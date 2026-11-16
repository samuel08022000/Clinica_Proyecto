<?php

namespace App\Services;

use App\Exceptions\QuarkusApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class QuarkusApiClient
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.quarkus.base_url'), '/');
    }

    protected function client(bool $authenticated = true): PendingRequest
    {
        $client = Http::acceptJson()
            ->contentType('application/json')
            ->timeout((int) config('services.quarkus.timeout', 8))
            ->connectTimeout((int) config('services.quarkus.connect_timeout', 3));

        $token = $authenticated ? session('auth_token') : null;

        return filled($token) ? $client->withToken((string) $token) : $client;
    }

    public function get(string $endpoint, array $query = []): Response
    {
        return $this->client()->get(
            $this->url($endpoint),
            array_map(
                fn (mixed $value): mixed => is_bool($value) ? ($value ? 'true' : 'false') : $value,
                array_filter($query, fn (mixed $value): bool => $value !== null && $value !== '')
            )
        );
    }

    public function post(string $endpoint, array $data = []): Response
    {
        return $this->client()->post($this->url($endpoint), $data);
    }

    public function put(string $endpoint, array $data = []): Response
    {
        return $this->client()->put($this->url($endpoint), $data);
    }

    public function patch(string $endpoint, array $data = []): Response
    {
        return $this->client()->patch($this->url($endpoint), $data);
    }

    /** Administrative contracts return JSON directly; a password reset returns 204. */
    public function administrative(string $method, string $endpoint, array $data = []): array
    {
        try {
            $response = match ($method) {
                'GET' => $this->get($endpoint, $data),
                'POST' => $data === []
                    ? $this->client()->withBody('{}', 'application/json')->post($this->url($endpoint))
                    : $this->post($endpoint, $data),
                'PUT' => $this->put($endpoint, $data),
                default => throw new \InvalidArgumentException('Método administrativo no permitido.'),
            };
        } catch (ConnectionException) {
            throw new QuarkusApiException(503, 'El servicio de gestión no está disponible. Reintenta en unos momentos.');
        }

        if (! $response->successful()) {
            $status = $response->status();
            $message = match ($status) {
                401 => 'Tu sesión expiró o fue revocada. Inicia sesión nuevamente.',
                403 => 'No tienes permiso para realizar esta operación.',
                404 => 'No se encontró el registro solicitado.',
                423 => 'La cuenta está bloqueada. Consulta con el responsable de la clínica.',
                default => $status >= 500
                    ? 'El servicio de gestión no pudo completar la solicitud. Reintenta en unos momentos.'
                    : 'La solicitud fue rechazada. Revisa los datos e inténtalo nuevamente.',
            };
            if (in_array($status, [400, 409, 422], true)) {
                $detail = $response->json('mensaje') ?? $response->json('error.message');
                if (is_string($detail) && mb_strlen($detail) <= 2000) {
                    $message = $detail;
                }
            }
            // Never propagate a credential reflected by an upstream error into a flash message.
            foreach ([$data['password'] ?? null, $data['tokenQr'] ?? null, session('auth_token')] as $secret) {
                if (is_string($secret) && $secret !== '') {
                    $message = str_replace($secret, '[dato protegido]', $message);
                }
            }
            throw new QuarkusApiException($status >= 500 ? 503 : $status, $message);
        }

        if ($response->noContent()) {
            return [];
        }
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new QuarkusApiException(502, 'La respuesta del servicio no pudo interpretarse. Reintenta la consulta.');
        }

        return $payload;
    }

    public function login(string $email, string $password): Response
    {
        return $this->client(authenticated: false)->post(
            $this->url(config('clinic.endpoints.login') ?: 'auth/login'),
            ['email' => $email, 'password' => $password]
        );
    }

    public function registerPatient(array $data): Response
    {
        return $this->client(authenticated: false)
            ->timeout((int) config('services.quarkus.registration_timeout', 30))->post(
                $this->url(config('clinic.endpoints.register') ?: 'auth/register'),
                $data
            );
    }

    public function googleAuthorizationUrl(array $data): Response
    {
        return $this->client(authenticated: false)->post(
            $this->url(config('clinic.endpoints.google_authorization') ?: 'auth/google/authorization-url'),
            $data
        );
    }

    public function exchangeGoogleCode(array $data): Response
    {
        return $this->client(authenticated: false)->post(
            $this->url(config('clinic.endpoints.google_exchange') ?: 'auth/google/exchange'),
            $data
        );
    }

    public function registerGooglePatient(array $data): Response
    {
        return $this->client(authenticated: false)->post(
            $this->url(config('clinic.endpoints.google_register') ?: 'auth/google/register'),
            $data
        );
    }

    public function linkGoogleAccount(string $continuationToken): Response
    {
        return $this->client()->post(
            $this->url(config('clinic.endpoints.google_link') ?: 'auth/google/link'),
            ['continuationToken' => $continuationToken]
        );
    }

    public function requestPasswordReset(string $email): Response
    {
        return $this->client(authenticated: false)->post(
            $this->url(config('clinic.endpoints.password_forgot') ?: 'auth/password/forgot'),
            ['email' => $email]
        );
    }

    public function resetPassword(string $email, string $token, string $password): Response
    {
        return $this->client(authenticated: false)->post(
            $this->url(config('clinic.endpoints.password_reset') ?: 'auth/password/reset'),
            ['email' => $email, 'token' => $token, 'password' => $password]
        );
    }

    public function requestOtp(string $email, string $password): Response
    {
        return $this->client(authenticated: false)->post(
            $this->url(config('clinic.endpoints.request_otp') ?: 'auth/otp/request'),
            ['email' => $email, 'password' => $password]
        );
    }

    public function verifyOtp(string $verificationToken, string $code): Response
    {
        return $this->client(authenticated: false)->post(
            $this->url(config('clinic.endpoints.verify_otp') ?: 'auth/otp/verify'),
            ['verificationToken' => $verificationToken, 'code' => $code]
        );
    }

    public function resendOtp(string $verificationToken): Response
    {
        return $this->client(authenticated: false)->post(
            $this->url(config('clinic.endpoints.resend_otp') ?: 'auth/otp/resend'),
            ['verificationToken' => $verificationToken]
        );
    }

    public function otpStatus(string $verificationToken): Response
    {
        return $this->client(authenticated: false)->post(
            $this->url(config('clinic.endpoints.otp_status') ?: 'auth/otp/status'),
            ['verificationToken' => $verificationToken]
        );
    }

    public function authenticatedUser(): Response
    {
        return $this->client()->get($this->url(config('clinic.endpoints.me') ?: 'auth/me'));
    }

    public function authorizeRoles(array $roles): Response
    {
        return $this->client()->get(
            $this->url(config('clinic.endpoints.authorize') ?: 'auth/authorize'),
            ['roles' => implode(',', $roles)]
        );
    }

    public function logout(): Response
    {
        return $this->client()->post($this->url(config('clinic.endpoints.logout') ?: 'auth/logout'));
    }

    protected function url(string $endpoint): string
    {
        return "{$this->baseUrl}/".ltrim($endpoint, '/');
    }
}
