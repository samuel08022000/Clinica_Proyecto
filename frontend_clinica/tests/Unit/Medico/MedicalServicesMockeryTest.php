<?php

namespace Tests\Unit\Medico;

use App\Contracts\ClinicalActorProvider;
use App\Exceptions\ClinicalOperationException;
use App\Services\Medico\MedicoAnalisisService;
use App\Services\Medico\MedicoAtencionService;
use App\Services\Medico\MedicoDerivacionService;
use Illuminate\Auth\AuthManager;
use Illuminate\Database\DatabaseManager;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MockeryIsolationTestCase;

final class MedicalServicesMockeryTest extends MockeryIsolationTestCase
{
    public static function protectedOperations(): array
    {
        return [
            'finish attention' => [MedicoAtencionService::class, 'finish', ['CIT00000001']],
            'process referral' => [MedicoDerivacionService::class, 'create', ['CIT00000001',
                ['codEspDestino' => 'ESP001', 'motivo' => 'Synthetic fixture']]],
            'request analysis' => [MedicoAnalisisService::class, 'request', ['CIT00000001',
                ['tipoAnalisis' => 'Synthetic fixture']]],
        ];
    }

    #[DataProvider('protectedOperations')]
    public function test_non_doctor_cannot_reach_transaction_actor_or_database(string $class, string $method, array $args): void
    {
        $this->denyClinicalIdentity('ROL001');
        $service = app($class);
        $this->assertClinicalAccessDenied(fn () => $service->{$method}(...$args));
    }

    public function test_invalid_analysis_transition_stops_before_even_resolving_identity(): void
    {
        $auth = Mockery::mock(AuthManager::class);
        $auth->shouldNotReceive('guard');
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $actor = Mockery::mock(ClinicalActorProvider::class);
        $actor->shouldNotReceive('authenticatedUserCode');
        $this->app->instance(AuthManager::class, $auth);
        $this->app->instance(DatabaseManager::class, $database);
        $this->app->instance(ClinicalActorProvider::class, $actor);
        try {
            app(MedicoAnalisisService::class)->update('ANA00000001', ['estado' => 'Estado inexistente']);
            $this->fail('Expected state validation');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(400, $error->status);
            $this->assertSame('VALIDACION', $error->errorCode);
        }
    }
}
