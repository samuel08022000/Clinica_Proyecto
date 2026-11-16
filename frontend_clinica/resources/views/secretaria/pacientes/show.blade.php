@extends('layouts.secretaria')
@section('reception_title', 'Perfil administrativo')
@section('breadcrumb')
    <a href="{{ route('secretaria.pacientes.index') }}">Pacientes</a><span aria-hidden="true">/</span>{{ $patient['codPac'] }}
@endsection
@section('reception_content')
    <x-admin.heading :title="$patient['nombreCompleto']" :description="$patient['codPac']">
        @if($patient['habilitadoParaReservar'])<x-admin.button :href="route('secretaria.reservas.create', ['codPac' => $patient['codPac']])">Nueva reserva</x-admin.button>@endif
    </x-admin.heading>
    <x-admin.notice :type="$patient['habilitadoParaReservar'] ? 'success' : 'warning'">{{ $patient['habilitadoParaReservar'] ? 'Paciente habilitado para reservar.' : 'Reservas restringidas: '.($patient['motivoInhabilitacion'] ?? 'consulta con Administración.') }}</x-admin.notice>
    <section class="a-panel"><h2>Datos administrativos</h2><dl class="a-details">
        @foreach(['ci' => 'CI', 'email' => 'Correo', 'telefono' => 'Teléfono', 'ciudadResidencia' => 'Ciudad', 'estadoCuenta' => 'Estado de cuenta', 'estadoUsuario' => 'Estado de usuario'] as $key => $label)<div><dt>{{ $label }}</dt><dd>{{ $patient[$key] ?? 'No informado' }}</dd></div>@endforeach
        <div><dt>Correo verificado</dt><dd>{{ $patient['emailVerificado'] ? 'Sí' : 'No' }}</dd></div>
        @if(filled($patient['bloqueadoHasta'] ?? null))<div><dt>Restricción hasta</dt><dd>{{ $patient['bloqueadoHasta'] }}</dd></div>@endif
    </dl></section>
    <div class="a-actions"><x-admin.button variant="secondary" :href="route('secretaria.historial.show', $patient['codPac'])">Consultar historial</x-admin.button><x-admin.button variant="text" :href="route('secretaria.pacientes.index')">Volver a búsqueda</x-admin.button></div>
@endsection
