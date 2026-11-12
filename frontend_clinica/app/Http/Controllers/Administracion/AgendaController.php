<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Requests\Administracion\DuracionRequest;
use App\Http\Requests\Administracion\EstadoRequest;
use App\Http\Requests\Administracion\HorarioRequest;
use Illuminate\Http\Request;

class AgendaController extends AdminController
{
    public function index(Request $request)
    {
        $request->validate(['medico' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]+$/', 'max:20']]);
        $doctors = $this->api->administrative('GET', 'personal/medicos');
        $doctor = $request->filled('medico') ? $this->api->administrative('GET', 'personal/medicos/'.$request->query('medico')) : null;
        $schedules = $doctor ? $this->api->administrative('GET', 'administracion/medicos/'.$doctor['codMed'].'/horarios') : [];

        return view('administracion.agendas.index', compact('doctors', 'doctor', 'schedules'));
    }

    public function create(string $medico)
    {
        return $this->form($medico);
    }

    public function edit(string $medico, string $horario)
    {
        return $this->form($medico, $horario);
    }

    private function form(string $medico, ?string $horario = null)
    {
        $doctor = $this->api->administrative('GET', 'personal/medicos/'.$medico);
        $record = [];
        if ($horario) {
            $rows = $this->api->administrative('GET', 'administracion/medicos/'.$medico.'/horarios');
            $record = collect($rows)->firstWhere('codHor', $horario);
            abort_unless($record, 404);
        }

        return view('administracion.agendas.form', compact('doctor', 'record'));
    }

    public function store(HorarioRequest $request, string $medico)
    {
        $data = $request->validated();
        $data['diaSemana'] = (int) $data['diaSemana'];

        return $this->save('POST', 'administracion/medicos/'.$medico.'/horarios', $data, 'administracion.agendas.index', ['medico' => $medico]);
    }

    public function update(HorarioRequest $request, string $medico, string $horario)
    {
        $this->assertSchedule($medico, $horario);
        $data = $request->validated();
        $data['diaSemana'] = (int) $data['diaSemana'];

        return $this->save('PUT', 'administracion/horarios/'.$horario, $data, 'administracion.agendas.index', ['medico' => $medico]);
    }

    public function estado(EstadoRequest $request, string $medico, string $horario)
    {
        $this->assertSchedule($medico, $horario);

        return $this->save('PUT', 'administracion/horarios/'.$horario.'/estado', $request->safe()->only('estado'), 'administracion.agendas.index', ['medico' => $medico]);
    }

    private function assertSchedule(string $medico, string $horario): void
    {
        $rows = $this->api->administrative('GET', 'administracion/medicos/'.$medico.'/horarios');
        abort_unless(collect($rows)->contains('codHor', $horario), 404);
    }

    public function duracion(DuracionRequest $request, string $medico)
    {
        return $this->save('PUT', 'administracion/medicos/'.$medico.'/duracion', ['duracionConsultaMin' => (int) $request->validated('duracionConsultaMin')],
            'administracion.agendas.index', ['medico' => $medico]);
    }
}
