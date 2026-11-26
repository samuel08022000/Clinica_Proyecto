@extends('layouts.medico')
@section('medico_title', 'Resumen del día')
@section('medico_content')
    <x-admin.heading title="Resumen del día" :description="ucfirst(\Illuminate\Support\Carbon::parse($fecha)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY'))" />

    <details class="m-summary">
        <summary><span>Indicadores del día</span><strong>{{ $resumen['enEspera'] }} en espera · {{ $resumen['enAtencion'] }} en atención</strong></summary>
        <div class="a-metrics m-metrics">
            <a href="{{ route('medico.agenda.index', ['fecha' => $fecha]) }}"><dl><dt>Citas de hoy</dt><dd>{{ $resumen['citasHoy'] }}</dd></dl><small>Agenda completa</small></a>
            <a href="{{ route('medico.agenda.index', ['fecha' => $fecha]) }}"><dl><dt>Pacientes en espera</dt><dd>{{ $resumen['enEspera'] }}</dd></dl><small>Requieren atención</small></a>
            <a href="{{ route('medico.agenda.index', ['fecha' => $fecha]) }}"><dl><dt>En atención</dt><dd>{{ $resumen['enAtencion'] }}</dd></dl><small>Consultas iniciadas</small></a>
            <a href="{{ route('medico.historial.index', ['desde' => $fecha, 'hasta' => $fecha]) }}"><dl><dt>Atenciones finalizadas</dt><dd>{{ $resumen['atendidas'] }}</dd></dl><small>Durante el día</small></a>
            <a href="{{ route('medico.derivaciones.index', ['estado' => 'Pendiente']) }}"><dl><dt>Derivaciones pendientes</dt><dd>{{ $resumen['derivacionesPendientes'] }}</dd></dl><small>Por programar</small></a>
        </div>
    </details>

    <section class="a-stack m-agenda-lead" aria-labelledby="agenda-hoy">
        <div class="m-section-heading m-agenda-heading"><h2 id="agenda-hoy">Agenda de hoy</h2><a href="{{ route('medico.agenda.index', ['fecha' => $fecha]) }}">Cambiar fecha</a></div>
        @include('medico.partials.appointments-table', ['appointments' => $citas, 'overview' => true])
    </section>
@endsection
