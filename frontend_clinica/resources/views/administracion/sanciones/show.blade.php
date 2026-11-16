@extends('layouts.administracion')
@section('admin_title', 'Detalle de sanción')
@section('breadcrumb')<a href="{{ route('administracion.pacientes.show', $record['codPac']) }}#sanciones">Sanciones del paciente</a><span aria-hidden="true">/</span>{{ $record['codInc'] }}@endsection
@section('admin_content')
    <x-admin.heading title="Detalle de sanción" :description="$record['codInc'].' · Paciente '.$record['codPac']" />
    <dl class="a-details">
        <div><dt>Motivo</dt><dd>{{ $record['motivo'] }}</dd></div><div><dt>Vigencia informada</dt><dd><x-admin.badge :value="$record['vigente'] ? 'Vigente' : 'No vigente'" /></dd></div>
        <div><dt>Aplicación</dt><dd>{{ $record['fechaRegistro'] }}</dd></div><div><dt>Vencimiento</dt><dd>{{ $record['fechaFinSancion'] }}</dd></div><div><dt>Responsable</dt><dd>{{ $record['registradoPorUsu'] }}</dd></div>
    </dl>
@endsection
