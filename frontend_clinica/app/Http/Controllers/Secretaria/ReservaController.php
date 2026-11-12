<?php

namespace App\Http\Controllers\Secretaria;

use App\Exceptions\QuarkusApiException;
use App\Http\Requests\Secretaria\ReservationBrowseRequest;
use App\Http\Requests\Secretaria\ReservationRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

class ReservaController extends ReceptionController
{
    public function create(ReservationBrowseRequest $request): View
    {
        $filters = array_filter($request->validated(), fn ($value) => $value !== null && $value !== '');
        $patient = isset($filters['codPac']) ? $this->read('pacientes/'.$filters['codPac']) : null;
        $patients = ! $patient && isset($filters['q']) ? $this->read('pacientes', ['q' => $filters['q']])['pacientes'] : null;
        $specialties = $patient && $patient['habilitadoParaReservar'] ? $this->read('especialidades') : [];
        $specialty = collect($specialties)->firstWhere('codEsp', $filters['codEsp'] ?? null);
        $doctors = $specialty ? $this->read('medicos', ['codEsp' => $specialty['codEsp']]) : [];
        $slots = [];
        $selected = null;
        $message = null;
        if ($specialty && count($doctors) && isset($filters['fecha'])) {
            // A stale doctor from another specialty is not silently sent to availability.
            if (isset($filters['codMed']) && ! collect($doctors)->contains('codMed', $filters['codMed'])) {
                unset($filters['codMed']);
            }
            try {
                $slots = $this->read('disponibilidad', array_intersect_key($filters, array_flip(['codEsp', 'codMed', 'fecha', 'codPac'])));
                if (isset($filters['codSlot'])) {
                    $selected = collect($slots)->firstWhere('codSlot', $filters['codSlot']);
                    if (! $selected) {
                        $message = 'El turno seleccionado ya no está disponible. Elige otro horario.';
                    }
                }
            } catch (QuarkusApiException $exception) {
                if (! in_array($exception->status, [400, 409, 422], true)) {
                    throw $exception;
                }
                $message = $exception->getMessage();
            }
        }
        // Browser hints use the Laravel server in the clinic zone. PostgreSQL owns D..D+14.
        $today = CarbonImmutable::now('America/La_Paz')->toDateString();
        $lastDay = CarbonImmutable::now('America/La_Paz')->addDays(14)->toDateString();
        $view = view($selected ? 'secretaria.reservas.confirm' : 'secretaria.reservas.create',
            compact('patient', 'patients', 'specialties', 'specialty', 'doctors', 'slots', 'selected', 'filters', 'today', 'lastDay'));
        if ($message) {
            $view->with('errors', (new ViewErrorBag)->put('default', new MessageBag(['api' => $message])));
        }

        return $view;
    }

    public function store(ReservationRequest $request): RedirectResponse
    {
        $data = $request->validated();
        try {
            $appointment = $this->write('citas', array_intersect_key($data, array_flip(['codPac', 'codSlot'])), 'Confirmada');
        } catch (QuarkusApiException $exception) {
            if (! in_array($exception->status, [400, 409, 422, 502, 503], true)) {
                throw $exception;
            }

            // Re-query slots on the redirected GET; never keep the rejected slot selected.
            return redirect()->route('secretaria.reservas.create', array_intersect_key($data, array_flip(['codPac', 'codEsp', 'codMed', 'fecha'])))
                ->withErrors(['api' => $exception->status >= 500
                    ? 'No se pudo confirmar el resultado de la reserva. Consulta las citas del paciente antes de reintentar.' : $exception->getMessage()])
                ->with('api_status', $exception->status);
        }

        return redirect()->route('secretaria.citas.show', $appointment['codCit'])->with('success', 'Reserva confirmada por el servicio de citas.');
    }
}
