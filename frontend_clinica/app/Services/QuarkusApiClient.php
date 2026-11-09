<?php

namespace App\Services;

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
            array_filter($query, fn (mixed $value): bool => $value !== null && $value !== '')
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

    public function login(string $email, string $password): Response
    {
        return $this->client(authenticated: false)->post(
            $this->url(config('clinic.endpoints.login') ?: 'auth/login'),
            ['email' => $email, 'password' => $password]
        );
    }

    public function registerPatient(array $data): Response
    {
        return $this->client(authenticated: false)->post(
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
