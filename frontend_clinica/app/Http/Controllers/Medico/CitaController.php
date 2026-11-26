<?php

namespace App\Http\Controllers\Medico;

use App\Http\Controllers\Controller;
use App\Http\Requests\Medico\FinishAppointmentRequest;
use App\Services\Medico\MedicoAnalisisService;
use App\Services\Medico\MedicoAtencionService;
use App\Services\Medico\MedicoDerivacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class CitaController extends Controller
{
    public function __construct(
        private MedicoAtencionService $appointments,
        private MedicoDerivacionService $referrals,
        private MedicoAnalisisService $analyses,
    ) {}

    public function show(string $cita): View
    {
        $appointment = $this->appointments->appointment($cita);
        $canAttend = $appointment['estado'] === 'En Espera' && $appointment['checkinRegistrado']
            && $appointment['fechaAtencion'] === null && $appointment['atendidaPorUsu'] === null;

        return view('medico.citas.show', [
            'appointment' => $appointment,
            'derivaciones' => $this->referrals->forAppointment($cita),
            'analisis' => $this->analyses->listing($cita),
            'canStart' => $canAttend && $appointment['horaInicioAtencion'] === null,
            'canFinish' => $canAttend && $appointment['horaInicioAtencion'] !== null,
            'canRefer' => $appointment['estado'] === 'Atendida',
            'canRequestAnalysis' => in_array($appointment['estado'], ['En Espera', 'Atendida'], true),
        ]);
    }

    public function start(string $cita): RedirectResponse
    {
        $this->appointments->start($cita);

        return redirect()->route('medico.citas.show', $cita)->with('success', 'Se registró el inicio de la atención.');
    }

    public function finish(FinishAppointmentRequest $request, string $cita): RedirectResponse
    {
        $request->validate(['confirmacion' => ['required', 'accepted']]);
        $this->appointments->finish($cita);

        return redirect()->route('medico.citas.show', $cita)
            ->with('success', 'La atención fue finalizada y auditada correctamente.');
    }
}
