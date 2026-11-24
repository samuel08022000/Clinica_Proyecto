<?php

namespace Tests\Unit\Auth;

use App\Models\Usuario;
use App\Services\Auth\SessionService;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\Support\MockeryIsolationTestCase;

final class SessionServiceMockeryTest extends MockeryIsolationTestCase
{
    public function test_guard_logout_failure_preserves_session_and_does_not_claim_success(): void
    {
        $user = new Usuario;
        $user->cod_usu = 'USU000004';
        $user->cod_rol = 'ROL004';
        $failure = new RuntimeException('Synthetic guard outage');
        $guard = Mockery::mock(StatefulGuard::class);
        $guard->shouldReceive('user')->once()->ordered()->andReturn($user);
        $guard->shouldReceive('logout')->once()->ordered()->andThrow($failure);
        $auth = Mockery::mock(AuthManager::class);
        $auth->shouldReceive('guard')->twice()->with('web')->andReturn($guard);
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $request = Request::create('/logout', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('auth_authority', 'laravel');
        try {
            (new SessionService($auth, $database))->logout($request);
            $this->fail('Expected guard outage');
        } catch (RuntimeException $error) {
            $this->assertSame($failure, $error);
            $this->assertSame('laravel', $request->session()->get('auth_authority'));
        }
        Log::shouldNotHaveReceived('log');
    }
}
