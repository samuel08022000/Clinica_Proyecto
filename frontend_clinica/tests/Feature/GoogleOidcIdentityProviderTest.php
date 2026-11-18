<?php

namespace Tests\Feature;

use App\Exceptions\AuthDomainException;
use App\Services\Auth\GoogleOidcIdentityProvider;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GoogleOidcIdentityProviderTest extends TestCase
{
    private static ?OpenSSLAsymmetricKey $signingKey = null;

    private array $jwks;

    protected function setUp(): void
    {
        parent::setUp();
        config(['clinic.features.google_client_id' => 'test-client.apps.googleusercontent.com',
            'clinic.features.google_client_secret' => 'test-only-secret']);
        Http::preventStrayRequests();
        Cache::flush();
        if (self::$signingKey === null) {
            $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            $windowsConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
            if (is_file($windowsConfig)) {
                $options['config'] = $windowsConfig;
            }
            self::$signingKey = openssl_pkey_new($options);
        }
        $details = openssl_pkey_get_details(self::$signingKey);
        $this->jwks = ['keys' => [['kty' => 'RSA', 'kid' => 'test-key', 'alg' => 'RS256', 'use' => 'sig',
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'e' => JWT::urlsafeB64Encode($details['rsa']['e'])]]];
    }

    #[Test]
    public function validates_a_signed_identity_and_exchanges_only_with_google_using_pkce(): void
    {
        $this->fakeGoogle($this->claims());
        $identity = app(GoogleOidcIdentityProvider::class)->exchange('test-code', 'test-verifier', 'test-nonce', route('google.callback'));
        $this->assertSame(['subject' => 'test-subject', 'email' => 'paciente@example.invalid',
            'name' => 'Paciente Google', 'emailVerified' => true], $identity);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['code'] === 'test-code' && $request['code_verifier'] === 'test-verifier'
            && $request['redirect_uri'] === route('google.callback') && $request['client_secret'] === 'test-only-secret');
        Http::assertSentCount(2);
    }

    #[Test]
    #[DataProvider('invalidClaims')]
    public function rejects_invalid_signed_claims(string $claim, mixed $value): void
    {
        $claims = $this->claims();
        if ($value === null) {
            unset($claims[$claim]);
        } else {
            $claims[$claim] = $value;
        }
        $this->fakeGoogle($claims);
        $this->expectException(AuthDomainException::class);
        app(GoogleOidcIdentityProvider::class)->exchange('code', 'verifier', 'test-nonce', route('google.callback'));
    }

    public static function invalidClaims(): array
    {
        return [
            'wrong audience' => ['aud', 'other-client'],
            'wrong issuer' => ['iss', 'https://untrusted.example.invalid'],
            'wrong nonce' => ['nonce', 'other-nonce'],
            'malformed nonce' => ['nonce', ['test-nonce']],
            'expired token' => ['exp', 1],
            'missing expiration' => ['exp', null],
            'missing issued at' => ['iat', null],
            'missing subject' => ['sub', null],
            'wrong authorized party' => ['azp', 'other-client'],
            'multiple audiences without authorized party' => ['aud', ['test-client.apps.googleusercontent.com', 'other-client']],
        ];
    }

    #[Test]
    public function rejects_an_invalid_signature(): void
    {
        $token = JWT::encode($this->claims(), self::$signingKey, 'RS256', 'test-key');
        $parts = explode('.', $token);
        $parts[2] = JWT::urlsafeB64Encode(random_bytes(256));
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['id_token' => implode('.', $parts)]),
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response($this->jwks)]);
        $this->expectException(AuthDomainException::class);
        app(GoogleOidcIdentityProvider::class)->exchange('code', 'verifier', 'test-nonce', route('google.callback'));
    }

    #[Test]
    public function refreshes_cached_keys_when_google_rotates_the_signing_key(): void
    {
        Cache::put('google_oidc_jwks', ['keys' => []], 3600);
        $this->fakeGoogle($this->claims());
        $identity = app(GoogleOidcIdentityProvider::class)->exchange('code', 'verifier', 'test-nonce', route('google.callback'));
        $this->assertSame('test-subject', $identity['subject']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function rejects_a_rejected_authorization_code_without_exposing_the_response(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->expectException(AuthDomainException::class);
        app(GoogleOidcIdentityProvider::class)->exchange('code', 'verifier', 'test-nonce', route('google.callback'));
    }

    private function claims(): array
    {
        return ['iss' => 'https://accounts.google.com', 'aud' => 'test-client.apps.googleusercontent.com',
            'sub' => 'test-subject', 'iat' => time(), 'exp' => time() + 300, 'nonce' => 'test-nonce',
            'email' => 'Paciente@example.invalid', 'name' => 'Paciente Google', 'email_verified' => true];
    }

    private function fakeGoogle(array $claims): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
            'id_token' => JWT::encode($claims, self::$signingKey, 'RS256', 'test-key'),
        ]), 'https://www.googleapis.com/oauth2/v3/certs' => Http::response($this->jwks)]);
    }
}
