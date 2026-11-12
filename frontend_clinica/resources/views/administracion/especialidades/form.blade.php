@extends('layouts.administracion')
@php($editing = !empty($record))
@php($title = $editing ? 'Editar especialidad' : 'Nueva especialidad')
@section('admin_title', $title)
@section('breadcrumb')<a href="{{ route('administracion.especialidades.index') }}">Especialidades</a><span aria-hidden="true">/</span>{{ $title }}@endsection
@section('admin_content')
    <x-admin.heading :title="$title" description="Los campos con * son obligatorios." />
    <x-admin.form :action="$editing ? route('administracion.especialidades.update', $record['codEsp']) : route('administracion.especialidades.store')" :method="$editing ? 'PUT' : 'POST'">
        <x-admin.field name="nombreEspecialidad" label="Nombre de especialidad" :value="$record['nombreEspecialidad'] ?? ''" required maxlength="120" />
        <x-admin.field name="descripcion" label="Descripción" type="textarea" :value="$record['descripcion'] ?? ''" maxlength="10000" />
        @if(!$editing)<x-admin.field name="duracionConsultaMin" label="Duración de consulta" type="select" required value="30" :options="[20 => '20 minutos', 30 => '30 minutos', 45 => '45 minutos', 60 => '60 minutos']" />
        @else<x-admin.notice>Duración actual: {{ $record['duracionConsultaMin'] }} minutos. El contrato de edición permite modificar el nombre y la descripción.</x-admin.notice>@endif
        <div class="a-actions"><x-admin.button>Guardar especialidad</x-admin.button><x-admin.button variant="secondary" :href="route('administracion.especialidades.index')">Cancelar</x-admin.button></div>
    </x-admin.form>
@endsection
