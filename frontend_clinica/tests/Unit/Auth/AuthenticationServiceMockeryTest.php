<?php

namespace Tests\Unit\Auth;

use App\Exceptions\AuthDomainException;
use App\Models\Usuario;
use App\Repositories\AuthAccountRepository;
use App\Services\Auth\AccountStatusService;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\LoginAttemptService;
use App\Services\Auth\SessionService;
use Illuminate\Auth\AuthManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\MockeryIsolationTestCase;

/** Componente: repositorio Eloquent real en SQLite; estado de cuenta, limitador y guard simulados. */
final class AuthenticationServiceMockeryTest extends MockeryIsolationTestCase
{
    private AccountStatusService&MockInterface $status;

    private RateLimiter&MockInterface $limiter;

    private AuthManager&MockInterface $auth;

    private StatefulGuard&MockInterface $guard;

    private AuthenticationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = app('db')->connection('clinical');
        $this->assertSame('sqlite', $connection->getDriverName());
        Schema::connection('clinical')->create('usuarios', function (Blueprint $table): void {
            $table->string('cod_usu')->primary();
            $table->string('email');
            $table->string('password_hash');
            $table->string('cod_rol');
            $table->string('estado');
        });
        $connection->table('usuarios')->insert([
            'cod_usu' => 'USU000004', 'email' => 'synthetic@example.invalid',
            'password_hash' => app(HashManager::class)->driver('bcrypt')->make('SyntheticPassword9!'),
            'cod_rol' => 'ROL004', 'estado' => 'Activo',
        ]);
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $this->status = Mockery::mock(AccountStatusService::class);
        $this->limiter = Mockery::mock(RateLimiter::class);
        $this->guard = Mockery::mock(StatefulGuard::class);
        $this->auth = Mockery::mock(AuthManager::class);
        $databaseForSessions = Mockery::mock(DatabaseManager::class);
        $databaseForSessions->shouldNotReceive('connection');
        $this->service = new AuthenticationService(
            new AuthAccountRepository(app('db')), $this->status, new LoginAttemptService($this->limiter),
            new SessionService($this->auth, $databaseForSessions), app(HashManager::class),
        );
    }

    public function test_account_collaborator_result_is_used_only_after_credentials_and_before_session_login(): void
    {
        $this->allowAttempts();
        $account = (object) [
            'cod_usu' => 'USU000004', 'cod_rol' => 'ROL004', 'nombre_completo' => 'SYNTHETIC_PRIVATE_NAME',
            'email' => 'synthetic@example.invalid', 'nombre_rol' => 'Administrador', 'perfil_estado' => 'Activo',
        ];
        $this->status->shouldReceive('requireActive')->once()->with('USU000004')
            ->globally()->ordered()->andReturn($account);
        $this->limiter->shouldReceive('clear')->twice()->with(Mockery::type('string'))->globally()->ordered();
        $this->auth->shouldReceive('guard')->once()->with('web')->andReturn($this->guard);
        $this->guard->shouldReceive('login')->once()->with(Mockery::on(fn ($user) => $user instanceof Usuario
            && $user->cod_usu === 'USU000004'), false)->globally()->ordered();
        $request = $this->loginRequest();

        $result = $this->service->login($request, ' SYNTHETIC@example.invalid ', 'SyntheticPassword9!');

        $this->assertSame($account, $result);
        $this->assertSame('USU000004', $request->session()->get('cod_usu'));
        $this->assertSame('laravel', $request->session()->get('auth_authority'));
        $this->assertCount(1, app('db')->connection('clinical')->getQueryLog());
        Log::shouldHaveReceived('log')->once()->with('info', 'auth.login.success',
            ['actor_id' => 'USU000004', 'role' => 'ROL004', 'action' => 'credentials']);
    }

    public function test_account_rejection_does_not_clear_attempts_or_call_the_guard(): void
    {
        $this->allowAttempts();
        $error = new AuthDomainException('ACCOUNT_TEMPORARILY_BLOCKED', 423, 'Cuenta sintética bloqueada.');
        $this->status->shouldReceive('requireActive')->once()->with('USU000004')->andThrow($error);
        $this->limiter->shouldNotReceive('clear');
        $this->limiter->shouldNotReceive('hit');
        $this->auth->shouldNotReceive('guard');
        $request = $this->loginRequest();
        try {
            $this->service->login($request, 'synthetic@example.invalid', 'SyntheticPassword9!');
            $this->fail('The account rejection must propagate');
        } catch (AuthDomainException $actual) {
            $this->assertSame($error, $actual);
            $this->assertFalse($request->session()->has('auth_authority'));
        }
        Log::shouldHaveReceived('log')->once()->with('warning', 'auth.login.failed',
            ['error_code' => 'ACCOUNT_TEMPORARILY_BLOCKED']);
        Log::shouldNotHaveReceived('log', ['info', Mockery::any(), Mockery::any()]);
    }

    public function test_invalid_password_never_resolves_account_status_or_session_guard(): void
    {
        $this->allowAttempts();
        $this->status->shouldNotReceive('requireActive');
        $this->auth->shouldNotReceive('guard');
        $this->limiter->shouldReceive('hit')->twice()->with(Mockery::type('string'), 900)->andReturn(1);
        $this->limiter->shouldNotReceive('clear');
        try {
            $this->service->login($this->loginRequest(), 'synthetic@example.invalid', 'WrongSyntheticSecret9!');
            $this->fail('Expected invalid credentials');
        } catch (AuthDomainException $error) {
            $this->assertSame('INVALID_CREDENTIALS', $error->errorCode);
            $this->assertSame(401, $error->status);
            $this->assertStringNotContainsString('synthetic@example.invalid', $error->getMessage());
            $this->assertStringNotContainsString('WrongSyntheticSecret9!', $error->getMessage());
        }
        Log::shouldHaveReceived('log')->once()->with('warning', 'auth.login.failed', ['error_code' => 'INVALID_CREDENTIALS']);
        Log::shouldNotHaveReceived('log', ['info', Mockery::any(), Mockery::any()]);
    }

    public function test_mocked_rate_limit_stops_before_any_account_lookup_or_guard_call(): void
    {
        $this->limiter->shouldReceive('tooManyAttempts')->once()->with(Mockery::type('string'), 5)->andReturnTrue();
        $this->limiter->shouldReceive('availableIn')->once()->with(Mockery::type('string'))->andReturn(37);
        $this->limiter->shouldNotReceive('hit');
        $this->limiter->shouldNotReceive('clear');
        $this->status->shouldNotReceive('requireActive');
        $this->auth->shouldNotReceive('guard');
        try {
            $this->service->login($this->loginRequest(), 'synthetic@example.invalid', 'SyntheticPassword9!');
            $this->fail('Expected rate limit');
        } catch (AuthDomainException $error) {
            $this->assertSame('TOO_MANY_ATTEMPTS', $error->errorCode);
            $this->assertSame(37, $error->retryAfter);
        }
        $this->assertSame([], app('db')->connection('clinical')->getQueryLog());
        Log::shouldHaveReceived('log')->once()->with('warning', 'auth.login.blocked',
            ['action' => 'pair', 'error_code' => 'TOO_MANY_ATTEMPTS']);
        Log::shouldNotHaveReceived('log', ['warning', 'auth.login.failed', Mockery::any()]);
    }

    private function allowAttempts(): void
    {
        $this->limiter->shouldReceive('tooManyAttempts')->once()->with(Mockery::type('string'), 5)->andReturnFalse();
        $this->limiter->shouldReceive('tooManyAttempts')->once()->with(Mockery::type('string'), 15)->andReturnFalse();
    }

    private function loginRequest(): Request
    {
        $request = Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.77']);
        $request->setLaravelSession(app('session.store'));

        return $request;
    }
}
