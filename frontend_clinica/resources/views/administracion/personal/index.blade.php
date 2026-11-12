@extends('layouts.administracion')
@php($medical = $resource === 'medicos')
@php($title = $medical ? 'Médicos' : 'Secretarios')
@section('admin_title', $title)
@section('breadcrumb', $title)
@section('admin_content')
    <x-admin.heading :title="$title" description="Consulta el personal y administra sus datos de acceso.">
        <x-admin.button :href="route('administracion.'.$resource.'.create')"><x-admin.icon name="plus" />{{ $medical ? 'Registrar médico' : 'Registrar secretario' }}</x-admin.button>
    </x-admin.heading>
    <form method="GET" class="a-filter">
        <x-admin.field name="q" label="Buscar en el catálogo" :value="request('q')" placeholder="Nombre, correo, CI o código" maxlength="150" />
        <x-admin.field name="estado" label="Estado" type="select" :value="request('estado')" :options="['' => 'Todos', 'Activo' => 'Activo', 'Inactivo' => 'Inactivo']" />
        <x-admin.button type="submit" variant="secondary">Buscar</x-admin.button>
        <x-admin.button variant="text" :href="route('administracion.'.$resource.'.index')">Limpiar</x-admin.button>
    </form>
    <p class="a-small a-muted">{{ count($rows) }} {{ strtolower($title) }} en el resultado.</p>
    @if(count($rows))
        <x-admin.table :headings="['Código', 'Nombre completo', $medical ? 'Especialidades' : 'Correo', 'Estado', 'Acciones']" :caption="$title">
            @foreach($rows as $row)
                @php($code = $row[$medical ? 'codMed' : 'codSec'])
                <tr>
                    <td>{{ $code }}</td><td><a href="{{ route('administracion.'.$resource.'.show', $code) }}">{{ $row['nombreCompleto'] }}</a>@if($medical)<small>{{ $row['matriculaProfesional'] }}</small>@endif</td>
                    <td>@if($medical){{ collect($row['especialidades'])->where('estado', 'Activo')->pluck('nombreEspecialidad')->implode(', ') ?: 'Sin vínculos activos' }}@else{{ $row['email'] }}@endif</td>
                    <td><x-admin.badge :value="$row['estado']" /></td>
                    <td><div class="a-actions"><a href="{{ route('administracion.'.$resource.'.show', $code) }}" aria-label="Ver a {{ $row['nombreCompleto'] }}">Ver</a><a href="{{ route('administracion.'.$resource.'.edit', $code) }}" aria-label="Editar a {{ $row['nombreCompleto'] }}">Editar</a></div></td>
                </tr>
            @endforeach
        </x-admin.table>
    @else
        <x-admin.empty :title="'No se encontraron '.strtolower($title)" description="Revisa la búsqueda y el estado seleccionado, o registra personal con el botón superior." />
    @endif
@endsection
