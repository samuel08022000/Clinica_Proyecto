@extends('layouts.secretaria')
@section('reception_title', 'Acreditación de cita')
@section('breadcrumb')
    <a href="{{ route('secretaria.citas.show', $accreditation['codCit']) }}">{{ $accreditation['codCit'] }}</a><span aria-hidden="true">/</span>Acreditación
@endsection
@section('reception_content')
    <x-admin.heading title="Acreditación de cita" :description="$accreditation['codCit']" />
    <dl class="a-details">
        <div><dt>Paciente</dt><dd>{{ $accreditation['nombrePaciente'] }}</dd></div><div><dt>Fecha</dt><dd>{{ $accreditation['fecha'] }}</dd></div><div><dt>Estado</dt><dd><x-appointment-status :value="$accreditation['estado']" /></dd></div>
        <div><dt>QR válido informado</dt><dd>{{ $accreditation['qrValido'] ? 'Sí' : 'No' }}</dd></div><div><dt>Admite check-in</dt><dd>{{ $accreditation['admiteCheckin'] ? 'Sí' : 'No' }}</dd></div>
    </dl>
    <x-admin.notice :type="$accreditation['admiteCheckin'] ? 'success' : 'warning'">{{ $accreditation['admiteCheckin'] ? 'Presenta el QR del paciente en el lector para registrar su llegada.' : 'La acreditación actual no admite registrar una nueva llegada.' }}</x-admin.notice>
    <div class="a-actions">@if($accreditation['admiteCheckin'])<x-admin.button :href="route('secretaria.checkin.index')">Ir a check-in</x-admin.button>@endif<x-admin.button variant="secondary" :href="route('secretaria.citas.show', $accreditation['codCit'])">Ver cita</x-admin.button></div>
@endsection
