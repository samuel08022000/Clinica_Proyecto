<?php

namespace Tests\Unit;

use App\Contracts\PatientAppointmentManager;
use App\Contracts\PatientAvailabilityProvider;
use App\Exceptions\ClinicalOperationException;
use App\Http\Controllers\Paciente\AppointmentController;
use App\Http\Requests\Paciente\CancelAppointmentRequest;
use App\Http\Requests\Paciente\RescheduleAppointmentRequest;
use App\Http\Requests\Paciente\ReserveRequest;
use App\Services\Notifications\AppointmentNotificationService;
use App\Services\Paciente\PatientSupport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class PatientAppointmentUnitTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * @template T of FormRequest
     * @param class-string<T> $requestClass
     * @param array<string, mixed> $payload
     * @return T
     */
    private function makeValidatedRequest(string $requestClass, array $payload): FormRequest
    {
        $session = $this->app['session']->driver();
        $session->start();
        $session->put('cod_rol', 'ROL001');
        $session->put('cod_usu', 'USU001');

        $request = $requestClass::create('/paciente/citas', 'POST', $payload);
        $request->setLaravelSession($session);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->validateResolved();

        return $request;
    }

    public function test_reserva_exitosa_con_mock_y_registro_de_log(): void
    {
        $appointmentsMock = Mockery::mock(PatientAppointmentManager::class);
        $availabilityMock = Mockery::mock(PatientAvailabilityProvider::class);
        $notifications = $this->app->make(AppointmentNotificationService::class);

        $appointmentsMock->shouldReceive('reserve')
            ->once()
            ->with('SLOT001')
            ->andReturn(['codCit' => 'CIT001']);

        Log::shouldReceive('info')
            ->once()
            ->with('Cita reservada por paciente.', Mockery::on(
                static fn (array $context): bool => ($context['codCit'] ?? null) === 'CIT001'
                    && ($context['codSlot'] ?? null) === 'SLOT001'
            ));

        $request = $this->makeValidatedRequest(ReserveRequest::class, [
            'codSlot' => 'SLOT001',
        ]);

        $controller = new AppointmentController($appointmentsMock, $availabilityMock, $notifications);
        $response = $controller->store($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('CIT001', $response->getTargetUrl());
    }

    public function test_reserva_fallida_lanza_excepcion_clinica_y_registra_log_warning(): void
    {
        $appointmentsMock = Mockery::mock(PatientAppointmentManager::class);
        $availabilityMock = Mockery::mock(PatientAvailabilityProvider::class);
        $notifications = $this->app->make(AppointmentNotificationService::class);

        $appointmentsMock->shouldReceive('reserve')
            ->once()
            ->with('SLOT999')
            ->andThrow(new ClinicalOperationException(409, 'El cupo seleccionado ya no está disponible.', 'CUPO_OCUPADO'));

        Log::shouldReceive('warning')
            ->once()
            ->with('Conflicto de negocio al reservar cita de paciente.', Mockery::on(
                static fn (array $context): bool => ($context['codSlot'] ?? null) === 'SLOT999'
            ));

        $request = $this->makeValidatedRequest(ReserveRequest::class, [
            'codSlot' => 'SLOT999',
        ]);

        $controller = new AppointmentController($appointmentsMock, $availabilityMock, $notifications);

        $this->expectException(ClinicalOperationException::class);
        $this->expectExceptionMessage('El cupo seleccionado ya no está disponible.');

        $controller->store($request);
    }

    public function test_cancelacion_cita_vencida_registra_log_warning_y_propaga_excepcion(): void
    {
        $appointmentsMock = Mockery::mock(PatientAppointmentManager::class);
        $availabilityMock = Mockery::mock(PatientAvailabilityProvider::class);
        $notifications = $this->app->make(AppointmentNotificationService::class);

        $appointmentsMock->shouldReceive('cancel')
            ->once()
            ->with('CIT010', 'Motivo de prueba')
            ->andThrow(new ClinicalOperationException(409, 'La cita ya venció y no puede cancelarse.', 'CITA_VENCIDA'));

        Log::shouldReceive('warning')
            ->once()
            ->with('No se pudo cancelar la cita del paciente por regla clínica.', Mockery::on(
                static fn (array $context): bool => ($context['codCit'] ?? null) === 'CIT010'
            ));

        $request = $this->makeValidatedRequest(CancelAppointmentRequest::class, [
            'motivo' => 'Motivo de prueba',
            'confirmacion' => '1',
        ]);

        $controller = new AppointmentController($appointmentsMock, $availabilityMock, $notifications);

        $this->expectException(ClinicalOperationException::class);
        $this->expectExceptionMessage('La cita ya venció y no puede cancelarse.');

        $controller->cancel($request, 'CIT010');
    }

    public function test_reprogramacion_exitosa_con_mock_y_registro_de_log(): void
    {
        $appointmentsMock = Mockery::mock(PatientAppointmentManager::class);
        $availabilityMock = Mockery::mock(PatientAvailabilityProvider::class);
        $notifications = $this->app->make(AppointmentNotificationService::class);

        $appointmentsMock->shouldReceive('reschedule')
            ->once()
            ->with('CIT001', 'SLOT002', 'Cambio de horario laboral')
            ->andReturn(['codCit' => 'CIT002']);

        Log::shouldReceive('info')
            ->once()
            ->with('Cita reprogramada por paciente.', Mockery::on(
                static fn (array $context): bool => ($context['codCitAnterior'] ?? null) === 'CIT001'
                    && ($context['codCitNueva'] ?? null) === 'CIT002'
            ));

        $request = $this->makeValidatedRequest(RescheduleAppointmentRequest::class, [
            'codSlotNuevo' => 'SLOT002',
            'motivo' => 'Cambio de horario laboral',
            'confirmacion' => '1',
        ]);

        $controller = new AppointmentController($appointmentsMock, $availabilityMock, $notifications);
        $response = $controller->reschedule($request, 'CIT001');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('CIT002', $response->getTargetUrl());
    }

    public function test_validaciones_unitarias_de_patient_support(): void
    {
        $this->assertSame('CIT001', PatientSupport::identifier('  CIT001  ', 'codCit'));
        $this->assertSame('2026-11-20', PatientSupport::date('2026-11-20', 'fecha'));
        $this->assertSame(2, PatientSupport::page('2', 0, 10, 'page'));

        $this->expectException(ClinicalOperationException::class);
        PatientSupport::identifier('CIT-INVALIDO!@#', 'codCit');
    }
}
