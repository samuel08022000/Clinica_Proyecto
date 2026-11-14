<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAndPasswordRecoveryFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('clinic.features.google_oauth', true);
        config()->set('clinic.features.google_redirect_uri', 'http://127.0.0.1:8000/auth/google/callback');
    }

    public function test_google_login_uses_state_nonce_pkce_and_creates_only_internal_session(): void
    {
        Http::fake([
            '*/auth/google/authorization-url' => Http::response([
                'data' => ['authorizationUrl' => 'https://accounts.google.com/o/oauth2/v2/auth?test=1'],
            ]),
        ]);

        $this->get(route('google.login'))
            ->assertRedirect('https://accounts.google.com/o/oauth2/v2/auth?test=1');

        $flow = session('google_oauth_flow');
        $this->assertIsArray($flow);
        $this->assertNotEmpty($flow['state']);
        $this->assertNotEmpty($flow['nonce']);
        $this->assertGreaterThanOrEqual(43, strlen($flow['code_verifier']));

        Http::fake([
            '*/auth/google/exchange' => Http::response(['data' => [
                'status' => 'AUTHENTICATED',
                'login' => $this->loginData(),
            ]]),
        ]);

        $this->get(route('google.callback', ['state' => $flow['state'], 'code' => 'authorization-code']))
            ->assertRedirect(route('paciente.home'))
            ->assertSessionHas('auth_token', 'opaque-quarkus-token')
            ->assertSessionMissing('google_oauth_flow');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/auth/google/exchange')
            && $request['nonce'] === $flow['nonce']
            && $request['codeVerifier'] === $flow['code_verifier']
            && ! $request->hasHeader('Authorization'));
    }

    public function test_google_callback_rejects_wrong_state_before_contacting_quarkus(): void
    {
        Http::fake();
        $this->withSession(['google_oauth_flow' => [
            'state' => 'expected-state',
            'nonce' => 'nonce',
            'code_verifier' => str_repeat('a', 64),
            'expires_at' => time() + 300,
        ]])->get(route('google.callback', ['state' => 'attacker-state', 'code' => 'code']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('authentication')
            ->assertSessionMissing('google_oauth_flow');
        Http::assertNothingSent();
    }

    public function test_google_cancellation_is_handled_without_contacting_quarkus(): void
    {
        Http::fake();
        $this->withSession(['google_oauth_flow' => [
            'state' => 'safe-state',
            'nonce' => 'safe-nonce',
            'code_verifier' => str_repeat('a', 64),
            'expires_at' => time() + 300,
        ]])->get(route('google.callback', ['error' => 'access_denied', 'state' => 'safe-state']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('authentication')
            ->assertSessionMissing('google_oauth_flow');
        Http::assertNothingSent();
    }

    public function test_existing_email_requires_local_login_before_linking(): void
    {
        Http::fake([
            '*/auth/google/exchange' => Http::response(['data' => [
                'status' => 'LINK_REQUIRED',
                'continuationToken' => 'one-time-link-ticket',
                'email' => 'paciente@clinica.bo',
            ]]),
        ]);
        $this->withSession(['google_oauth_flow' => [
            'state' => 'safe-state',
            'nonce' => 'safe-nonce',
            'code_verifier' => str_repeat('a', 64),
            'expires_at' => time() + 300,
        ]])->get(route('google.callback', ['state' => 'safe-state', 'code' => 'code']))
            ->assertRedirect(route('login'))
            ->assertSessionHas('google_link_token', 'one-time-link-ticket')
            ->assertSessionMissing('auth_token');
    }

    public function test_password_recovery_is_generic_and_reset_never_auto_logs_in(): void
    {
        Http::fake([
            '*/auth/password/forgot' => Http::response(['data' => [
                'message' => 'Si la cuenta es elegible, recibirás instrucciones.',
                'deliveryMode' => 'DEMO',
                'deliveryConfirmed' => false,
            ]], 202),
            '*/auth/password/reset' => Http::response(['data' => [
                'reset' => true,
                'nextAction' => 'LOGIN',
            ]]),
        ]);

        $this->post(route('password.email'), ['email' => 'nadie@example.test'])
            ->assertSessionHas('success');

        $this->withSession(['auth_token' => 'old-token', 'cod_usu' => 'USU000001'])
            ->post(route('password.update'), [
                'email' => 'paciente@clinica.bo',
                'token' => str_repeat('x', 64),
                'password' => 'NuevaSegura123',
                'password_confirmation' => 'NuevaSegura123',
            ])->assertRedirect(route('login'))
            ->assertSessionMissing('auth_token')
            ->assertSessionMissing('cod_usu');
    }

    private function loginData(): array
    {
        return [
            'user' => [
                'id' => 'USU000010',
                'name' => 'Paciente Google',
                'email' => 'paciente@gmail.com',
                'role' => ['code' => 'ROL001', 'name' => 'Paciente', 'slug' => 'paciente'],
                'accountStatus' => 'Activo',
            ],
            'session' => [
                'accessToken' => 'opaque-quarkus-token',
                'tokenType' => 'Bearer',
                'expiresAt' => '2026-11-06T18:00:00Z',
            ],
        ];
    }
}
