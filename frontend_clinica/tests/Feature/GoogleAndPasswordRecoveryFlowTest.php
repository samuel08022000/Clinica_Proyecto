<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
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
        config(['clinic.features.google_oauth' => true]);

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
