<?php

namespace App\Services\Auth;

use App\Contracts\GoogleIdentityProvider;
use App\Exceptions\AuthDomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proveedor real de Google (OpenID Connect, flujo de código con PKCE).
 *
 * - authorizationUrl(): arma la URL de Google con state, nonce y code_challenge (S256).
 * - exchange(): canjea el código en el endpoint de tokens y valida el ID token:
 *   firma RS256 contra las claves públicas de Google (JWKS), vencimiento, emisor,
 *   audiencia (nuestro client_id) y nonce. Solo entonces devuelve la identidad.
 *
 * Los tokens de Google nunca se guardan ni se envían al navegador.
 */
final class GoogleOidcIdentityProvider implements GoogleIdentityProvider
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const JWKS_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/certs';

    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    private const JWKS_CACHE_KEY = 'google_oidc_jwks';

    private const JWKS_TTL_SECONDS = 3600;

    public function available(): bool
    {
        $id = $this->clientId();

        return class_exists(JWT::class)
            && $id !== ''
            && str_ends_with($id, '.apps.googleusercontent.com')
            && $this->clientSecret() !== '';
    }

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge, string $redirectUri): string
    {
        $this->requireConfigured();

        return self::AUTH_ENDPOINT.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(string $code, string $codeVerifier, string $expectedNonce, string $redirectUri): array
    {
        $this->requireConfigured();

        $idToken = $this->requestIdToken($code, $codeVerifier, $redirectUri);
        $claims = $this->verifiedClaims($idToken);

        $issuer = $claims->iss ?? null;
        $audience = $claims->aud ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        $nonce = $claims->nonce ?? null;

        if (! in_array($issuer, self::ISSUERS, true)
            || ! in_array($this->clientId(), $audiences, true)
            || (isset($claims->azp) && $claims->azp !== $this->clientId())
            || (count($audiences) > 1 && ($claims->azp ?? null) !== $this->clientId())
            || ! is_int($claims->exp ?? null)
            || ! is_int($claims->iat ?? null)
            || ! is_string($claims->sub ?? null)
            || $claims->sub === ''
            || ! is_string($nonce)
            || $expectedNonce === ''
            || ! hash_equals($expectedNonce, $nonce)) {
            Log::warning('Google OIDC: el ID token no cumple emisor, audiencia o nonce.');
            throw $this->invalidToken();
        }

        if (! is_string($claims->email ?? null)) {
            throw $this->invalidToken();
        }
        $email = mb_strtolower(trim($claims->email));
        $nameClaim = $claims->name ?? $claims->given_name ?? '';
        $name = is_string($nameClaim) ? trim($nameClaim) : '';
        if ($name === '' && $email !== '') {
            $name = (string) strstr($email, '@', true);
        }

        return [
            'subject' => (string) ($claims->sub ?? ''),
            'email' => $email,
            'name' => $name,
            'emailVerified' => filter_var($claims->email_verified ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function requestIdToken(string $code, string $codeVerifier, string $redirectUri): string
    {
        try {
            $response = Http::asForm()
                ->connectTimeout(4)
                ->timeout(10)
                ->post(self::TOKEN_ENDPOINT, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'redirect_uri' => $redirectUri,
                    'code_verifier' => $codeVerifier,
                ]);
        } catch (ConnectionException $e) {
            preg_match('/cURL error (\d+):/', $e->getMessage(), $curlError);
            Log::warning('Google OIDC: no se pudo contactar el endpoint de tokens.', [
                'error' => $e::class, 'curl_errno' => (int) ($curlError[1] ?? 0),
            ]);
            throw new AuthDomainException('GOOGLE_CONNECTION_FAILED', 503, 'No fue posible contactar a Google.');
        }

        if (! $response->successful()) {
            // Solo el código de error de Google (p. ej. invalid_grant, redirect_uri_mismatch). Nunca el cuerpo completo.
            Log::warning('Google OIDC: el canje del código fue rechazado.', [
                'status' => $response->status(),
                'google_error' => in_array($response->json('error'), ['invalid_grant', 'invalid_client', 'redirect_uri_mismatch', 'invalid_request', 'unauthorized_client'], true)
                    ? $response->json('error') : 'unknown_error',
            ]);
            throw new AuthDomainException('GOOGLE_AUTHENTICATION_FAILED', 401, 'Google rechazó el código de autorización.');
        }

        $idToken = $response->json('id_token');
        if (! is_string($idToken) || $idToken === '') {
            throw $this->invalidToken();
        }

        return $idToken;
    }

    /** Verifica firma y vencimiento con las claves públicas de Google. */
    private function verifiedClaims(string $idToken): object
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = 30;

        try {
            // Primero con las claves en caché; si Google rotó la clave (kid nuevo), se reintenta con las recién descargadas.
            foreach ([true, false] as $useCache) {
                try {
                    return JWT::decode($idToken, JWK::parseKeySet($this->jwks($useCache), 'RS256'));
                } catch (\Throwable $e) {
                    if (! $useCache) {
                        Log::warning('Google OIDC: la validación del ID token falló.', ['error' => $e::class]);
                    }
                }
            }
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        throw $this->invalidToken();
    }

    private function jwks(bool $useCache): array
    {
        if ($useCache) {
            $cached = Cache::get(self::JWKS_CACHE_KEY);
            if (is_array($cached) && isset($cached['keys'])) {
                return $cached;
            }
        }

        $jwks = Http::connectTimeout(4)->timeout(8)->get(self::JWKS_ENDPOINT)->throw()->json();
        if (! is_array($jwks) || ! isset($jwks['keys'])) {
            throw new \UnexpectedValueException('JWKS de Google con formato inesperado.');
        }
        Cache::put(self::JWKS_CACHE_KEY, $jwks, self::JWKS_TTL_SECONDS);

        return $jwks;
    }

    private function requireConfigured(): void
    {
        if (! class_exists(JWT::class)) {
            throw new AuthDomainException('GOOGLE_OIDC_LIBRARY_MISSING', 503, 'Falta instalar firebase/php-jwt.');
        }
        if (! $this->available()) {
            throw new AuthDomainException('GOOGLE_OAUTH_NOT_CONFIGURED', 503, 'Google OAuth no está configurado.');
        }
    }

    private function invalidToken(): AuthDomainException
    {
        return new AuthDomainException('GOOGLE_ID_TOKEN_INVALID', 401, 'No fue posible validar la identidad Google.');
    }

    private function clientId(): string
    {
        return trim((string) config('clinic.features.google_client_id'));
    }

    private function clientSecret(): string
    {
        return trim((string) config('clinic.features.google_client_secret'));
    }
}
