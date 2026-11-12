@extends('layouts.administracion')
@section('admin_title', 'Aplicar sanción temporal')
@section('breadcrumb')<a href="{{ route('administracion.pacientes.show', $record['codPac']) }}">{{ $record['nombreCompleto'] }}</a><span aria-hidden="true">/</span>Sanción temporal
@endsection
@section('admin_content')
    <x-admin.heading title="Aplicar sanción temporal" :description="$record['nombreCompleto'].' · '.$record['codPac']" />
    <x-admin.notice type="warning">La sanción restringe temporalmente las reservas. Revisa el motivo y la duración antes de confirmar. No está disponible el levantamiento anticipado.</x-admin.notice>
    <x-admin.form :action="route('administracion.sanciones.store', $record['codPac'])" :confirm="'¿Confirmas aplicar una sanción temporal a '.$record['nombreCompleto'].'?'">
        <x-admin.field name="motivo" label="Motivo de la sanción" type="textarea" required maxlength="2000" />
        <x-admin.field name="dias" label="Duración en días" type="number" required min="1" max="365" step="1" inputmode="numeric" help="Número entero entre 1 y 365. El servicio establece las fechas y verifica restricciones vigentes." />
        <x-admin.confirmation message="Confirmo la aplicación de esta sanción temporal y su motivo." />
        <div class="a-actions"><x-admin.button variant="danger">Aplicar sanción</x-admin.button><x-admin.button variant="secondary" :href="route('administracion.pacientes.show', $record['codPac'])">Cancelar</x-admin.button></div>
    </x-admin.form>
@endsection
