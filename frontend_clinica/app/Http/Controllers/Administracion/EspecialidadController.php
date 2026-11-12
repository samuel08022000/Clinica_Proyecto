<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Requests\Administracion\EspecialidadRequest;
use App\Http\Requests\Administracion\EstadoRequest;
use Illuminate\Http\Request;

class EspecialidadController extends AdminController
{
    public function index(Request $request)
    {
        $filters = $request->validate(['estado' => ['nullable', 'in:Activo,Inactivo,Todos'], 'q' => ['nullable', 'string', 'max:120']]);
        $rows = $this->api->administrative('GET', 'personal/especialidades', ['estado' => $filters['estado'] ?? 'Todos']);
        if (filled($filters['q'] ?? null)) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => str_contains(mb_strtolower($row['nombreEspecialidad']), mb_strtolower($filters['q']))));
        }

        return view('administracion.especialidades.index', compact('rows'));
    }

    public function create()
    {
        return view('administracion.especialidades.form', ['record' => []]);
    }

    public function store(EspecialidadRequest $request)
    {
        $data = $request->validated();
        $data['duracionConsultaMin'] = (int) $data['duracionConsultaMin'];

        return $this->save('POST', 'personal/especialidades', $data, 'administracion.especialidades.index');
    }

    public function show(string $codigo)
    {
        $record = $this->api->administrative('GET', 'personal/especialidades/'.$codigo);
        $doctors = $this->api->administrative('GET', 'personal/especialidades/'.$codigo.'/medicos');

        return view('administracion.especialidades.show', compact('record', 'doctors'));
    }

    public function edit(string $codigo)
    {
        return view('administracion.especialidades.form', ['record' => $this->api->administrative('GET', 'personal/especialidades/'.$codigo)]);
    }

    public function update(EspecialidadRequest $request, string $codigo)
    {
        return $this->save('PUT', 'personal/especialidades/'.$codigo, $request->validated(), 'administracion.especialidades.show', ['codigo' => $codigo]);
    }

    public function estado(EstadoRequest $request, string $codigo)
    {
        return $this->save('PUT', 'personal/especialidades/'.$codigo.'/estado', $request->safe()->only('estado'), 'administracion.especialidades.show', ['codigo' => $codigo]);
    }
}
