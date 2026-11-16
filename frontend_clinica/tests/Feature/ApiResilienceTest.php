<?php

namespace Tests\Feature;

use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use App\Services\Auth\AccountStatusService;
use App\Services\Auth\PasswordRecoveryService;
use App\Services\Reception\ReceptionService;
use Illuminate\Database\QueryException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiResilienceTest extends TestCase
{
    public function test_authentication_rate_limits_are_scoped_to_each_operation(): void
    {
        $recovery = Mockery::mock(PasswordRecoveryService::class);
        $recovery->shouldReceive('request')->once()->andReturn(PasswordRecoveryService::GENERIC_MESSAGE);
        $this->app->instance(PasswordRecoveryService::class, $recovery);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('register.store'), [])->assertRedirect();
        }
        $this->post(route('register.store'), [])->assertStatus(429);

        $this->post(route('password.email'), ['email' => 'fiction@example.test'])
            ->assertRedirect()->assertSessionHas('success');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.attempt'), [])->assertRedirect();
        }
        $this->post(route('login.attempt'), [])->assertStatus(429);
    }

    private function secretary(): static
    {
        $user = new Usuario;
        $user->forceFill([
            'cod_usu' => 'USU000002',
            'cod_rol' => 'ROL002',
            'nombre_completo' => 'Ángela Secretaria',
            'email' => 'angela@example.test',
            'estado' => 'Activo',
        ]);

        return $this->actingAs($user, 'web')->withSession([
            'auth_authority' => 'laravel',
            'auth_expires_at' => now()->addMinutes(120)->toIso8601String(),
            'cod_usu' => 'USU000002',
            'cod_rol' => 'ROL002',
            'nombre_usuario' => 'Ángela Secretaria',
            'email_usuario' => 'angela@example.test',
            'nombre_rol' => 'Secretaria',
            'rol_slug' => 'secretaria',
            'estado_cuenta' => 'Activo',
        ]);
    }

    public function test_clinical_database_failure_during_authorization_denies_access_and_preserves_session(): void
    {
        $mock = Mockery::mock(AccountStatusService::class);
        $mock->shouldReceive('requireActive')->andThrow(
            new QueryException('clinical', 'SELECT 1', [], new \PDOException('SQLSTATE[08006] connection refused'))
        );
        $this->app->instance(AccountStatusService::class, $mock);

        $this->secretary()->get(route('secretaria.home'))
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertSessionHas('cod_usu', 'USU000002')
            ->assertDontSee('Panel de Secretaría');
    }

    public static function clinicalFailureStatuses(): array
    {
        return [[400], [403], [404], [409], [422], [423], [503]];
    }

    #[DataProvider('clinicalFailureStatuses')]
    public function test_reception_controller_handles_clinical_domain_failures_without_retrying(int $status): void
    {
        $accounts = Mockery::mock(AccountStatusService::class);
        $accounts->shouldReceive('requireActive')->andReturn((object) [
            'cod_usu' => 'USU000002',
            'nombre_completo' => 'Ángela Secretaria',
            'email' => 'angela@example.test',
            'cod_rol' => 'ROL002',
            'nombre_rol' => 'Secretaria',
            'usuario_estado' => 'Activo',
            'rol_estado' => 'Activo',
            'perfil_presente' => true,
            'perfil_estado' => 'Activo',
            'email_verificado' => true,
            'bloqueado_hasta' => null,
        ]);
        $this->app->instance(AccountStatusService::class, $accounts);

        $reception = Mockery::mock(ReceptionService::class);
        $reception->shouldReceive('request')->once()->andThrow(
            new ClinicalOperationException($status, 'Fallo controlado del servicio clínico', 'CLINICAL_ERR')
        );
        $this->app->instance(ReceptionService::class, $reception);

        $response = $this->secretary()
            ->from('/secretaria/citas/CIT00000001/cancelar')
            ->post('/secretaria/citas/CIT00000001/cancelar', ['motivo' => 'Motivo de prueba', 'confirmacion' => 1]);

        if (in_array($status, [403, 404, 423], true)) {
            $response->assertStatus($status)->assertSessionHas('cod_usu', 'USU000002');
        } else {
            $response->assertRedirect('/secretaria/citas/CIT00000001/cancelar')
                ->assertSessionHasErrors('api')
                ->assertSessionHas('api_status', $status)
                ->assertSessionHas('cod_usu', 'USU000002');
        }
    }
}
