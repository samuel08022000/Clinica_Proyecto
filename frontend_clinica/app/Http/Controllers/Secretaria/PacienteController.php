<?php

namespace App\Http\Controllers\Secretaria;

use App\Http\Requests\Secretaria\PatientSearchRequest;
use Illuminate\View\View;

class PacienteController extends ReceptionController
{
    public function index(PatientSearchRequest $request): View
    {
        $q = $request->validated('q');
        $patients = filled($q) ? $this->read('pacientes', ['q' => $q])['pacientes'] : null;
        $history = $request->routeIs('secretaria.historial.index');

        return view('secretaria.pacientes.index', compact('q', 'patients', 'history'));
    }

    public function show(string $paciente): View
    {
        return view('secretaria.pacientes.show', ['patient' => $this->read('pacientes/'.$paciente)]);
    }
}
