<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->withoutVite();
    }

    #[Test]
    public function login_form_keeps_the_existing_route_names_and_csrf_protection(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('action="'.route('login.attempt').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false);

        Http::assertNothingSent();
    }

    #[Test]
    public function invalid_login_is_rejected_by_laravel_without_calling_quarkus(): void
    {
        $this->from('/login')->post('/login', ['email' => 'no-es-correo', 'password' => ''])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email', 'password']);

        Http::assertNothingSent();
    }

    #[Test]
    public function protected_routes_require_a_laravel_identity(): void
    {
        foreach (['/paciente/inicio', '/secretaria/inicio', '/medico/inicio', '/administracion/inicio'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function forged_legacy_quarkus_session_is_not_accepted(): void
    {
        $this->withSession([
            'auth_token' => 'token-opaco-no-transferible',
            'cod_usu' => 'USU000001',
            'cod_rol' => 'ROL004',
        ])->get('/administracion/inicio')->assertRedirect('/login');

        Http::assertNothingSent();
    }

    #[Test]
    public function logout_is_idempotent_and_invalidates_the_local_session(): void
    {
        $this->withSession(['dato_temporal' => 'sensible'])->post('/logout')
            ->assertRedirect('/')
            ->assertSessionMissing('dato_temporal');

        Http::assertNothingSent();
    }

    #[Test]
    public function session_cookie_policy_is_configured_server_side(): void
    {
        $this->assertTrue((bool) config('session.http_only'));
        $this->assertContains(config('session.same_site'), ['lax', 'strict']);
        $this->assertSame(120, (int) config('session.lifetime'));
    }
}
