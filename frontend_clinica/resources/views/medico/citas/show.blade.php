@extends('layouts.medico')
@section('medico_title', 'Detalle de cita')
@section('breadcrumb')
    <a href="{{ route('medico.agenda.index', ['fecha' => $appointment['fecha']]) }}">Agenda</a><span aria-hidden="true">/</span>{{ $appointment['codCit'] }}
@endsection
@section('medico_content')
    <x-admin.heading :title="$appointment['codCit']" :description="$appointment['nombrePaciente'].' · '.$appointment['nombreEspecialidad']">
        @if($canRefer)<x-admin.button variant="secondary" :href="route('medico.derivaciones.create', $appointment['codCit'])">Emitir derivación</x-admin.button>@endif
        @if($canRequestAnalysis)<x-admin.button variant="secondary" :href="route('medico.analisis.index', $appointment['codCit'])">Gestionar análisis</x-admin.button>@endif
    </x-admin.heading>

    <div class="a-columns m-detail-grid">
        <section class="a-panel"><h2>Datos administrativos</h2><dl class="a-details">
            <div><dt>Paciente</dt><dd>{{ $appointment['nombrePaciente'] }}<br><span class="a-muted">{{ $appointment['codPac'] }}</span></dd></div>
            <div><dt>Especialidad</dt><dd>{{ $appointment['nombreEspecialidad'] }}</dd></div>
            <div><dt>Fecha</dt><dd>{{ $appointment['fecha'] }}</dd></div>
            <div><dt>Horario</dt><dd>{{ substr($appointment['horaInicio'], 0, 5) }}–{{ substr($appointment['horaFin'], 0, 5) }}</dd></div>
            <div><dt>Canal</dt><dd>{{ $appointment['canalReserva'] }}</dd></div>
            <div><dt>Estado</dt><dd><x-appointment-status :value="$appointment['estado']" /></dd></div>
        </dl></section>
        <section class="a-panel"><h2>Asistencia y cierre</h2><dl class="a-details m-single-details">
            <div><dt>Check-in</dt><dd>{{ $appointment['checkinRegistrado'] ? 'Registrado' : 'Pendiente' }}@if($appointment['horaLlegadaCheckin'])<br><span class="a-muted">{{ $appointment['horaLlegadaCheckin'] }}</span>@endif</dd></div>
            <div><dt>Atención</dt><dd>{{ $appointment['fechaAtencion'] ?? 'Sin finalizar' }}@if($appointment['atendidaPorUsu'])<br><span class="a-muted">Responsable: {{ $appointment['atendidaPorUsu'] }}</span>@endif</dd></div>
        </dl>
        @if($canFinish)
            <x-admin.form :action="route('medico.citas.atender', $appointment['codCit'])" class="a-stack m-write-form" confirm="Finalizará la consulta y conservará los sellos del servidor. Esta acción no puede repetirse.">
                <x-admin.confirmation message="Confirmo que la consulta concluyó y deseo marcarla como Atendida." />
                <div class="a-actions"><x-admin.button>Finalizar consulta</x-admin.button></div>
            </x-admin.form>
        @elseif($appointment['estado'] === 'Confirmada')
            <p class="a-help">Secretaría debe registrar el check-in antes de que puedas finalizar la consulta.</p>
        @else
            <p class="a-help">El cierre no está disponible para el estado actual.</p>
        @endif
        </section>
    </div>

    <section class="a-stack" aria-labelledby="derivaciones-cita"><div class="m-section-heading"><div><h2 id="derivaciones-cita">Derivaciones asociadas</h2><p class="a-help">Interconsultas emitidas desde esta atención.</p></div>@if($canRefer)<x-admin.button variant="secondary" :href="route('medico.derivaciones.create', $appointment['codCit'])">Nueva derivación</x-admin.button>@endif</div>
        @if(count($derivaciones))<div class="m-record-list">@foreach($derivaciones as $item)<a href="{{ route('medico.derivaciones.show', $item['codDer']) }}"><span><strong>{{ $item['nombreEspecialidad'] }}</strong><small>{{ $item['nombreMedicoDestino'] ?? 'Sin destinatario específico' }}</small></span><x-admin.badge :value="$item['estado']" /></a>@endforeach</div>@else<x-admin.empty title="Sin derivaciones" description="Esta cita no tiene derivaciones emitidas." />@endif
    </section>

    <section class="a-stack" aria-labelledby="analisis-cita"><div class="m-section-heading"><div><h2 id="analisis-cita">Análisis asociados</h2><p class="a-help">Solicitudes y resultados textuales autorizados.</p></div>@if($canRequestAnalysis)<x-admin.button variant="secondary" :href="route('medico.analisis.index', $appointment['codCit'])">Abrir análisis</x-admin.button>@endif</div>
        @if(count($analisis))<div class="m-record-list">@foreach($analisis as $item)<a href="{{ route('medico.analisis.index', $appointment['codCit']) }}#{{ $item['codAna'] }}"><span><strong>{{ $item['tipoAnalisis'] }}</strong><small>{{ $item['codAna'] }} · {{ $item['fechaRegistro'] }}</small></span><x-admin.badge :value="$item['estado']" /></a>@endforeach</div>@else<x-admin.empty title="Sin análisis" description="No hay solicitudes asociadas a esta cita." />@endif
    </section>
@endsection
