@extends('layouts.administracion')
@php($medical = $resource === 'medicos')
@php($code = $record[$medical ? 'codMed' : 'codSec'])
@section('admin_title', $medical ? 'Detalle de médico' : 'Detalle de secretario')
@section('breadcrumb')<a href="{{ route('administracion.'.$resource.'.index') }}">{{ $medical ? 'Médicos' : 'Secretarios' }}</a><span aria-hidden="true">/</span>{{ $code }}@endsection
@section('admin_content')
    <x-admin.heading :title="$record['nombreCompleto']" :description="$code"><x-admin.button :href="route('administracion.'.$resource.'.edit', $code)">Editar datos</x-admin.button></x-admin.heading>
    <section class="a-panel a-stack" aria-labelledby="profile-title">
        <h2 id="profile-title">Datos administrativos</h2>
        <dl class="a-details">
            @foreach(['email' => 'Correo', 'ci' => 'Cédula de identidad', $medical ? 'matriculaProfesional' : 'telefono' => $medical ? 'Matrícula profesional' : 'Teléfono'] as $key => $label)<div><dt>{{ $label }}</dt><dd>{{ $record[$key] ?: '—' }}</dd></div>@endforeach
            <div><dt>Estado</dt><dd><x-admin.badge :value="$record['estado']" /></dd></div>
            @if($medical)<div><dt>Duración general</dt><dd>{{ $record['duracionConsultaMin'] ? $record['duracionConsultaMin'].' minutos' : 'Sin duración general' }}</dd></div><div><dt>Resumen curricular</dt><dd>{{ $record['resumenCurricular'] ?: '—' }}</dd></div>@endif
        </dl>
    </section>
    @if($medical)
        <section class="a-stack" aria-labelledby="specialty-title"><h2 id="specialty-title">Especialidades vinculadas</h2>
            <x-admin.table :headings="['Especialidad', 'Duración', 'Estado del vínculo']" caption="Especialidades del médico">
                @foreach($record['especialidades'] as $specialty)<tr><td>{{ $specialty['nombreEspecialidad'] }}</td><td class="a-numeric">{{ $specialty['duracionConsultaMin'] }} min</td><td><x-admin.badge :value="$specialty['estado']" /></td></tr>@endforeach
            </x-admin.table>
        </section>
        <div class="a-actions"><x-admin.button variant="secondary" :href="route('administracion.agendas.index', ['medico' => $code])">Configurar agenda</x-admin.button><x-admin.button variant="secondary" :href="route('administracion.bloqueos.index', ['medico' => $code])">Consultar bloqueos</x-admin.button></div>
    @else
        <div><x-admin.button variant="secondary" :href="route('administracion.secretarias.password', $code)">Restablecer contraseña</x-admin.button></div>
    @endif
    <x-admin.state :action="route('administracion.'.$resource.'.estado', $code)" :state="$record['estado']" :name="$record['nombreCompleto']" impact="La inactivación deshabilita el acceso del personal y revoca sus sesiones. Sus registros se conservan para mantener la trazabilidad." />
@endsection
