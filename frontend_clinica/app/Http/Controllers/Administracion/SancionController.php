<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Requests\Administracion\SancionRequest;

class SancionController extends AdminController
{
    public function create(string $paciente)
    {
        return view('administracion.sanciones.form', ['record' => $this->api->administrative('GET', 'administracion/pacientes/'.$paciente)]);
    }

    public function store(SancionRequest $request, string $paciente)
    {
        return $this->save('POST', 'administracion/pacientes/'.$paciente.'/sanciones', [
            'motivo' => $request->validated('motivo'), 'dias' => (int) $request->validated('dias'),
        ], 'administracion.pacientes.show', ['paciente' => $paciente]);
    }

    public function show(string $paciente, string $sancion)
    {
        return view('administracion.sanciones.show', [
            'record' => $this->api->administrative('GET', 'administracion/pacientes/'.$paciente.'/sanciones/'.$sancion),
        ]);
    }
}
