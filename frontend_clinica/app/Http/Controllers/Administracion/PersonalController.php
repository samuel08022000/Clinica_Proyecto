<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Requests\Administracion\EstadoRequest;
use App\Http\Requests\Administracion\PersonalRequest;
use Illuminate\Http\Request;

abstract class PersonalController extends AdminController
{
    protected string $resource;

    public function index(Request $request)
    {
        $filters = $request->validate(['estado' => ['nullable', 'in:Activo,Inactivo'], 'q' => ['nullable', 'string', 'max:150']]);
        $rows = $this->api->administrative('GET', 'personal/'.$this->resource, ['estado' => $filters['estado'] ?? null]);
        // Staff APIs expose state only. Search is explicitly scoped to the returned catalog.
        $term = mb_strtolower($filters['q'] ?? '');
        if ($term !== '') {
            $rows = array_values(array_filter($rows, fn (array $row): bool => str_contains(mb_strtolower(implode(' ', [
                $row['nombreCompleto'], $row['email'], $row['ci'] ?? '', $row['codMed'] ?? $row['codSec'],
            ])), $term)));
        }

        return view('administracion.personal.index', ['rows' => $rows, 'resource' => $this->resource]);
    }

    public function create()
    {
        return $this->form([]);
    }

    public function store(PersonalRequest $request)
    {
        return $this->save('POST', 'personal/'.$this->resource, $this->payload($request), 'administracion.'.$this->resource.'.index');
    }

    public function show(string $codigo)
    {
        return view('administracion.personal.show', [
            'record' => $this->api->administrative('GET', 'personal/'.$this->resource.'/'.$codigo),
            'resource' => $this->resource,
        ]);
    }

    public function edit(string $codigo)
    {
        return $this->form($this->api->administrative('GET', 'personal/'.$this->resource.'/'.$codigo));
    }

    public function update(PersonalRequest $request, string $codigo)
    {
        return $this->save('PUT', 'personal/'.$this->resource.'/'.$codigo, $this->payload($request),
            'administracion.'.$this->resource.'.show', ['codigo' => $codigo]);
    }

    public function estado(EstadoRequest $request, string $codigo)
    {
        return $this->save('PUT', 'personal/'.$this->resource.'/'.$codigo.'/estado', $request->safe()->only('estado'),
            'administracion.'.$this->resource.'.show', ['codigo' => $codigo]);
    }

    private function payload(PersonalRequest $request): array
    {
        $data = $request->validated();
        if (empty($data['password'])) {
            unset($data['password']);
        }
        if (isset($data['duracionConsultaMin'])) {
            $data['duracionConsultaMin'] = (int) $data['duracionConsultaMin'];
        }

        return $data;
    }

    private function form(array $record)
    {
        $specialties = $this->resource === 'medicos'
            ? $this->api->administrative('GET', 'personal/especialidades') : [];
        $selected = [];
        // The backend permits keeping existing active links to an inactive specialty.
        foreach ($record['especialidades'] ?? [] as $specialty) {
            if ($specialty['estado'] === 'Activo') {
                $selected[] = $specialty['codEsp'];
                if (! in_array($specialty['codEsp'], array_column($specialties, 'codEsp'), true)) {
                    $specialties[] = $specialty + ['soloConservar' => true];
                }
            }
        }

        return view('administracion.personal.form', [
            'record' => $record, 'resource' => $this->resource, 'specialties' => $specialties, 'selected' => $selected,
        ]);
    }
}
