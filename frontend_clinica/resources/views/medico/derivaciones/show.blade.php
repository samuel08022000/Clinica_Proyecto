@extends('layouts.medico')
@section('medico_title', 'Detalle de derivación')
@section('breadcrumb')
    <a href="{{ route('medico.derivaciones.index') }}">Derivaciones</a><span aria-hidden="true">/</span>{{ $derivacion['codDer'] }}
@endsection
@section('medico_content')
    <x-admin.heading :title="$derivacion['codDer']" description="Trazabilidad administrativa de la derivación.">
        <x-admin.button variant="secondary" :href="route('medico.citas.show', $derivacion['codCitOrigen'])">Ver cita origen</x-admin.button>
    </x-admin.heading>
    <section class="a-panel"><h2>Origen y paciente</h2><dl class="a-details">
        <div><dt>Cita origen</dt><dd>{{ $derivacion['codCitOrigen'] }}</dd></div>
        <div><dt>Paciente</dt><dd>{{ $derivacion['nombrePaciente'] }}<br><span class="a-muted">{{ $derivacion['codPac'] }}</span></dd></div>
        <div><dt>Médico emisor</dt><dd>{{ $derivacion['nombreMedicoEmisor'] }}</dd></div>
        <div><dt>Fecha de derivación</dt><dd>{{ $derivacion['fechaDerivacion'] }}</dd></div>
    </dl></section>
    <section class="a-panel"><h2>Destino y programación</h2><dl class="a-details">
        <div><dt>Especialidad</dt><dd>{{ $derivacion['nombreEspecialidad'] }}</dd></div>
        <div><dt>Médico destinatario</dt><dd>{{ $derivacion['nombreMedicoDestino'] ?? 'Cualquier médico habilitado de la especialidad' }}</dd></div>
        <div><dt>Estado</dt><dd><x-admin.badge :value="$derivacion['estado']" /></dd></div>
        <div><dt>Cita hija vigente</dt><dd>{{ $derivacion['codCitHija'] ?? 'Secretaría todavía no la programó' }}</dd></div>
    </dl></section>
    @if($derivacion['estado'] === 'Pendiente')<x-admin.notice>La derivación ya está disponible para que Secretaría seleccione un slot compatible. El módulo Médico no crea la cita hija.</x-admin.notice>@endif
@endsection
