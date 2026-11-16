@extends('layouts.administracion')
@section('admin_title', 'Especialidades')
@section('breadcrumb', 'Especialidades')
@section('admin_content')
    <x-admin.heading title="Especialidades" description="Catálogo médico y duración de consulta por especialidad."><x-admin.button :href="route('administracion.especialidades.create')"><x-admin.icon name="plus" />Nueva especialidad</x-admin.button></x-admin.heading>
    <form method="GET" class="a-filter">
        <x-admin.field name="q" label="Buscar especialidad" :value="request('q')" maxlength="120" />
        <x-admin.field name="estado" label="Estado" type="select" :value="request('estado', 'Todos')" :options="['Todos' => 'Todos', 'Activo' => 'Activo', 'Inactivo' => 'Inactivo']" />
        <x-admin.button variant="secondary">Buscar</x-admin.button><x-admin.button variant="text" :href="route('administracion.especialidades.index')">Limpiar</x-admin.button>
    </form>
    <p class="a-small a-muted">{{ count($rows) }} especialidades en el resultado.</p>
    @if(count($rows))<x-admin.table :headings="['Código', 'Nombre', 'Descripción', 'Duración', 'Estado', 'Acciones']" caption="Catálogo de especialidades">
        @foreach($rows as $row)<tr><td>{{ $row['codEsp'] }}</td><td><a href="{{ route('administracion.especialidades.show', $row['codEsp']) }}">{{ $row['nombreEspecialidad'] }}</a></td><td>{{ \Illuminate\Support\Str::limit($row['descripcion'] ?? '—', 100) }}</td><td class="a-numeric">{{ $row['duracionConsultaMin'] }} min</td><td><x-admin.badge :value="$row['estado']" /></td><td><div class="a-actions"><a href="{{ route('administracion.especialidades.show', $row['codEsp']) }}" aria-label="Ver {{ $row['nombreEspecialidad'] }}">Ver</a><a href="{{ route('administracion.especialidades.edit', $row['codEsp']) }}" aria-label="Editar {{ $row['nombreEspecialidad'] }}">Editar</a></div></td></tr>@endforeach
    </x-admin.table>@else<x-admin.empty title="No se encontraron especialidades" description="Revisa los filtros o registra una especialidad nueva." />@endif
@endsection
