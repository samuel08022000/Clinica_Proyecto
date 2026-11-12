<?php

namespace App\Http\Controllers\Secretaria;

use App\Http\Requests\Secretaria\AppointmentFiltersRequest;
use Illuminate\View\View;

class HistorialController extends ReceptionController
{
    public function show(AppointmentFiltersRequest $request, string $paciente): View
    {
        $filters = $request->filters();

        return view('secretaria.historial.show', ['patient' => $this->read('pacientes/'.$paciente),
            'page' => $this->read('pacientes/'.$paciente.'/historial', $filters), 'filters' => $filters]);
    }
}
