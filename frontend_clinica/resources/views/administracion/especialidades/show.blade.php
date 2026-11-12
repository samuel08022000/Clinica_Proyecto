@extends('layouts.administracion')
@section('admin_title', 'Detalle de especialidad')
@section('breadcrumb')<a href="{{ route('administracion.especialidades.index') }}">Especialidades</a><span aria-hidden="true">/</span>{{ $record['codEsp'] }}@endsection
@section('admin_content')
    <x-admin.heading :title="$record['nombreEspecialidad']" :description="$record['codEsp']"><x-admin.button :href="route('administracion.especialidades.edit', $record['codEsp'])">Editar especialidad</x-admin.button></x-admin.heading>
    <dl class="a-details"><div><dt>Descripción</dt><dd>{{ $record['descripcion'] ?: '—' }}</dd></div><div><dt>Duración</dt><dd>{{ $record['duracionConsultaMin'] }} minutos</dd></div><div><dt>Estado</dt><dd><x-admin.badge :value="$record['estado']" /></dd></div></dl>
    <section class="a-stack" aria-labelledby="linked-doctors"><h2 id="linked-doctors">Médicos vinculados</h2>
        @if(count($doctors))<x-admin.table :headings="['Código', 'Médico', 'Duración', 'Estado del médico', 'Estado del vínculo']" caption="Médicos de la especialidad">
            @foreach($doctors as $doctor)<tr><td>{{ $doctor['codMed'] }}</td><td><a href="{{ route('administracion.medicos.show', $doctor['codMed']) }}">{{ $doctor['nombreCompleto'] }}</a></td><td class="a-numeric">{{ $doctor['duracionConsultaMin'] }} min</td><td><x-admin.badge :value="$doctor['estadoMedico']" /></td><td><x-admin.badge :value="$doctor['estadoVinculo']" /></td></tr>@endforeach
        </x-admin.table>@else<x-admin.empty title="Sin médicos vinculados" description="Asocia profesionales desde la edición del médico." />@endif
    </section>
    <x-admin.state :action="route('administracion.especialidades.estado', $record['codEsp'])" :state="$record['estado']" :name="$record['nombreEspecialidad']" impact="La inactivación restringe nuevas reservas en esta especialidad. Las citas existentes y los vínculos se conservan." />
@endsection
