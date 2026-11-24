<?php

namespace Tests\Unit\Paciente;

use App\Services\Paciente\PatientAppointmentService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MockeryIsolationTestCase;

final class PatientAppointmentServiceMockeryTest extends MockeryIsolationTestCase
{
    public static function mutations(): array
    {
        return [
            'reserve' => ['reserve', ['SLOT0001']],
            'cancel' => ['cancel', ['CIT00000001', 'Synthetic fixture']],
            'reschedule' => ['reschedule', ['CIT00000001', 'SLOT0002', 'Synthetic fixture']],
        ];
    }

    #[DataProvider('mutations')]
    public function test_non_patient_never_reaches_transaction_or_database(string $method, array $args): void
    {
        $this->denyClinicalIdentity('ROL004');
        $service = app(PatientAppointmentService::class);
        $this->assertClinicalAccessDenied(fn () => $service->{$method}(...$args));
    }
}
