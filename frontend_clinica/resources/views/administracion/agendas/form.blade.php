@extends('layouts.administracion')
@php($editing = !empty($record))
@php($title = $editing ? 'Editar horario' : 'Agregar horario')
@section('admin_title', $title)
@section('breadcrumb')<a href="{{ route('administracion.agendas.index', ['medico' => $doctor['codMed']]) }}">Agenda de {{ $doctor['nombreCompleto'] }}</a><span aria-hidden="true">/</span>{{ $title }}@endsection
@section('admin_content')
    <x-admin.heading :title="$title" :description="$doctor['nombreCompleto'].' · Los campos con * son obligatorios.'" />
    <x-admin.form :action="$editing ? route('administracion.agendas.update', [$doctor['codMed'], $record['codHor']]) : route('administracion.agendas.store', $doctor['codMed'])" :method="$editing ? 'PUT' : 'POST'">
        <x-admin.field name="diaSemana" label="Día de atención" type="select" :value="$record['diaSemana'] ?? 1" required :options="[1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo']" />
        <fieldset><legend>Horario de atención</legend><div class="a-form-grid">
            <x-admin.field name="horaInicio" label="Hora de inicio" type="time" :value="substr($record['horaInicio'] ?? '', 0, 5)" required />
            <x-admin.field name="horaFin" label="Hora de fin" type="time" :value="substr($record['horaFin'] ?? '', 0, 5)" required />
        </div></fieldset>
        <fieldset><legend>Descanso opcional</legend><p class="a-help">Completa ambas horas de descanso o deja ambas vacías. El descanso debe quedar dentro del horario.</p><div class="a-form-grid">
            <x-admin.field name="pausaInicio" label="Inicio del descanso" type="time" :value="substr($record['pausaInicio'] ?? '', 0, 5)" />
            <x-admin.field name="pausaFin" label="Fin del descanso" type="time" :value="substr($record['pausaFin'] ?? '', 0, 5)" />
        </div></fieldset>
        <x-admin.notice>Los horarios superpuestos o los cambios incompatibles con citas futuras se rechazan y se informa el motivo.</x-admin.notice>
        <div class="a-actions"><x-admin.button>Guardar horario</x-admin.button><x-admin.button variant="secondary" :href="route('administracion.agendas.index', ['medico' => $doctor['codMed']])">Cancelar</x-admin.button></div>
    </x-admin.form>
@endsection
