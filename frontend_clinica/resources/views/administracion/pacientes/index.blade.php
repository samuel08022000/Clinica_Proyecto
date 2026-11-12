@extends('layouts.administracion')
@section('admin_title', $sanctions ? 'Sanciones' : 'Pacientes')
@section('breadcrumb', $sanctions ? 'Sanciones' : 'Pacientes')
@section('admin_content')
    <x-admin.heading :title="$sanctions ? 'Sanciones' : 'Pacientes'" :description="$sanctions ? 'Busca un paciente para consultar sus incidencias y gestionar sanciones temporales.' : 'Información administrativa y habilitación para reservar, informada por el servicio.'" />
    <form method="GET" class="a-filter">
        <x-admin.field name="q" label="Buscar paciente" :value="request('q')" minlength="3" maxlength="100" placeholder="Nombre, CI o correo (mínimo 3 caracteres)" />
        <x-admin.field name="estado" label="Estado de cuenta" type="select" :value="request('estado')" :options="['' => 'Todos', 'Activo' => 'Activo', 'Inactivo' => 'Inactivo', 'Bloqueado_Temporal' => 'Bloqueado temporal']" />
        <x-admin.button variant="secondary">Buscar</x-admin.button><x-admin.button variant="text" :href="route($sanctions ? 'administracion.sanciones.index' : 'administracion.pacientes.index')">Limpiar</x-admin.button>
    </form>
    @if(count($page['pacientes']))<x-admin.table :headings="['Código', 'Paciente', 'Contacto', 'Cuenta', 'Reserva', 'Acciones']" caption="Pacientes administrativos">
        @foreach($page['pacientes'] as $patient)<tr><td>{{ $patient['codPac'] }}</td><td><a href="{{ route('administracion.pacientes.show', $patient['codPac']) }}">{{ $patient['nombreCompleto'] }}</a><small>CI {{ $patient['ci'] ?: '—' }}</small></td><td>{{ $patient['email'] }}<small>{{ $patient['telefono'] ?: 'Sin teléfono' }}</small></td><td><x-admin.badge :value="$patient['estadoCuenta']" /></td><td><x-admin.badge :value="$patient['habilitadoParaReservar'] ? 'Habilitado' : 'Restringido'" /></td><td><a href="{{ route('administracion.pacientes.show', $patient['codPac']) }}{{ $sanctions ? '#sanciones' : '' }}">{{ $sanctions ? 'Ver sanciones' : 'Ver perfil' }}</a></td></tr>@endforeach
    </x-admin.table>@else<x-admin.empty title="No se encontraron pacientes" description="Prueba otro término o cambia el estado de cuenta." />@endif
    <x-admin.pagination :page="$page" :count="count($page['pacientes'])" />
@endsection
