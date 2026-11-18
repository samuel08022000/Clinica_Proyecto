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
        $mailState = $notifications->sendConfirmation($cita);

        $response = redirect()->route('paciente.citas.show', $cita);

        return $mailState === 'Enviada'
            ? $response->with('success', 'El correo con tu pase QR fue aceptado por el transporte. No se creó otra cita.')
            : $response->withErrors(['correoQr' => 'No se pudo confirmar el envío. Tu QR sigue disponible y la cita se conserva. Espera la cuenta regresiva antes de reintentar.']);
    }
}
