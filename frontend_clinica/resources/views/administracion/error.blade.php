@extends('layouts.administracion')
@section('admin_title', 'Servicio de administración')
@section('breadcrumb', 'Consulta no disponible')
@section('admin_content')
    <x-admin.heading title="No se pudo completar" :description="'Respuesta del servicio: '.$status" />
    <x-admin.notice :type="$status >= 500 ? 'warning' : 'error'">{{ $message }}</x-admin.notice>
    <div class="a-actions"><x-admin.button :href="route('administracion.home')">Ir al panel</x-admin.button><x-admin.button variant="secondary" :href="request()->isMethod('GET') ? request()->fullUrl() : route('administracion.home')">Reintentar consulta</x-admin.button></div>
@endsection
