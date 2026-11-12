<?php

use App\Http\Controllers\Administracion\AgendaController;
use App\Http\Controllers\Administracion\BloqueoController;
use App\Http\Controllers\Administracion\DashboardController;
use App\Http\Controllers\Administracion\EspecialidadController;
use App\Http\Controllers\Administracion\MedicoController;
use App\Http\Controllers\Administracion\PacienteController;
use App\Http\Controllers\Administracion\SancionController;
use App\Http\Controllers\Administracion\SecretariaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PortalController;
use App\Http\Middleware\AdministrativeResponses;
use Illuminate\Support\Facades\Route;

Route::get('/', [PortalController::class, 'index'])->name('home');

Route::controller(AuthController::class)->group(function (): void {
    Route::get('/login', 'showLogin')->name('login');
    Route::post('/login', 'login')->middleware('throttle:5,1,login:')->name('login.attempt');
    Route::post('/logout', 'logout')->name('logout');

    Route::get('/registro', 'showRegistration')->name('register');
    Route::post('/registro', 'register')->middleware('throttle:3,1,register:')->name('register.store');
    Route::get('/auth/google', 'redirectToGoogle')->middleware('throttle:10,1,google-start:')->name('google.login');
    Route::get('/registro/google', 'registerWithGoogle')->middleware('throttle:10,1,google-start:')->name('google.register');
    Route::get('/auth/google/callback', 'handleGoogleCallback')->name('google.callback');
    Route::get('/registro/google/completar', 'showGoogleRegistration')->name('google.registration');
    Route::post('/registro/google/completar', 'completeGoogleRegistration')
        ->middleware('throttle:3,1,google-register:')
        ->name('google.registration.store');

    Route::get('/verificar-correo', 'showEmailOtpVerification')->name('verification.email');
    Route::post('/verificar-correo', 'verifyEmailOtp')
        ->middleware('throttle:5,1,otp-verify:')
        ->name('verification.email.verify');
    Route::post('/verificar-correo/reenviar', 'resendEmailOtp')
        ->middleware('throttle:3,1,otp-resend:')
        ->name('verification.email.resend');
    Route::post('/verificar-correo/solicitar', 'requestEmailVerification')
        ->middleware('throttle:5,1,otp-request:')
        ->name('verification.email.request');

    Route::get('/recuperar-contrasena', 'showForgotPassword')->name('password.request');
    Route::post('/recuperar-contrasena', 'requestPasswordReset')
        ->middleware('throttle:3,1,password-forgot:')
        ->name('password.email');

    Route::get('/restablecer-contrasena/{token?}', 'showResetPassword')->name('password.reset');
    Route::post('/restablecer-contrasena', 'resetPassword')
        ->middleware('throttle:3,1,password-reset:')
        ->name('password.update');

});

Route::controller(AuthController::class)->group(function (): void {
    Route::get('/paciente/inicio', 'dashboard')->middleware('rol:ROL001')->name('paciente.home');
    Route::get('/secretaria/inicio', 'dashboard')->middleware('rol:ROL002')->name('secretaria.home');
    Route::get('/medico/inicio', 'dashboard')->middleware('rol:ROL003')->name('medico.home');
});

Route::prefix('administracion')->name('administracion.')
    ->middleware([AdministrativeResponses::class, 'rol:ROL004'])
    ->group(function (): void {
        Route::get('/inicio', DashboardController::class)->name('home');
        foreach (['medicos' => MedicoController::class,
            'secretarias' => SecretariaController::class,
            'especialidades' => EspecialidadController::class] as $resource => $controller) {
            Route::controller($controller)->prefix($resource)->name($resource.'.')->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('/crear', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('/{codigo}', 'show')->whereAlphaNumeric('codigo')->name('show');
                Route::get('/{codigo}/editar', 'edit')->whereAlphaNumeric('codigo')->name('edit');
                Route::put('/{codigo}', 'update')->whereAlphaNumeric('codigo')->name('update');
                Route::put('/{codigo}/estado', 'estado')->whereAlphaNumeric('codigo')->name('estado');
            });
        }
        Route::get('/secretarias/{codigo}/password', [SecretariaController::class, 'password'])->whereAlphaNumeric('codigo')->name('secretarias.password');
        Route::put('/secretarias/{codigo}/password', [SecretariaController::class, 'resetPassword'])->whereAlphaNumeric('codigo')->name('secretarias.password.update');

        Route::controller(AgendaController::class)->prefix('agendas')->name('agendas.')->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/{medico}/horarios/crear', 'create')->whereAlphaNumeric('medico')->name('create');
            Route::post('/{medico}/horarios', 'store')->whereAlphaNumeric('medico')->name('store');
            Route::get('/{medico}/horarios/{horario}/editar', 'edit')->whereAlphaNumeric(['medico', 'horario'])->name('edit');
            Route::put('/{medico}/horarios/{horario}', 'update')->whereAlphaNumeric(['medico', 'horario'])->name('update');
            Route::put('/{medico}/horarios/{horario}/estado', 'estado')->whereAlphaNumeric(['medico', 'horario'])->name('estado');
            Route::put('/{medico}/duracion', 'duracion')->whereAlphaNumeric('medico')->name('duracion');
        });
        Route::controller(BloqueoController::class)->prefix('bloqueos')->name('bloqueos.')->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/medicos/{medico}/crear', 'create')->whereAlphaNumeric('medico')->name('create');
            Route::post('/medicos/{medico}', 'store')->whereAlphaNumeric('medico')->name('store');
            Route::get('/{bloqueo}', 'show')->whereAlphaNumeric('bloqueo')->name('show');
        });
        Route::get('/pacientes', [PacienteController::class, 'index'])->name('pacientes.index');
        Route::get('/sanciones', [PacienteController::class, 'index'])->name('sanciones.index');
        Route::get('/pacientes/{paciente}', [PacienteController::class, 'show'])->whereAlphaNumeric('paciente')->name('pacientes.show');
        Route::get('/pacientes/{paciente}/sanciones/crear', [SancionController::class, 'create'])->whereAlphaNumeric('paciente')->name('sanciones.create');
        Route::post('/pacientes/{paciente}/sanciones', [SancionController::class, 'store'])->whereAlphaNumeric('paciente')->name('sanciones.store');
        Route::get('/pacientes/{paciente}/sanciones/{sancion}', [SancionController::class, 'show'])->whereAlphaNumeric(['paciente', 'sancion'])->name('sanciones.show');
    });
