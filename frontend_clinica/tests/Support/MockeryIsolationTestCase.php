<?php

namespace Tests\Support;

use App\Contracts\ClinicalActorProvider;
use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use LogicException;
use Mockery;
use Tests\TestCase;

/** Laravel verifica expectativas y cierra Mockery en su tearDown; no se desactiva ese ciclo. */
abstract class MockeryIsolationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing')) {
            throw new LogicException('These tests require the testing environment');
        }
        // Toda persistencia clínica que se necesite en estos casos será SQLite efímero.
        config([
            'database.connections.clinical' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'logging.default' => 'null',
            'mail.default' => 'array',
            'session.driver' => 'array',
        ]);
        Http::preventStrayRequests();
        Mail::fake();
        Log::spy();
    }

    protected function denyClinicalIdentity(string $wrongRole): void
    {
        $user = new Usuario;
        $user->cod_usu = 'USU000001';
        $user->cod_rol = $wrongRole;
        $user->estado = 'Activo';
        $guard = Mockery::mock(StatefulGuard::class);
        $guard->shouldReceive('user')->once()->andReturn($user);
        $auth = Mockery::mock(AuthManager::class);
        $auth->shouldReceive('guard')->with('web')->once()->andReturn($guard);
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $actor = Mockery::mock(ClinicalActorProvider::class);
        $actor->shouldNotReceive('authenticatedUserCode');
        $this->app->instance(AuthManager::class, $auth);
        $this->app->instance(DatabaseManager::class, $database);
        $this->app->instance(ClinicalActorProvider::class, $actor);
    }

    protected function validatedForm(string $class, array $data): FormRequest
    {
        $request = $class::create('/unit-test', 'POST', $data);
        $request->setContainer($this->app);
        $request->setRedirector(app('redirect'));
        $request->setLaravelSession(app('session.store'));
        $validator = Validator::make($data, $request->rules());
        $validator->validate();
        $request->setValidator($validator);

        return $request;
    }

    protected function assertClinicalAccessDenied(callable $operation, ?string $event = null): void
    {
        try {
            $operation();
            $this->fail('Expected authorization failure before clinical work');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(403, $error->status);
            $this->assertSame('ROL_INSUFICIENTE', $error->errorCode);
        }
        if ($event !== null) {
            Log::shouldHaveReceived('log')->once()->with('warning', $event, [
                'http_status' => 403, 'exception_type' => ClinicalOperationException::class,
                'error_code' => 'ROL_INSUFICIENTE',
            ]);
        } else {
            Log::shouldNotHaveReceived('log');
        }
    }
}
