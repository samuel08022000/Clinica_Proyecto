@extends('layouts.secretaria')
@section('reception_title', 'Detalle de derivación')
@section('breadcrumb')
    <a href="{{ route('secretaria.derivaciones.index') }}">Derivaciones</a><span aria-hidden="true">/</span>{{ $derivacion['codDer'] }}
@endsection
@section('reception_content')
    <x-clinical-progress :state="$derivacion['estado']" :referral="true" />
    <x-admin.heading :title="$derivacion['codDer']" description="Datos administrativos necesarios para programar la cita hija.">
        @if($derivacion['estado'] === 'Pendiente' && empty($derivacion['codCitHija']))
            <x-admin.button :href="route('secretaria.reservas.create', ['codDer' => $derivacion['codDer']])">Programar cita hija</x-admin.button>
        @endif
    </x-admin.heading>
    <div class="a-columns">
        <section class="a-panel"><h2>Origen</h2><dl class="a-details">
            <div><dt>Paciente</dt><dd>{{ $derivacion['nombrePaciente'] }}<br><span class="a-muted">{{ $derivacion['codPac'] }}</span></dd></div>
            <div><dt>Cita origen</dt><dd>{{ $derivacion['codCitOrigen'] }}</dd></div>
            <div><dt>Médico emisor</dt><dd>{{ $derivacion['nombreMedicoEmisor'] }}</dd></div>
            <div><dt>Fecha</dt><dd>{{ $derivacion['fechaDerivacion'] }}</dd></div>
        </dl></section>
        <section class="a-panel"><h2>Destino y programación</h2><dl class="a-details">
            <div><dt>Especialidad</dt><dd>{{ $derivacion['nombreEspecialidadDestino'] }}</dd></div>
            <div><dt>Médico destinatario</dt><dd>{{ $derivacion['nombreMedicoDestino'] ?? 'Cualquier médico habilitado de la especialidad' }}</dd></div>
            <div><dt>Estado</dt><dd><x-admin.badge :value="$derivacion['estado']" /></dd></div>
            <div><dt>Cita hija vigente</dt><dd>{{ $derivacion['codCitHija'] ?? 'Pendiente de programación' }}</dd></div>
        </dl></section>
    </div>
    @if($derivacion['estado'] !== 'Pendiente' || filled($derivacion['codCitHija']))
        <x-admin.notice>El ticket ya no admite una nueva reserva. La cita hija vigente permanece enlazada para trazabilidad.</x-admin.notice>
    @endif
@endsection
