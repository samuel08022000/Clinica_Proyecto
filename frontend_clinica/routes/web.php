<?php

use App\Http\Controllers\Administracion\AgendaController;
use App\Http\Controllers\Administracion\BitacoraController;
use App\Http\Controllers\Administracion\BloqueoController;
use App\Http\Controllers\Administracion\ColaController;
use App\Http\Controllers\Administracion\DashboardController;
use App\Http\Controllers\Administracion\EspecialidadController;
use App\Http\Controllers\Administracion\MedicoController;
use App\Http\Controllers\Administracion\PacienteController;
use App\Http\Controllers\Administracion\SancionController;
use App\Http\Controllers\Administracion\SecretariaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Medico\AgendaController as MedicoAgendaController;
use App\Http\Controllers\Medico\AnalisisController as MedicoAnalisisController;
use App\Http\Controllers\Medico\CitaController as MedicoCitaController;
use App\Http\Controllers\Medico\DashboardController as MedicoDashboardController;
use App\Http\Controllers\Medico\DerivacionController as MedicoDerivacionController;
use App\Http\Controllers\Paciente\AppointmentController as PatientAppointmentController;
use App\Http\Controllers\Paciente\DashboardController as PatientDashboardController;
use App\Http\Controllers\Paciente\NotificationController;
use App\Http\Controllers\Paciente\ProfileController as PatientProfileController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\QrPassController;
use App\Http\Controllers\Secretaria\CheckinController;
use App\Http\Controllers\Secretaria\CitaController;
use App\Http\Controllers\Secretaria\DerivacionController as SecretariaDerivacionController;
use App\Http\Controllers\Secretaria\HistorialController;
use App\Http\Controllers\Secretaria\ReservaController;
use App\Http\Middleware\AdministrativeResponses;
use App\Http\Middleware\MedicoResponses;
use App\Http\Middleware\PatientResponses;
use App\Http\Middleware\ReceptionResponses;
use App\Http\Middleware\ThrottleAppointmentQrMail;
use Illuminate\Support\Facades\Route;

Route::get('/', [PortalController::class, 'index'])->name('home');

Route::get('/pase-qr/{token}', [QrPassController::class, 'show'])
    ->where('token', '[A-Za-z0-9\-_=\.]+')
    ->name('pase-qr.show');

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

Route::prefix('paciente')->name('paciente.')
    ->middleware([PatientResponses::class, 'rol:ROL001'])
    ->group(function (): void {
        Route::get('/inicio', PatientDashboardController::class)->name('home');
        Route::get('/notificaciones', [NotificationController::class, 'index'])->name('notificaciones');
        Route::post('/citas/{cita}/correo-qr', [NotificationController::class, 'retry'])
            ->whereAlphaNumeric('cita')->middleware(ThrottleAppointmentQrMail::class)->name('citas.correo-qr');
        Route::get('/citas', [PatientAppointmentController::class, 'index'])->name('citas');
        Route::get('/citas/nueva', [PatientAppointmentController::class, 'create'])->name('citas.nueva');
        Route::post('/citas', [PatientAppointmentController::class, 'store'])->name('citas.store');
        Route::get('/citas/{cita}', [PatientAppointmentController::class, 'show'])
            ->whereAlphaNumeric('cita')->name('citas.show');
        Route::post('/citas/{cita}/cancelar', [PatientAppointmentController::class, 'cancel'])
            ->whereAlphaNumeric('cita')->name('citas.cancel');
        Route::get('/citas/{cita}/reprogramar', [PatientAppointmentController::class, 'editReschedule'])
            ->whereAlphaNumeric('cita')->name('citas.reprogramar');
        Route::post('/citas/{cita}/reprogramar', [PatientAppointmentController::class, 'reschedule'])
            ->whereAlphaNumeric('cita')->name('citas.reschedule');
        Route::get('/perfil', [PatientProfileController::class, 'show'])->name('perfil');
    });

Route::prefix('medico')->name('medico.')
    ->middleware([MedicoResponses::class, 'rol:ROL003'])
    ->group(function (): void {
        Route::get('/inicio', MedicoDashboardController::class)->name('home');
        Route::get('/agenda', [MedicoAgendaController::class, 'index'])->name('agenda.index');
        Route::get('/historial', [MedicoAgendaController::class, 'history'])->name('historial.index');
        Route::get('/citas/{cita}', [MedicoCitaController::class, 'show'])->whereAlphaNumeric('cita')->name('citas.show');
        Route::post('/citas/{cita}/iniciar', [MedicoCitaController::class, 'start'])->whereAlphaNumeric('cita')->name('citas.iniciar');
        Route::post('/citas/{cita}/atender', [MedicoCitaController::class, 'finish'])->whereAlphaNumeric('cita')->name('citas.atender');
        Route::get('/derivaciones', [MedicoDerivacionController::class, 'index'])->name('derivaciones.index');
        Route::get('/derivaciones/{derivacion}', [MedicoDerivacionController::class, 'show'])->whereAlphaNumeric('derivacion')->name('derivaciones.show');
        Route::get('/citas/{cita}/derivaciones/crear', [MedicoDerivacionController::class, 'create'])->whereAlphaNumeric('cita')->name('derivaciones.create');
        Route::post('/citas/{cita}/derivaciones', [MedicoDerivacionController::class, 'store'])->whereAlphaNumeric('cita')->name('derivaciones.store');
        Route::get('/citas/{cita}/analisis', [MedicoAnalisisController::class, 'index'])->whereAlphaNumeric('cita')->name('analisis.index');
        Route::post('/citas/{cita}/analisis', [MedicoAnalisisController::class, 'store'])->whereAlphaNumeric('cita')->name('analisis.store');
        Route::patch('/analisis/{analisis}', [MedicoAnalisisController::class, 'update'])->whereAlphaNumeric('analisis')->name('analisis.update');
    });

