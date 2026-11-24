<?php

namespace Tests\Unit\Administration;

use App\Services\Administration\AdministrationService;
use Tests\Support\MockeryIsolationTestCase;

final class AdministrationServiceMockeryTest extends MockeryIsolationTestCase
{
    public function test_non_admin_identity_never_opens_database_or_starts_doctor_creation(): void
    {
        $this->denyClinicalIdentity('ROL001');
        $service = app(AdministrationService::class);
        $this->assertClinicalAccessDenied(fn () => $service->request('POST', 'personal/medicos', [
            'nombreCompleto' => 'PRIVATE_SYNTHETIC_NAME', 'password' => 'SyntheticPassword9!',
        ]), 'admin.operation.rejected');
    }
}
