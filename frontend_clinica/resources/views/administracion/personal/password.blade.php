@extends('layouts.administracion')
@section('admin_title', 'Restablecer contraseña')
@section('breadcrumb')<a href="{{ route('administracion.secretarias.show', $record['codSec']) }}">{{ $record['nombreCompleto'] }}</a><span aria-hidden="true">/</span>Acceso
@endsection
@section('admin_content')
    <x-admin.heading title="Restablecer contraseña" :description="$record['nombreCompleto'].' · '.$record['email']" />
    <x-admin.form :action="route('administracion.secretarias.password.update', $record['codSec'])" method="PUT" confirm="Se cambiará la contraseña del secretario y se revocarán sus sesiones. ¿Deseas continuar?">
        <div class="a-form-grid">
            <x-admin.field name="password" label="Nueva contraseña" type="password" required minlength="10" maxlength="72" autocomplete="new-password" help="Entre 10 y 72 bytes, con mayúsculas, minúsculas y números." />
            <x-admin.field name="password_confirmation" label="Confirmar nueva contraseña" type="password" required minlength="10" maxlength="72" autocomplete="new-password" />
        </div>
        <x-admin.confirmation message="Confirmo el restablecimiento de acceso de este secretario." />
        <div class="a-actions"><x-admin.button>Restablecer contraseña</x-admin.button><x-admin.button variant="secondary" :href="route('administracion.secretarias.show', $record['codSec'])">Cancelar</x-admin.button></div>
    </x-admin.form>
@endsection
