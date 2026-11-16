@extends('layouts.medico')
@section('medico_title', 'Resumen del día')
@section('medico_content')
    <x-admin.heading title="Resumen del día" :description="'Agenda clínica del '.$fecha.'. Prioriza pacientes que ya registraron su llegada.'">
        <x-admin.button :href="route('medico.agenda.index', ['fecha' => $fecha])">Abrir agenda</x-admin.button>
    </x-admin.heading>

    <section aria-labelledby="resumen-clinico">
        <h2 id="resumen-clinico" class="sr-only">Indicadores del día</h2>
        <div class="a-metrics m-metrics">
            <a href="{{ route('medico.agenda.index', ['fecha' => $fecha]) }}"><dl><dt>Citas de hoy</dt><dd>{{ $resumen['citasHoy'] }}</dd></dl><small>Agenda completa</small></a>
            <a href="{{ route('medico.agenda.index', ['fecha' => $fecha]) }}"><dl><dt>Pacientes en espera</dt><dd>{{ $resumen['enEspera'] }}</dd></dl><small>Requieren atención</small></a>
            <a href="{{ route('medico.historial.index', ['desde' => $fecha, 'hasta' => $fecha]) }}"><dl><dt>Atenciones finalizadas</dt><dd>{{ $resumen['atendidas'] }}</dd></dl><small>Durante el día</small></a>
            <a href="{{ route('medico.derivaciones.index', ['estado' => 'Pendiente']) }}"><dl><dt>Derivaciones pendientes</dt><dd>{{ $resumen['derivacionesPendientes'] }}</dd></dl><small>Por programar</small></a>
        </div>
    </section>

    <section class="a-stack m-agenda-lead" aria-labelledby="agenda-hoy">
        <div class="m-section-heading"><div><h2 id="agenda-hoy">Agenda de hoy</h2><p class="a-help">Las citas están ordenadas por hora de inicio.</p></div><x-admin.button variant="secondary" :href="route('medico.agenda.index', ['fecha' => $fecha])">Consultar otra fecha</x-admin.button></div>
        @include('medico.partials.appointments-table', ['appointments' => $citas, 'overview' => true])
    </section>
@endsection
