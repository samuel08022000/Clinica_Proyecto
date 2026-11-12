@extends('layouts.administracion')
@section('admin_title', 'Registrar bloqueo')
@section('breadcrumb')<a href="{{ route('administracion.bloqueos.index', ['medico' => $doctor['codMed']]) }}">Bloqueos</a><span aria-hidden="true">/</span>Registrar
@endsection
@section('admin_content')
    <x-admin.heading title="Registrar bloqueo" :description="$doctor['nombreCompleto'].' · '.$doctor['codMed']" />
    <x-admin.form :action="route('administracion.bloqueos.store', $doctor['codMed'])" confirm="Se bloqueará esta fecha y las citas afectadas quedarán pendientes de reprogramación. ¿Deseas continuar?">
        <x-admin.field name="fechaBloqueo" label="Fecha del bloqueo" type="date" required />
        <fieldset><legend>Intervalo del bloqueo</legend><p class="a-help">Para una jornada completa, deja ambas horas vacías. El bloqueo se registra para una sola fecha.</p><div class="a-form-grid">
            <x-admin.field name="horaInicio" label="Hora de inicio" type="time" />
            <x-admin.field name="horaFin" label="Hora de fin" type="time" />
        </div></fieldset>
        <x-admin.field name="motivo" label="Motivo" type="textarea" required maxlength="2000" />
        <x-admin.confirmation message="Confirmo el bloqueo y la revisión posterior de las citas afectadas." />
        <div class="a-actions"><x-admin.button>Registrar bloqueo</x-admin.button><x-admin.button variant="secondary" :href="route('administracion.bloqueos.index', ['medico' => $doctor['codMed']])">Cancelar</x-admin.button></div>
    </x-admin.form>
@endsection
