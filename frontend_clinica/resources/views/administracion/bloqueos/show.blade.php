@extends('layouts.administracion')
@section('admin_title', 'Detalle de bloqueo')
@section('breadcrumb')<a href="{{ route('administracion.bloqueos.index', ['medico' => $record['codMed']]) }}">Bloqueos</a><span aria-hidden="true">/</span>{{ $record['codBlo'] }}@endsection
@section('admin_content')
    <x-admin.heading title="Detalle de bloqueo" :description="$record['codBlo'].' · Médico '.$record['codMed']" />
    <dl class="a-details">
        <div><dt>Fecha</dt><dd>{{ $record['fechaBloqueo'] }}</dd></div>
        <div><dt>Intervalo</dt><dd>{{ $record['horaInicio'] ? substr($record['horaInicio'], 0, 5).' – '.substr($record['horaFin'], 0, 5) : 'Jornada completa' }}</dd></div>
        <div><dt>Motivo</dt><dd>{{ $record['motivo'] }}</dd></div>
        <div><dt>Registrado por</dt><dd>{{ $record['codUsuAdmin'] }} · {{ $record['creadoEn'] }}</dd></div>
    </dl>
    <section class="a-stack" aria-labelledby="affected-title"><h2 id="affected-title">Citas afectadas</h2>
        @if(!$record['trazabilidadCompleta'] || !($affected['trazabilidadCompleta'] ?? false))
            <x-admin.notice type="warning">Este bloqueo no dispone de trazabilidad completa. No es posible reconstruir una lista fiable de citas afectadas a partir de este registro.</x-admin.notice>
        @elseif(!count($affected['citas']))<x-admin.empty title="Sin citas afectadas" description="El bloqueo no dejó citas pendientes de reprogramación." />
        @else
            <p class="a-muted">{{ $record['cantidadCitasAfectadas'] }} citas afectadas. La reprogramación debe gestionarse mediante el proceso administrativo correspondiente.</p>
            <x-admin.table :headings="['Cita', 'Paciente', 'Especialidad', 'Fecha', 'Intervalo', 'Estado']" caption="Citas afectadas por el bloqueo">
                @foreach($affected['citas'] as $appointment)<tr><td>{{ $appointment['codCit'] }}</td><td><a href="{{ route('administracion.pacientes.show', $appointment['codPac']) }}">{{ $appointment['codPac'] }}</a></td><td>{{ $appointment['codEsp'] }}</td><td>{{ $appointment['fecha'] }}</td><td>{{ substr($appointment['horaInicio'], 0, 5) }} – {{ substr($appointment['horaFin'], 0, 5) }}</td><td><x-admin.badge :value="$appointment['estado']" /></td></tr>@endforeach
            </x-admin.table>
        @endif
    </section>
@endsection
