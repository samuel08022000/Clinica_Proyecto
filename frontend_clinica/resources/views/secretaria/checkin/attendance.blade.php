@extends('layouts.secretaria')
@section('reception_title', 'Asistencia')
@section('breadcrumb')
    <a href="{{ route('secretaria.citas.show', $attendance['codCit']) }}">{{ $attendance['codCit'] }}</a><span aria-hidden="true">/</span>Asistencia
@endsection
@section('reception_content')
    <x-admin.heading title="Asistencia de la cita" :description="$attendance['codCit']" />
    <x-admin.notice :type="$attendance['checkinRegistrado'] ? 'success' : 'warning'">{{ $attendance['checkinRegistrado'] ? 'Llegada acreditada mediante check-in.' : 'La cita no tiene una llegada registrada.' }}</x-admin.notice>
    <dl class="a-details">
        <div><dt>Paciente</dt><dd>{{ $attendance['nombrePaciente'] }}</dd></div><div><dt>Fecha de cita</dt><dd>{{ $attendance['fecha'] }}</dd></div><div><dt>Estado</dt><dd><x-appointment-status :value="$attendance['estado']" /></dd></div>
        <div><dt>Hora de llegada</dt><dd>{{ $attendance['horaLlegadaCheckin'] ?? 'Sin registro' }}</dd></div><div><dt>Responsable del check-in</dt><dd>{{ $attendance['checkinPorUsu'] ?? 'Sin registro' }}</dd></div>
    </dl>
    <div class="a-actions"><x-admin.button :href="route('secretaria.checkin.index')">Registrar otra llegada</x-admin.button><x-admin.button variant="secondary" :href="route('secretaria.citas.show', $attendance['codCit'])">Ver cita</x-admin.button></div>
@endsection
