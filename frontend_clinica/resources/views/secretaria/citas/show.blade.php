@extends('layouts.secretaria')
@section('reception_title', 'Detalle de cita')
@section('breadcrumb')
    <a href="{{ route('secretaria.citas.index') }}">Gestión de citas</a><span aria-hidden="true">/</span>{{ $appointment['codCit'] }}
@endsection
@section('reception_content')
    <x-admin.heading :title="$appointment['codCit']" description="Información administrativa de la cita." />
    <section class="a-panel"><h2>Detalle de la reserva</h2><x-appointment-summary :appointment="$appointment" /></section>
    @if(session('appointment_result'))
        <section class="a-panel"><h2>Resultado de la operación</h2><dl class="a-details">
            @foreach(['estado' => 'Estado informado', 'motivoCancelacion' => 'Motivo de cancelación', 'canceladoPorUsu' => 'Cancelado por', 'canceladoEn' => 'Fecha de cancelación', 'codInc' => 'Incidencia', 'registradoPorUsu' => 'Registrado por', 'fechaRegistro' => 'Fecha de registro', 'motivo' => 'Motivo administrativo'] as $key => $label)
                @if(filled(session('appointment_result.'.$key)))<div><dt>{{ $label }}</dt><dd>{{ session('appointment_result.'.$key) }}</dd></div>@endif
            @endforeach
        </dl></section>
    @endif
    <section class="a-stack"><h2>Recepción y asistencia</h2>
        <p>{{ $attendance['checkinRegistrado'] ? 'Llegada registrada: '.$attendance['horaLlegadaCheckin'] : 'No hay check-in registrado.' }}</p>
        <div class="a-actions">
            <x-admin.button variant="secondary" :href="route('secretaria.citas.asistencia', $appointment['codCit'])">Consultar asistencia</x-admin.button>
            <x-admin.button variant="secondary" :href="route('secretaria.citas.acreditacion', $appointment['codCit'])">Consultar acreditación</x-admin.button>
            <x-admin.button variant="text" :href="route('secretaria.historial.show', $appointment['codPac'])">Historial del paciente</x-admin.button>
        </div>
        <p class="a-help">{{ $appointment['qrDisponible'] ? 'La cita dispone de QR. Su contenido no se entrega en esta consulta.' : 'La cita no tiene un QR disponible.' }}</p>
    </section>
    <section class="a-sensitive a-stack"><h2>Gestión de incidencias</h2>
        <div class="a-actions">
            @if($canCancel)<x-admin.button variant="secondary" :href="route('secretaria.citas.cancelar', $appointment['codCit'])">Cancelar cita</x-admin.button>@else<p class="a-help">La cancelación no está disponible para el estado o asistencia actual.</p>@endif
            @if($canMarkAbsence)<x-admin.button variant="secondary" :href="route('secretaria.citas.inasistencia', $appointment['codCit'])">Registrar inasistencia</x-admin.button>@else<p class="a-help">La inasistencia requiere una cita confirmada, finalizada y sin check-in.</p>@endif
        </div>
    </section>
@endsection
