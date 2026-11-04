<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PortalController::class, 'index'])->name('home');

Route::controller(AuthController::class)->group(function (): void {
    Route::get('/login', 'showLogin')->name('login');
    Route::post('/login', 'login')->middleware('throttle:5,1')->name('login.attempt');
    Route::post('/logout', 'logout')->name('logout');

    Route::get('/registro', 'showRegistration')->name('register');
    Route::post('/registro', 'register')->middleware('throttle:3,1')->name('register.store');

    Route::get('/recuperar-contrasena', 'showForgotPassword')->name('password.request');
    Route::post('/recuperar-contrasena', 'requestPasswordReset')
        ->middleware('throttle:3,1')
        ->name('password.email');

    Route::get('/restablecer-contrasena/{token?}', 'showResetPassword')->name('password.reset');
    Route::post('/restablecer-contrasena', 'resetPassword')
        ->middleware('throttle:3,1')
        ->name('password.update');

    Route::get('/verificar-correo', 'showEmailVerification')->name('verification.notice');
});

Route::controller(AuthController::class)->group(function (): void {
    Route::get('/paciente/inicio', 'dashboard')->middleware('rol:ROL001')->name('paciente.home');
    Route::get('/secretaria/inicio', 'dashboard')->middleware('rol:ROL002')->name('secretaria.home');
    Route::get('/medico/inicio', 'dashboard')->middleware('rol:ROL003')->name('medico.home');
    Route::get('/administracion/inicio', 'dashboard')->middleware('rol:ROL004')->name('administracion.home');
});
