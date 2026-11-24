<?php

namespace Tests\Unit\Paciente;

use App\Contracts\PatientAppointmentManager;
use App\Contracts\PatientAvailabilityProvider;
use App\Exceptions\ClinicalOperationException;
use App\Http\Controllers\Paciente\AppointmentController;
use App\Http\Controllers\Paciente\NotificationController;
use App\Http\Requests\Paciente\RescheduleAppointmentRequest;
use App\Services\Notifications\AppointmentNotificationService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Support\MockeryIsolationTestCase;

final class AppointmentOrchestrationMockeryTest extends MockeryIsolationTestCase
{
    public function test_failed_reschedule_never_invokes_notification_or_flashes_success(): void
    {
        $error = new ClinicalOperationException(409, 'Synthetic conflict', 'CONFLICTO');
        $appointments = Mockery::mock(PatientAppointmentManager::class);
        $appointments->shouldReceive('reschedule')->once()->with('CIT00000001', 'SLOT0002', 'Synthetic fixture')->andThrow($error);
        $availability = Mockery::mock(PatientAvailabilityProvider::class);
        $availability->shouldNotReceive('slots');
        $availability->shouldNotReceive('doctors');
        $notifications = Mockery::mock(AppointmentNotificationService::class);
        $notifications->shouldNotReceive('sendConfirmation');
        $controller = new AppointmentController($appointments, $availability, $notifications);
        $request = $this->validatedForm(RescheduleAppointmentRequest::class, [
            'codSlotNuevo' => 'SLOT0002', 'motivo' => 'Synthetic fixture', 'confirmacion' => 'yes',
        ]);
        try {
            $controller->reschedule($request, 'CIT00000001');
            $this->fail('Expected reschedule conflict');
        } catch (ClinicalOperationException $actual) {
            $this->assertSame($error, $actual);
            $this->assertFalse($request->session()->has('success'));
        }
        Log::shouldNotHaveReceived('log', ['info', Mockery::any(), Mockery::any()]);
    }

    public function test_retry_rejection_never_calls_send_confirmation(): void
    {
        $error = new ClinicalOperationException(409, 'Synthetic retry conflict', 'CONFLICTO');
        $notifications = Mockery::mock(AppointmentNotificationService::class);
        $notifications->shouldReceive('retryConfirmation')->once()->with('CIT00000001')->andThrow($error);
        $notifications->shouldNotReceive('sendConfirmation');
        try {
            (new NotificationController)->retry('CIT00000001', $notifications);
            $this->fail('Expected retry rejection');
        } catch (ClinicalOperationException $actual) {
            $this->assertSame($error, $actual);
            $this->assertFalse(app('session.store')->has('success'));
        }
        Log::shouldNotHaveReceived('log', ['info', Mockery::any(), Mockery::any()]);
    }
}
