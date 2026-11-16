@extends('layouts.administracion')
@section('admin_title', 'Bloqueos médicos')
@section('breadcrumb', 'Bloqueos médicos')
@section('admin_content')
    <x-admin.heading title="Bloqueos médicos" description="Ausencias por fecha y trazabilidad de citas pendientes de reprogramación.">
        @if($doctor)<x-admin.button :href="route('administracion.bloqueos.create', $doctor['codMed'])"><x-admin.icon name="plus" />Registrar bloqueo</x-admin.button>@endif
    </x-admin.heading>
    <form method="GET" class="a-filter">
        @include('administracion.partials.doctor-selector')
        <x-admin.field name="desde" label="Desde" type="date" :value="request('desde')" />
        <x-admin.field name="hasta" label="Hasta" type="date" :value="request('hasta')" />
        <x-admin.button variant="secondary">Consultar</x-admin.button>
    </form>
    @if(!$doctor)<x-admin.empty title="Selecciona un médico" description="Consulta sus bloqueos o registra una ausencia de jornada completa o de un intervalo." />
    @elseif(!count($rows))<x-admin.empty title="No hay bloqueos en este período" description="Puedes registrar una ausencia mediante el botón superior." />
    @else<x-admin.table :headings="['Fecha', 'Intervalo', 'Motivo', 'Citas afectadas', 'Trazabilidad', 'Acciones']" caption="Bloqueos del médico">
        @foreach($rows as $row)<tr><td>{{ $row['fechaBloqueo'] }}</td><td>{{ $row['horaInicio'] ? substr($row['horaInicio'], 0, 5).' – '.substr($row['horaFin'], 0, 5) : 'Jornada completa' }}</td><td>{{ \Illuminate\Support\Str::limit($row['motivo'], 100) }}</td><td class="a-numeric">{{ $row['trazabilidadCompleta'] ? $row['cantidadCitasAfectadas'] : '—' }}</td><td><x-admin.badge :value="$row['trazabilidadCompleta'] ? 'Completa' : 'Incompleta'" /></td><td><a href="{{ route('administracion.bloqueos.show', $row['codBlo']) }}" aria-label="Ver bloqueo {{ $row['codBlo'] }}">Ver detalle</a></td></tr>@endforeach
    </x-admin.table>@endif
@endsection
