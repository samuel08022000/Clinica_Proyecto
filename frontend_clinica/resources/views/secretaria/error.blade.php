@extends('layouts.secretaria')
@section('reception_title', 'Consulta no disponible')
@section('breadcrumb', 'Consulta no disponible')
@section('reception_content')
    <x-admin.heading title="No se pudo completar" :description="'Respuesta del servicio: '.$status" />
    <x-admin.notice :type="$status >= 500 ? 'warning' : 'error'">{{ $message }}</x-admin.notice>
    <div class="a-actions"><x-admin.button :href="route('secretaria.home')">Ir al panel</x-admin.button><x-admin.button variant="secondary" :href="$returnUrl ?? request()->fullUrl()">{{ isset($returnUrl) ? 'Corregir consulta' : 'Reintentar consulta' }}</x-admin.button></div>
@endsection
