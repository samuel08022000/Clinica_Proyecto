<?php

namespace App\Http\Controllers\Paciente;

use App\Contracts\PatientAppointmentManager;
use App\Contracts\PatientProfileProvider;
use App\Http\Controllers\Controller;
use App\Support\PatientAppointmentPresentation;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __construct(
        private PatientProfileProvider $profiles,
        private PatientAppointmentManager $appointments,
    ) {}

    public function __invoke(): View
    {
        $profile = $this->profiles->profile();
        $upcoming = $this->appointments->list([
            'alcance' => 'proximas',
            'page' => 0,
            'size' => 1,
        ]);
        $attended = $this->appointments->list([
            'alcance' => 'todas',
            'estado' => 'Atendida',
            'page' => 0,
            'size' => 50,
        ]);
        $next = $upcoming['citas'][0] ?? null;

        return view('paciente.home', [
            'nombre' => $profile['nombreCompleto'],
            'proxima' => $next === null ? null : PatientAppointmentPresentation::decorate($next),
            'totalProximas' => $profile['resumen']['citasProximas'],
            'totalAtendidas' => count($attended['citas']).($attended['hayMas'] ? '+' : ''),
        ]);
    }
}
