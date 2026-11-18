<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GoogleAndPasswordRecoveryFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->withoutVite();
    }

    #[Test]
    public function google_is_fail_closed_when_no_verified_oidc_provider_is_installed(): void
    {
        config(['clinic.features.google_oauth' => true,
            'clinic.features.google_client_id' => null,
            'clinic.features.google_client_secret' => null]);

        $this->get('/auth/google')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('authentication');

        Http::assertNothingSent();
    }

    #[Test]
    public function callback_without_a_valid_state_is_rejected_before_token_exchange(): void
    {
        $this->get('/auth/google/callback?state=falso&code=falso')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('authentication');

        Http::assertNothingSent();
    }

    #[Test]
    public function disabled_google_cannot_be_started_by_opening_either_route_directly(): void
    {
        config(['clinic.features.google_oauth' => false,
            'clinic.features.google_client_id' => 'test-client.apps.googleusercontent.com',
            'clinic.features.google_client_secret' => 'test-only-secret']);

        $this->get('/auth/google')->assertRedirect('/login')->assertSessionHasErrors('authentication');
        $this->get('/registro/google')->assertRedirect('/registro')->assertSessionHasErrors('authentication');
        $this->assertFalse(session()->has('google_oauth_flows'));
        Http::assertNothingSent();
    }

    #[Test]
    public function configured_google_enables_both_buttons_and_starts_pkce_in_laravel(): void
    {
        config(['clinic.features.google_oauth' => true,
            'clinic.features.google_client_id' => 'test-client.apps.googleusercontent.com',
            'clinic.features.google_client_secret' => 'test-only-secret',
            'clinic.features.google_redirect_uri' => route('google.callback')]);

        $this->get('/login')->assertOk()->assertSee('href="'.route('google.login').'"', false);
        $this->get('/registro')->assertOk()->assertSee('href="'.route('google.register').'"', false);
        foreach (['/auth/google' => 'login', '/registro/google' => 'register'] as $uri => $intent) {
            $response = $this->get($uri)->assertRedirect();
            $flow = collect(session('google_oauth_flows'))->last();
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->assertSame('accounts.google.com', parse_url($response->headers->get('Location'), PHP_URL_HOST));
            $this->assertSame($intent, $flow['intent']);
            $this->assertSame($flow['state'], $query['state']);
            $this->assertSame($flow['nonce'], $query['nonce']);
            $this->assertSame('S256', $query['code_challenge_method']);
            $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $flow['verifier'], true)), '+/', '-_'), '='), $query['code_challenge']);
            $this->assertSame(route('google.callback'), $query['redirect_uri']);
            $this->assertArrayNotHasKey('client_secret', $query);
        }
        Http::assertNothingSent();
    }

    #[Test]
    public function invalid_callbacks_do_not_consume_pending_flows_and_cancellation_is_single_use(): void
    {
        $this->configureGoogle();
        $this->get('/auth/google')->assertRedirect();
        $first = collect(session('google_oauth_flows'))->last();
        $this->get('/registro/google')->assertRedirect();
        $second = collect(session('google_oauth_flows'))->last();
        foreach (['state=incorrecto&code=x', 'state[]=invalid&code=x', 'error=access_denied'] as $query) {
            $this->get('/auth/google/callback?'.$query)->assertRedirect('/login')->assertSessionHasErrors('authentication');
            $this->assertCount(2, session('google_oauth_flows'));
        }
        $this->get('/auth/google/callback?state='.$first['state'].'&error=access_denied')->assertRedirect('/login');
        $this->assertArrayNotHasKey($first['state'], session('google_oauth_flows'));
        $this->assertArrayHasKey($second['state'], session('google_oauth_flows'));
        $this->get('/auth/google/callback?state='.$first['state'].'&code=replay')->assertRedirect('/login');
        Http::assertNothingSent();
    }

    #[Test]
    public function expired_flows_and_malformed_codes_are_rejected_and_pending_flows_are_bounded(): void
    {
        $this->configureGoogle();
        $this->get('/auth/google');
        $expired = collect(session('google_oauth_flows'))->last();
        $expired['expires_at'] = time() - 1;
        $this->withSession(['google_oauth_flows' => [$expired['state'] => $expired]])
            ->get('/auth/google/callback?state='.$expired['state'].'&code=x')
            ->assertRedirect('/login')->assertSessionHasErrors('authentication');
        $this->get('/auth/google');
        $this->assertArrayNotHasKey($expired['state'], session('google_oauth_flows'));
        $flow = collect(session('google_oauth_flows'))->last();
        $this->get('/auth/google/callback?state='.$flow['state'].'&code[]=invalid')
            ->assertRedirect('/login')->assertSessionHasErrors('authentication');
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->get('/auth/google')->assertRedirect();
        }
        $this->assertCount(5, session('google_oauth_flows'));
        Http::assertNothingSent();
    }

    #[Test]
    public function token_exchange_uses_the_original_callback_and_cannot_be_replayed_after_failure(): void
    {
        $this->configureGoogle();
        $this->get('/registro/google');
        $flow = collect(session('google_oauth_flows'))->last();
        config(['clinic.features.google_redirect_uri' => 'https://changed.example.invalid/auth/google/callback']);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->get('/auth/google/callback?state='.$flow['state'].'&code=test-code')->assertRedirect('/login')->assertSessionHasErrors('authentication');
        Http::assertSent(fn ($request) => $request['redirect_uri'] === $flow['redirect_uri']
            && $request['code_verifier'] === $flow['verifier']);
        $this->get('/auth/google/callback?state='.$flow['state'].'&code=test-code')->assertRedirect('/login');
        Http::assertSentCount(1);
    }

    #[Test]
    public function tls_failure_is_identified_without_logging_secrets_and_a_new_attempt_can_start(): void
    {
        $this->configureGoogle();
        Log::spy();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::failedConnection('cURL error 60: certificate verification failed')]);
        $this->get('/auth/google');
        $flow = collect(session('google_oauth_flows'))->last();
        $this->get('/auth/google/callback?state='.$flow['state'].'&code=private-code')
            ->assertRedirect('/login')->assertSessionHasErrors(['authentication' => 'No fue posible establecer una conexión segura con Google. Intenta nuevamente más tarde.']);
        Log::shouldHaveReceived('warning')->once()->with('Google OIDC: no se pudo contactar el endpoint de tokens.',
            ['error' => ConnectionException::class, 'curl_errno' => 60]);
        $this->get('/auth/google')->assertRedirect();
        $this->assertNotSame($flow['state'], collect(session('google_oauth_flows'))->last()['state']);
    }

    #[Test]
    public function oauth_starts_on_the_callback_host_before_creating_session_state(): void
    {
        $this->configureGoogle();
        config(['clinic.features.google_redirect_uri' => 'http://127.0.0.1:8000/auth/google/callback']);
        $this->get('http://localhost:8000/registro/google')->assertRedirect('http://127.0.0.1:8000/registro/google');
        $this->assertFalse(session()->has('google_oauth_flows'));
        Http::assertNothingSent();
    }

    #[Test]
    public function unavailable_google_buttons_remain_visible_but_disabled(): void
    {
        config(['clinic.features.google_oauth' => true, 'clinic.features.google_client_secret' => '']);
        $this->get('/login')->assertOk()->assertSee('Continuar con Google')->assertSee('aria-disabled="true"', false);
        $this->get('/registro')->assertOk()->assertSee('Registrarse con Google')->assertSee('aria-disabled="true"', false);
    }

    private function configureGoogle(): void
    {
        config(['clinic.features.google_oauth' => true,
            'clinic.features.google_client_id' => 'test-client.apps.googleusercontent.com',
            'clinic.features.google_client_secret' => 'test-only-secret',
            'clinic.features.google_redirect_uri' => route('google.callback')]);
    }

    #[Test]
    public function recovery_form_preserves_routes_fields_and_csrf(): void
    {
        $this->get('/recuperar-contrasena')->assertOk()
            ->assertSee('action="'.route('password.email').'"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="_token"', false);

        $this->get('/restablecer-contrasena/token-opaco?email=persona%40example.invalid')->assertOk()
            ->assertSee('action="'.route('password.update').'"', false)
            ->assertSee('name="token"', false)
            ->assertSee('name="password_confirmation"', false);

        Http::assertNothingSent();
    }

    #[Test]
    public function recovery_requests_use_laravel_validation_and_never_call_quarkus(): void
    {
        $this->from('/recuperar-contrasena')->post('/recuperar-contrasena', ['email' => 'invalido'])
            ->assertRedirect('/recuperar-contrasena')
            ->assertSessionHasErrors('email');

        $this->from('/restablecer-contrasena')->post('/restablecer-contrasena', [
            'email' => 'persona@example.invalid',
            'token' => '',
            'password' => 'debil',
            'password_confirmation' => 'distinta',
        ])->assertRedirect('/restablecer-contrasena')->assertSessionHasErrors(['token', 'password']);

        Http::assertNothingSent();
    }
}
