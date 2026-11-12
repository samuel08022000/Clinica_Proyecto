<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Requests\Administracion\BloqueoRequest;
use Illuminate\Http\Request;

class BloqueoController extends AdminController
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'medico' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]+$/', 'max:20'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
        $doctors = $this->api->administrative('GET', 'personal/medicos');
        $doctor = $request->filled('medico') ? $this->api->administrative('GET', 'personal/medicos/'.$filters['medico']) : null;
        $rows = $doctor ? $this->api->administrative('GET', 'administracion/medicos/'.$doctor['codMed'].'/bloqueos', array_intersect_key($filters, array_flip(['desde', 'hasta']))) : [];

        return view('administracion.bloqueos.index', compact('doctors', 'doctor', 'rows'));
    }

    public function create(string $medico)
    {
        return view('administracion.bloqueos.form', ['doctor' => $this->api->administrative('GET', 'personal/medicos/'.$medico)]);
    }

    public function store(BloqueoRequest $request, string $medico)
    {
        $record = $this->api->administrative('POST', 'administracion/medicos/'.$medico.'/bloqueos', $request->safe()->except('confirmacion'));

        return redirect()->route('administracion.bloqueos.show', ['bloqueo' => $record['codBlo']])->with('success', 'Bloqueo registrado. Revisa las citas afectadas.');
    }

    public function show(string $bloqueo)
    {
        $record = $this->api->administrative('GET', 'administracion/bloqueos/'.$bloqueo);
        // Legacy blocks explicitly lack traceability. Do not invent their affected appointments.
        $affected = $record['trazabilidadCompleta']
            ? $this->api->administrative('GET', 'administracion/bloqueos/'.$bloqueo.'/citas-afectadas') : null;

        return view('administracion.bloqueos.show', compact('record', 'affected'));
    }
}
