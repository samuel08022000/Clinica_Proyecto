<?php

namespace Tests\Feature;

use App\Exceptions\AuthDomainException;
use App\Http\Requests\Paciente\CancelAppointmentRequest;
use App\Http\Requests\Paciente\RescheduleAppointmentRequest;
use App\Http\Requests\Paciente\ReserveRequest;
use App\Models\Usuario;
use App\Services\Administration\AdministrationService;
use App\Services\Auth\AccountStatusService;
use App\Services\Auth\LoginAttemptService;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Pruebas de regresión de los hallazgos confirmados en la auditoría de seguridad (Fase 6). */
final class SecurityAuditRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->withoutVite();
    }

    #[Test]
    public function admin_patient_search_rejects_array_query_parameter_with_validation_error_instead_of_500(): void
    {
        $accounts = Mockery::mock(AccountStatusService::class);
        $accounts->shouldReceive('requireActive')->andReturn((object) [
            'cod_usu' => 'USU000099',
            'nombre_completo' => 'María Administradora',
            'email' => 'maria@example.test',
            'cod_rol' => 'ROL004',
            'nombre_rol' => 'Administrador',
            'usuario_estado' => 'Activo',
            'rol_estado' => 'Activo',
            'perfil_presente' => true,
            'perfil_estado' => 'Activo',
            'email_verificado' => true,
            'bloqueado_hasta' => null,
        ]);
        $this->app->instance(AccountStatusService::class, $accounts);

        $adminService = Mockery::mock(AdministrationService::class);
        $adminService->shouldNotReceive('request');
        $this->app->instance(AdministrationService::class, $adminService);

        $admin = new Usuario;
        $admin->cod_usu = 'USU000099';
        $admin->cod_rol = 'ROL004';
        $admin->estado = 'Activo';
        $this->actingAs($admin)
            ->withSession([
                'auth_authority' => 'laravel',
                'cod_usu' => $admin->cod_usu,
                'cod_rol' => 'ROL004',
                'auth_expires_at' => now()->addHour()->toIso8601String(),
            ])
            ->from('/administracion/inicio')
            ->get('/administracion/pacientes?q[]=abc')
            ->assertRedirect('/administracion/inicio')
            ->assertSessionHasErrors('q');

        Http::assertNothingSent();
    }

    #[Test]
    public function otp_verification_rejects_nested_array_code_digits_with_validation_error_instead_of_500(): void
    {
        $this->from('/verificar-correo')
            ->post('/verificar-correo', [
                'code_digits' => [['1', '2'], '3', '4', '5', '6'],
            ])
            ->assertRedirect('/verificar-correo')
            ->assertSessionHasErrors('code');

        Http::assertNothingSent();
    }

    #[Test]
    public function login_attempt_service_limits_distributed_ip_attacks_against_the_same_email(): void
    {
        $limiter = app(LoginAttemptService::class);
        $targetEmail = 'victima.'.bin2hex(random_bytes(4)).'@clinica.invalid';

        for ($i = 1; $i <= 15; $i++) {
            $ip = '10.20.'.intdiv($i, 250).'.'.($i % 250 + 1);
            $limiter->ensureAllowed($targetEmail, $ip);
            $limiter->failure($targetEmail, $ip);
        }

        try {
            $limiter->ensureAllowed($targetEmail, '198.51.100.77');
            $this->fail('Se esperaba bloqueo agregado por correo ante rotación de IP.');
        } catch (AuthDomainException $exception) {
            $this->assertSame('TOO_MANY_ATTEMPTS', $exception->errorCode);
            $this->assertSame(429, $exception->status);
        }

        $limiter->success($targetEmail, '198.51.100.77');
        $limiter->ensureAllowed($targetEmail, '198.51.100.77');

        Http::assertNothingSent();
    }

    #[Test]
    public function patient_form_requests_require_patient_role_in_session(): void
    {
        foreach ([
            ReserveRequest::class,
            CancelAppointmentRequest::class,
            RescheduleAppointmentRequest::class,
        ] as $requestClass) {
            $denied = $requestClass::create('/paciente/test', 'POST');
            $denied->setLaravelSession(tap(app('session.store'), fn ($s) => $s->put('cod_rol', 'ROL002')));
            $this->assertFalse($denied->authorize(), "$requestClass no debe autorizar ROL002.");

            $allowed = $requestClass::create('/paciente/test', 'POST');
            $allowed->setLaravelSession(tap(app('session.store'), fn ($s) => $s->put('cod_rol', 'ROL001')));
            $this->assertTrue($allowed->authorize(), "$requestClass debe autorizar ROL001.");
        }

        $this->assertInstanceOf(Usuario::class, new Usuario);
        Http::assertNothingSent();
    }
}
