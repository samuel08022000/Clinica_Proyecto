<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientPanelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    public function test_guest_is_redirected_to_login_without_contacting_quarkus(): void
    {
        Http::fake();

        $this->get(route('paciente.home'))->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    public function test_patient_keeps_classic_layout_identity_navigation_and_future_state(): void
    {
        Http::fake(['*/auth/authorize*' => Http::response($this->identity(), 200)]);

        $this->withSession($this->sessionData())
            ->get('/paciente/inicio')
            ->assertOk()
            ->assertViewIs('dashboard.home')
            ->assertSee('Inicio de Paciente')
            ->assertSee('Cuenta conectada')
            ->assertSee('Paciente de QA')
            ->assertSee('paciente@example.test')
            ->assertSee('ROL001')
            ->assertSee('Cuenta activo')
            ->assertSee('Los módulos operativos de este perfil se implementarán en las siguientes fases.')
            ->assertSee('href="'.route('home').'"', false)
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee('Cita confirmada')
            ->assertDontSee('Pago realizado')
            ->assertDontSee('Recordatorio enviado')
            ->assertDontSee('auth_token');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/auth/authorize?roles=ROL001')
            && $request->hasHeader('Authorization'));
    }

    public static function otherRoles(): array
    {
        return [['ROL002'], ['ROL003'], ['ROL004']];
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_open_the_patient_panel(string $role): void
    {
        Http::fake(['*/auth/authorize*' => Http::response(['error' => ['code' => 'FORBIDDEN']], 403)]);

        $this->withSession($this->sessionData($role))->get('/paciente/inicio')->assertForbidden();
    }

    public function test_local_patient_role_cannot_override_a_different_remotely_validated_role(): void
    {
        $identity = $this->identity();
        $identity['data']['user']['role'] = ['code' => 'ROL004', 'name' => 'Administrador', 'slug' => 'administracion'];
        Http::fake(['*/auth/authorize*' => Http::response($identity, 200)]);

        $this->withSession($this->sessionData())->get('/paciente/inicio')->assertForbidden();
    }

    public function test_revoked_patient_session_is_removed(): void
    {
        Http::fake(['*/auth/authorize*' => Http::response(['error' => ['code' => 'INVALID_SESSION']], 401)]);

        $this->withSession($this->sessionData())->get('/paciente/inicio')
            ->assertRedirect(route('login'))->assertSessionMissing('auth_token');
    }

    public function test_unavailable_authorizer_does_not_expose_a_successful_patient_panel(): void
    {
        Http::fake(fn () => throw new ConnectionException('Servicio no disponible'));

        $this->withSession($this->sessionData())->get('/paciente/inicio')
            ->assertStatus(503)->assertSessionHas('cod_rol', 'ROL001')->assertDontSee('Cuenta conectada');
    }

    private function sessionData(string $role = 'ROL001'): array
    {
        return ['auth_token' => 'patient-unit-session', 'cod_usu' => 'USU000001', 'cod_rol' => $role];
    }

    private function identity(): array
    {
        return ['data' => [
            'user' => [
                'id' => 'USU000001', 'name' => 'Paciente de QA', 'email' => 'paciente@example.test',
                'role' => ['code' => 'ROL001', 'name' => 'Paciente', 'slug' => 'paciente'], 'accountStatus' => 'Activo',
            ],
            'expiresAt' => '2099-01-01T00:00:00Z',
        ]];
    }
}
