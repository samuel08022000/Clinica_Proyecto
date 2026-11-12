@extends('layouts.administracion')
@section('admin_title', 'Perfil administrativo')
@section('breadcrumb')<a href="{{ route('administracion.pacientes.index') }}">Pacientes</a><span aria-hidden="true">/</span>{{ $record['codPac'] }}@endsection
@section('admin_content')
    <x-admin.heading :title="$record['nombreCompleto']" :description="$record['codPac']"><x-admin.button :href="route('administracion.sanciones.create', $record['codPac'])">Aplicar sanción temporal</x-admin.button></x-admin.heading>
    <section class="a-panel a-stack" aria-labelledby="booking-state-title"><h2 id="booking-state-title">Habilitación para reservar</h2>
        <div><x-admin.badge :value="$record['habilitadoParaReservar'] ? 'Habilitado' : 'Restringido'" /></div>
        <p>{{ $record['motivoInhabilitacion'] ?: 'Sin restricciones informadas por el servicio.' }}</p>
        @if($record['bloqueadoHasta'])<p class="a-muted">Restricción hasta {{ $record['bloqueadoHasta'] }}.</p>@endif
    </section>
    <section class="a-stack" aria-labelledby="patient-data-title"><h2 id="patient-data-title">Datos administrativos</h2><dl class="a-details">
        @foreach(['ci' => 'Cédula de identidad', 'email' => 'Correo', 'telefono' => 'Teléfono', 'ciudadResidencia' => 'Ciudad de residencia', 'estadoCuenta' => 'Estado de cuenta', 'estadoUsuario' => 'Estado del usuario'] as $key => $label)<div><dt>{{ $label }}</dt><dd>{{ $record[$key] ?: '—' }}</dd></div>@endforeach
        <div><dt>Correo verificado</dt><dd>{{ $record['emailVerificado'] ? 'Sí' : 'No' }}</dd></div>
    </dl></section>
    <section class="a-stack" aria-labelledby="incidencias" id="incidents-section"><h2 id="incidencias">Historial de incidencias</h2>
        <form method="GET" class="a-filter" action="{{ route('administracion.pacientes.show', $record['codPac']) }}#incidencias">
            <x-admin.field name="tipo" label="Tipo de incidencia" type="select" :value="request('tipo')" :options="['' => 'Todas', 'CANCELACION' => 'Cancelación', 'INASISTENCIA_NO_SHOW' => 'Inasistencia', 'SANCION_BLOQUEO' => 'Sanción', 'OTRA' => 'Otra']" />
            @if(request()->filled('vigente'))<input type="hidden" name="vigente" value="{{ request('vigente') }}">@endif
            <x-admin.button variant="secondary">Filtrar incidencias</x-admin.button>
        </form>
        @include('administracion.pacientes.incidencias', ['page' => $incidents, 'sanctionList' => false])
        <x-admin.pagination :page="$incidents" key="incidencias_page" :count="count($incidents['incidencias'])" anchor="#incidencias" />
    </section>
    <section class="a-stack" aria-labelledby="sanciones" id="sanctions-section"><h2 id="sanciones">Historial de sanciones</h2>
        <form method="GET" class="a-filter" action="{{ route('administracion.pacientes.show', $record['codPac']) }}#sanciones">
            <x-admin.field name="vigente" label="Vigencia" type="select" :value="request('vigente')" :options="['' => 'Todas', 'true' => 'Vigentes', 'false' => 'No vigentes']" />
            @if(request()->filled('tipo'))<input type="hidden" name="tipo" value="{{ request('tipo') }}">@endif
            <x-admin.button variant="secondary">Filtrar sanciones</x-admin.button>
        </form>
        @include('administracion.pacientes.incidencias', ['page' => $sanctions, 'sanctionList' => true])
        <x-admin.pagination :page="$sanctions" key="sanciones_page" :count="count($sanctions['incidencias'])" anchor="#sanciones" />
    </section>
@endsection