Route::prefix('secretaria')->name('secretaria.')
    ->middleware([ReceptionResponses::class, 'rol:ROL002'])
    ->group(function (): void {
        Route::get('/inicio', App\Http\Controllers\Secretaria\DashboardController::class)->name('home');
        Route::get('/pacientes', [App\Http\Controllers\Secretaria\PacienteController::class, 'index'])->name('pacientes.index');
        Route::get('/pacientes/{paciente}', [App\Http\Controllers\Secretaria\PacienteController::class, 'show'])->whereAlphaNumeric('paciente')->name('pacientes.show');
        Route::get('/historial', [App\Http\Controllers\Secretaria\PacienteController::class, 'index'])->name('historial.index');
        Route::get('/pacientes/{paciente}/historial', [HistorialController::class, 'show'])->whereAlphaNumeric('paciente')->name('historial.show');
        Route::get('/reservas/nueva', [ReservaController::class, 'create'])->name('reservas.create');
        Route::post('/reservas', [ReservaController::class, 'store'])->name('reservas.store');
        Route::get('/citas', [CitaController::class, 'index'])->name('citas.index');
        Route::get('/citas/{cita}', [CitaController::class, 'show'])->whereAlphaNumeric('cita')->name('citas.show');
        Route::get('/checkin', [CheckinController::class, 'index'])->name('checkin.index');
        Route::post('/checkin', [CheckinController::class, 'store'])->name('checkin.store');
        Route::post('/checkin/validar', [CheckinController::class, 'preview'])->middleware('throttle:30,1')->name('checkin.preview');
        Route::get('/citas/{cita}/asistencia', [CheckinController::class, 'attendance'])->whereAlphaNumeric('cita')->name('citas.asistencia');
        Route::get('/citas/{cita}/acreditacion', [CheckinController::class, 'accreditation'])->whereAlphaNumeric('cita')->name('citas.acreditacion');
        Route::get('/citas/{cita}/cancelar', [CitaController::class, 'cancel'])->whereAlphaNumeric('cita')->name('citas.cancelar');
        Route::post('/citas/{cita}/cancelar', [CitaController::class, 'cancelStore'])->whereAlphaNumeric('cita')->name('citas.cancelar.store');
        Route::get('/citas/{cita}/inasistencia', [CitaController::class, 'absence'])->whereAlphaNumeric('cita')->name('citas.inasistencia');
        Route::post('/citas/{cita}/inasistencia', [CitaController::class, 'absenceStore'])->whereAlphaNumeric('cita')->name('citas.inasistencia.store');
        Route::get('/citas/{cita}/reprogramar', [CitaController::class, 'reschedule'])->whereAlphaNumeric('cita')->name('citas.reprogramar');
        Route::post('/citas/{cita}/reprogramar', [CitaController::class, 'rescheduleStore'])->whereAlphaNumeric('cita')->name('citas.reprogramar.store');
        Route::get('/derivaciones', [SecretariaDerivacionController::class, 'index'])->name('derivaciones.index');
        Route::get('/derivaciones/{derivacion}', [SecretariaDerivacionController::class, 'show'])->whereAlphaNumeric('derivacion')->name('derivaciones.show');
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
        Route::get('/colas', [ColaController::class, 'index'])->name('colas.index');

        Route::prefix('bitacoras')->name('bitacoras.')->controller(BitacoraController::class)->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/{seccion}', 'show')->whereIn('seccion', ['pacientes', 'secretarias', 'medicos'])->name('show');
            Route::get('/{seccion}/pdf', 'pdf')->whereIn('seccion', ['pacientes', 'secretarias', 'medicos'])->middleware('throttle:10,1')->name('pdf');
            Route::get('/{seccion}/perfiles/{codigo}/pdf', 'profilePdf')->whereIn('seccion', ['pacientes', 'secretarias', 'medicos'])->whereAlphaNumeric('codigo')->middleware('throttle:10,1')->name('profile-pdf');
        });

    });
