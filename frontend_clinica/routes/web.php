<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PortalController;
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
    Route::get('/administracion/inicio', 'dashboard')->middleware('rol:ROL004')->name('administracion.home');
});
