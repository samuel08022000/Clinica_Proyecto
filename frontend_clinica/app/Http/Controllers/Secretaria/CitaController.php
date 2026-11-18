<?php

namespace App\Http\Controllers\Secretaria;

use App\Exceptions\ClinicalOperationException;
use App\Http\Requests\Secretaria\AppointmentActionRequest;
use App\Http\Requests\Secretaria\AppointmentFiltersRequest;
use App\Http\Requests\Secretaria\RescheduleRequest;
use App\Http\Requests\Secretaria\ReservationBrowseRequest;
use App\Support\AppointmentPresentation;
use Carbon\CarbonImmutable;
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

    public function rescheduleStore(RescheduleRequest $request, string $cita): RedirectResponse
    {
        $result = $this->write('citas/'.$cita.'/reprogramar', $request->safe()->only(['codSlot', 'motivo']), 'Confirmada');

        return redirect()->route('secretaria.citas.show', $result['codCit'])
            ->with('success', 'La cita fue reprogramada conservando su trazabilidad.')
            ->with('appointment_result', $result);
    }

    public function reschedule(ReservationBrowseRequest $request, string $cita): View
    {
        $appointment = $this->read('citas/'.$cita);
        if (! in_array($appointment['estado'], ['Confirmada', 'Afectada/Pendiente Reprogramación'], true)) {
            throw new ClinicalOperationException(409, 'La cita ya no admite reprogramación. Actualiza su detalle.', 'CONFLICTO');
        }
        $filters = $request->validated();
        $doctors = $this->read('medicos', ['codEsp' => $appointment['codEsp']]);
        $slots = isset($filters['fecha']) ? $this->read('disponibilidad', array_filter([
            'codPac' => $appointment['codPac'], 'codEsp' => $appointment['codEsp'],
            'codMed' => $filters['codMed'] ?? null, 'fecha' => $filters['fecha'],
        ])) : [];
        $today = CarbonImmutable::now('America/La_Paz');

        return view('secretaria.citas.reprogramar', compact('appointment', 'doctors', 'slots', 'filters') + [
            'today' => $today->toDateString(), 'lastDay' => $today->addDays(14)->toDateString(),
        ]);
    }

    private function details(string $cita): array
    {
        $appointment = $this->read('citas/'.$cita);
        $attendance = $this->read('citas/'.$cita.'/asistencia');

        return compact('appointment', 'attendance') + ['canCancel' => AppointmentPresentation::canCancel($appointment, $attendance),
            'canMarkAbsence' => AppointmentPresentation::canMarkAbsence($appointment, $attendance)];
    }
}
