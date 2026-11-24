<?php

use App\Http\Middleware\CheckRolSession;
use App\Support\ClinicalLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'rol' => CheckRolSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $error): bool {
            ClinicalLog::failure('app.exception', $error);

            // Sustituye el volcado estándar (mensaje, SQL, bindings y stacktrace) por contexto seguro.
            // Laravel conserva sus exclusiones ShouldntReport antes de invocar este callback.
            return false;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
