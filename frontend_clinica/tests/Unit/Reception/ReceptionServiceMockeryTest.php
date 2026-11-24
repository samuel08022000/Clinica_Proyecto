<?php

namespace Tests\Unit\Reception;

use App\Contracts\ClinicalActorProvider;
use App\Services\Reception\ReceptionService;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\Support\MockeryIsolationTestCase;

final class ReceptionServiceMockeryTest extends MockeryIsolationTestCase
{
    public function test_non_secretary_is_rejected_before_reservation_or_database_lookup(): void
    {
        $this->denyClinicalIdentity('ROL001');
        $service = app(ReceptionService::class);
        $this->assertClinicalAccessDenied(fn () => $service->request('POST', 'citas', [
            'codPac' => 'PAC000001', 'codSlot' => 'SLOT0001',
        ]), 'reception.operation.rejected');
    }

    public function test_guard_outage_preserves_failure_and_never_reaches_clinical_collaborators(): void
    {
        $failure = new RuntimeException('PRIVATE_SYNTHETIC_AUTH_FAILURE');
        $guard = Mockery::mock(StatefulGuard::class);
        $guard->shouldReceive('user')->once()->andThrow($failure);
        $auth = Mockery::mock(AuthManager::class);
        $auth->shouldReceive('guard')->once()->with('web')->andReturn($guard);
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $actor = Mockery::mock(ClinicalActorProvider::class);
        $actor->shouldNotReceive('authenticatedUserCode');
        $this->app->instance(AuthManager::class, $auth);
        $this->app->instance(DatabaseManager::class, $database);
        $this->app->instance(ClinicalActorProvider::class, $actor);
        try {
            app(ReceptionService::class)->request('POST', 'checkin', ['tokenQr' => 'PRIVATE_SYNTHETIC_QR']);
            $this->fail('Expected guard outage');
        } catch (RuntimeException $error) {
            $this->assertSame($failure, $error);
        }
        Log::shouldHaveReceived('log')->once()->with('error', 'reception.operation.failed', [
            'http_status' => 500, 'exception_type' => RuntimeException::class, 'error_code' => 'TECHNICAL_ERROR',
        ]);
        Log::shouldNotHaveReceived('log', ['info', Mockery::any(), Mockery::any()]);
    }
}
