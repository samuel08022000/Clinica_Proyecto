@extends('layouts.medico')
@section('medico_title', 'No fue posible completar la solicitud')
@section('medico_content')
    <x-admin.heading title="No fue posible completar la solicitud" :description="$message" />
    <x-admin.notice :type="$status >= 500 ? 'error' : 'warning'">Código HTTP {{ $status }}. No se expusieron datos de otros médicos ni detalles internos.</x-admin.notice>
    <div class="a-actions"><x-admin.button :href="route('medico.home')">Volver al resumen</x-admin.button><x-admin.button variant="secondary" :href="url()->previous()">Volver</x-admin.button></div>
@endsection
