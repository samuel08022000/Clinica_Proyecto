<?php

namespace Tests\Unit\Paciente;

use App\Services\Clinical\ClinicalRoleGuard;
use App\Services\Notifications\AppointmentNotificationService;
use Illuminate\Auth\AuthManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\Support\MockeryIsolationTestCase;

final class AppointmentNotificationServiceMockeryTest extends MockeryIsolationTestCase
{
    public function test_rejected_transport_stops_before_claiming_outbox_or_sending_messages(): void
    {
        config(['mail.default' => 'log']);
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $auth = Mockery::mock(AuthManager::class);
        $auth->shouldNotReceive('guard');
        $service = new AppointmentNotificationService($database, new ClinicalRoleGuard($auth, $database));
        try {
            $service->process(1);
            $this->fail('Expected transport preflight rejection');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Configure un transporte de correo real', $error->getMessage());
        }
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Http::assertNothingSent();
        Log::shouldNotHaveReceived('log');
        Log::shouldNotHaveReceived('warning');
    }
}
