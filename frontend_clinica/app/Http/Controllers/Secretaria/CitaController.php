<?php

namespace App\Http\Controllers\Secretaria;

use App\Http\Requests\Secretaria\AppointmentActionRequest;
use App\Http\Requests\Secretaria\AppointmentFiltersRequest;
use App\Support\AppointmentPresentation;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CitaController extends ReceptionController
{
    public function index(AppointmentFiltersRequest $request): View
    {
        $filters = $request->filters();

        return view('secretaria.citas.index', ['page' => $this->read('citas', $filters), 'filters' => $filters]);
    }

    public function show(string $cita): View
    {
        return view('secretaria.citas.show', $this->details($cita));
    }

    public function cancel(string $cita): View
    {
        return view('secretaria.citas.action', $this->details($cita) + ['action' => 'cancelar']);
    }

    public function absence(string $cita): View
    {
        return view('secretaria.citas.action', $this->details($cita) + ['action' => 'inasistencia']);
    }

    public function cancelStore(AppointmentActionRequest $request, string $cita): RedirectResponse
    {
        $result = $this->write('citas/'.$cita.'/cancelar', $request->safe()->only('motivo'), 'Cancelada');

        return redirect()->route('secretaria.citas.show', $cita)->with('success', 'La cancelación fue registrada.')->with('appointment_result', $result);
    }

    public function absenceStore(AppointmentActionRequest $request, string $cita): RedirectResponse
    {
        // The API accepts an empty object; no actor or manual timestamp is forwarded.
        $result = $this->write('citas/'.$cita.'/inasistencia', [], 'No Asistió');

        return redirect()->route('secretaria.citas.show', $cita)->with('success', 'La inasistencia fue registrada.')->with('appointment_result', $result);
    }

    private function details(string $cita): array
    {
        $appointment = $this->read('citas/'.$cita);
        $attendance = $this->read('citas/'.$cita.'/asistencia');

        return compact('appointment', 'attendance') + ['canCancel' => AppointmentPresentation::canCancel($appointment, $attendance),
            'canMarkAbsence' => AppointmentPresentation::canMarkAbsence($appointment, $attendance)];
    }
}
