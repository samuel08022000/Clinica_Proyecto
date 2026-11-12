@extends('layouts.administracion')
@section('admin_title', 'Panel de administración')
@section('admin_content')
    <x-admin.heading title="Panel de administración" description="Personal, agendas y acceso a reservas bajo una misma gestión.">
        <x-admin.button :href="route('administracion.medicos.create')"><x-admin.icon name="plus" />Registrar médico</x-admin.button>
    </x-admin.heading>
    <div class="a-metrics">
        @foreach(['medicos' => 'Médicos activos', 'secretarias' => 'Secretarios activos', 'especialidades' => 'Especialidades activas'] as $resource => $label)
            <a href="{{ route('administracion.'.$resource.'.index', ['estado' => 'Activo']) }}"><dl><dt>{{ $label }}</dt><dd>{{ $counts[$resource] ?? 'No disponible' }}</dd></dl><small>{{ $counts[$resource] === null ? 'Reintentar desde el catálogo' : 'Consultar catálogo' }}</small></a>
        @endforeach
    </div>
    <div class="a-columns">
        <section class="a-stack" aria-labelledby="operations-title">
            <h2 id="operations-title">Gestión operativa</h2>
            <div class="a-job-list">
                <a href="{{ route('administracion.agendas.index') }}"><span><strong>Agendas y horarios</strong><small>Revisa la semana, los descansos y la duración de consulta.</small></span><x-admin.icon /></a>
                <a href="{{ route('administracion.bloqueos.index') }}"><span><strong>Bloqueos médicos</strong><small>Registra una ausencia y consulta las citas afectadas.</small></span><x-admin.icon /></a>
                <a href="{{ route('administracion.pacientes.index') }}"><span><strong>Pacientes e incidencias</strong><small>Consulta la habilitación para reservar y sus antecedentes administrativos.</small></span><x-admin.icon /></a>
                <a href="{{ route('administracion.sanciones.index') }}"><span><strong>Sanciones temporales</strong><small>Revisa restricciones y aplica una sanción desde el perfil del paciente.</small></span><x-admin.icon /></a>
            </div>
        </section>
        <section class="a-panel" aria-labelledby="quick-title">
            <h2 id="quick-title">Accesos rápidos</h2>
            <div class="a-job-list">
                <a href="{{ route('administracion.secretarias.create') }}"><span>Registrar secretario</span><x-admin.icon name="plus" /></a>
                <a href="{{ route('administracion.especialidades.create') }}"><span>Nueva especialidad</span><x-admin.icon name="plus" /></a>
                <a href="{{ route('administracion.agendas.index') }}"><span>Configurar horario</span><x-admin.icon /></a>
                <a href="{{ route('administracion.bloqueos.index') }}"><span>Registrar bloqueo</span><x-admin.icon /></a>
                <a href="{{ route('administracion.pacientes.index') }}"><span>Consultar pacientes</span><x-admin.icon /></a>
            </div>
        </section>
    </div>
@endsection
