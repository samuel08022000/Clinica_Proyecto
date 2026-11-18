<?php

namespace App\Http\Controllers\Paciente;

use App\Http\Controllers\Controller;
use App\Services\Notifications\AppointmentNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(AppointmentNotificationService $notifications): View
    {
        return view('paciente.notificaciones', ['avisos' => $notifications->patientNotices()]);
    }

    public function retry(string $cita, AppointmentNotificationService $notifications): RedirectResponse
    {
        $notifications->retryConfirmation($cita);

        return redirect()->route('paciente.citas.show', $cita)
            ->with('success', 'Se solicitó un reintento del correo. No se creó otra cita.');
    }
}
