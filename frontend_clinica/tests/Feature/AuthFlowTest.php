<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public static function roles(): array
    {
        return [
            'paciente' => ['ROL001', 'Paciente', 'paciente', 'paciente.home'],
            'secretaria' => ['ROL002', 'Secretario', 'secretaria', 'secretaria.home'],
            'medico' => ['ROL003', 'Doctor', 'medico', 'medico.home'],
            'administracion' => ['ROL004', 'Administrador', 'administracion', 'administracion.home'],
        ];
    }

    #[DataProvider('roles')]
    public function test_successful_login_redirects_each_role(
        string $roleCode,
        string $roleName,
        string $roleSlug,
        string $routeName,
    ): void {
        Http::fake([
            '*/auth/login' => Http::response($this->loginPayload($roleCode, $roleName, $roleSlug), 200),
        ]);

        $this->post(route('login.attempt'), [
            'email' => 'usuario@clinica.bo',
            'password' => 'KnownPassword123',
        ])->assertRedirect(route($routeName))
            ->assertSessionHas('cod_usu', 'USU000001')
            ->assertSessionHas('cod_rol', $roleCode)
            ->assertSessionHas('auth_token', 'opaque-test-token');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://quarkus.test/api/v1/auth/login'
            && ! $request->hasHeader('Authorization')
            && $request['email'] === 'usuario@clinica.bo');
    }

    public function test_required_login_fields_are_validated_before_calling_quarkus(): void
    {
        Http::fake();

        $this->post(route('login.attempt'), [])
            ->assertRedirect()
            ->assertSessionHasErrors(['email', 'password']);

        Http::assertNothingSent();
    }

    public function test_invalid_credentials_are_reported_without_storing_a_token(): void
    {
        Http::fake([
            '*/auth/login' => Http::response([
                'error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'Mensaje del backend'],
            ], 401),
        ]);

        $this->from(route('login'))->post(route('login.attempt'), [
            'email' => 'usuario@clinica.bo',
            'password' => 'IncorrectPassword',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors('authentication')
            ->assertSessionMissing('auth_token');
    }

    public function test_pending_patient_is_redirected_to_email_verification_message(): void
    {
        Http::fake([
            '*/auth/login' => Http::response([
                'error' => ['code' => 'EMAIL_VERIFICATION_REQUIRED', 'message' => 'Backend'],
            ], 403),
        ]);

        $this->from(route('login'))->post(route('login.attempt'), [
            'email' => 'pendiente@clinica.bo',
            'password' => 'PacienteSegura123',
        ])->assertRedirect(route('verification.email'))
            ->assertSessionHasErrors('verification')
            ->assertSessionMissing('auth_token');
    }

    public function test_connection_failure_returns_a_recoverable_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('Quarkus no disponible'));

        $this->from(route('login'))->post(route('login.attempt'), [
            'email' => 'usuario@clinica.bo',
            'password' => 'KnownPassword123',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors('authentication')
            ->assertSessionHasInput('email', 'usuario@clinica.bo');
    }

    public function test_protected_route_requires_a_local_and_remote_session(): void
    {
        $this->get(route('paciente.home'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('authentication');

        Http::fake([
            '*/auth/authorize*' => Http::response($this->mePayload('ROL001', 'Paciente', 'paciente'), 200),
        ]);

        $this->withSession($this->sessionData('ROL001'))
            ->get(route('paciente.home'))
            ->assertOk()
            ->assertSee('Inicio de Paciente');

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://quarkus.test/api/v1/auth/authorize')
            && $request->hasHeader('Authorization', 'Bearer opaque-test-token')
            && $request['roles'] === 'ROL001');
    }

    public function test_user_cannot_open_another_roles_route(): void
    {
        Http::fake([
            '*/auth/authorize*' => Http::response([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Sin permiso'],
            ], 403),
        ]);

        $this->withSession($this->sessionData('ROL001'))
            ->get(route('administracion.home'))
            ->assertForbidden();
    }

    public function test_expired_remote_session_is_removed_locally(): void
    {
        Http::fake([
            '*/auth/authorize*' => Http::response([
                'error' => ['code' => 'INVALID_SESSION', 'message' => 'Expirada'],
            ], 401),
        ]);

        $this->withSession($this->sessionData('ROL001'))
            ->get(route('paciente.home'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('authentication')
            ->assertSessionMissing('auth_token');
    }

    public function test_logout_revokes_remote_token_and_invalidates_local_session(): void
    {
        Http::fake([
            '*/auth/logout' => Http::response(['data' => ['revoked' => true]], 200),
        ]);

        $this->withSession($this->sessionData('ROL001'))
            ->post(route('logout'))
            ->assertRedirect(route('home'))
            ->assertSessionMissing('auth_token')
            ->assertSessionMissing('cod_usu');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://quarkus.test/api/v1/auth/logout'
            && $request->hasHeader('Authorization', 'Bearer opaque-test-token'));
    }

    private function loginPayload(string $roleCode, string $roleName, string $roleSlug): array
    {
        return [
            'data' => [
                'user' => $this->userData($roleCode, $roleName, $roleSlug),
                'session' => [
                    'accessToken' => 'opaque-test-token',
                    'tokenType' => 'Bearer',
                    'expiresAt' => '2026-11-03T14:00:00Z',
                ],
            ],
        ];
    }

    private function mePayload(string $roleCode, string $roleName, string $roleSlug): array
    {
        return [
            'data' => [
                'user' => $this->userData($roleCode, $roleName, $roleSlug),
                'expiresAt' => '2026-11-03T14:00:00Z',
            ],
        ];
    }

    private function userData(string $roleCode, string $roleName, string $roleSlug): array
    {
        return [
            'id' => 'USU000001',
            'name' => 'Usuario de prueba',
            'email' => 'usuario@clinica.bo',
            'role' => ['code' => $roleCode, 'name' => $roleName, 'slug' => $roleSlug],
            'accountStatus' => 'Activo',
        ];
    }

    private function sessionData(string $roleCode): array
    {
        return [
            'auth_token' => 'opaque-test-token',
            'cod_usu' => 'USU000001',
            'cod_rol' => $roleCode,
        ];
    }
}
