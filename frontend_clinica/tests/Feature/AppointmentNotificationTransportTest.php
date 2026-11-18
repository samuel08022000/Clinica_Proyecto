<?php

namespace Tests\Feature;

use App\Services\Notifications\AppointmentNotificationService;
use RuntimeException;
use Tests\TestCase;

class AppointmentNotificationTransportTest extends TestCase
{
    public function test_log_transport_cannot_be_reported_as_email_delivery(): void
    {
        config(['mail.default' => 'log']);
        $this->expectException(RuntimeException::class);
        app(AppointmentNotificationService::class)->assertMailTransport();
    }

    public function test_invalid_sender_is_rejected_before_claiming_notifications(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'invalid']);
        $this->expectException(RuntimeException::class);
        app(AppointmentNotificationService::class)->assertMailTransport();
    }

    public function test_configuration_check_does_not_send_or_connect_to_the_database(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'clinic@example.invalid']);
        $this->artisan('clinical:notifications --check')->assertSuccessful();
    }

    public function test_invalid_batch_size_is_rejected_without_claiming_notifications(): void
    {
        $this->artisan('clinical:notifications --limit=501')->assertExitCode(2);
    }
}
