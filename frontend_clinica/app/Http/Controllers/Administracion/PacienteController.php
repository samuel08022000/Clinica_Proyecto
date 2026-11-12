<?php

namespace App\Http\Controllers\Administracion;

use Illuminate\Http\Request;

class PacienteController extends AdminController
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'min:3', 'max:100', function ($attribute, $value, $fail): void {
                if (preg_match_all('/[\p{L}\p{N}]/u', $value) < 3) {
                    $fail('La búsqueda requiere al menos tres letras o números.');
                }
            }],
            'estado' => ['nullable', 'in:Activo,Inactivo,Bloqueado_Temporal'],
            'page' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $page = $this->api->administrative('GET', 'administracion/pacientes', $filters + ['page' => 0, 'size' => 20]);
        $sanctions = $request->routeIs('administracion.sanciones.index');

        return view('administracion.pacientes.index', compact('page', 'sanctions'));
    }

    public function show(Request $request, string $paciente)
    {
        $filters = $request->validate([
            'incidencias_page' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'sanciones_page' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'tipo' => ['nullable', 'in:CANCELACION,INASISTENCIA_NO_SHOW,SANCION_BLOQUEO,OTRA'],
            'vigente' => ['nullable', 'in:true,false'],
        ]);
        $record = $this->api->administrative('GET', 'administracion/pacientes/'.$paciente);
        $incidents = $this->api->administrative('GET', 'administracion/pacientes/'.$paciente.'/incidencias', [
            'page' => $filters['incidencias_page'] ?? 0, 'size' => 20, 'tipo' => $filters['tipo'] ?? null,
        ]);
        $sanctions = $this->api->administrative('GET', 'administracion/pacientes/'.$paciente.'/sanciones', [
            'page' => $filters['sanciones_page'] ?? 0, 'size' => 20, 'vigente' => $filters['vigente'] ?? null,
        ]);

        return view('administracion.pacientes.show', compact('record', 'incidents', 'sanctions'));
    }
}
