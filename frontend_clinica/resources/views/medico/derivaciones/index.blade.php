@extends('layouts.medico')
@section('medico_title', 'Derivaciones emitidas')
@section('breadcrumb', 'Derivaciones')
@section('medico_content')
    <x-admin.heading title="Derivaciones emitidas" description="Seguimiento de interconsultas creadas desde tus propias atenciones." />
    <form method="GET" action="{{ route('medico.derivaciones.index') }}" class="a-filter a-filter--compact">
        <x-admin.field name="estado" label="Estado" type="select" :value="$filters['estado'] ?? ''" :options="['' => 'Todos', 'Pendiente' => 'Pendiente', 'Agendada' => 'Agendada', 'Completada' => 'Completada', 'Cancelada' => 'Cancelada']" />
        <x-admin.button>Aplicar filtro</x-admin.button>
        <x-admin.button variant="text" :href="route('medico.derivaciones.index')">Limpiar</x-admin.button>
    </form>
    @if(count($page['derivaciones']))
        <x-admin.table :headings="['Derivación', 'Paciente y origen', 'Destino', 'Programación', 'Estado', 'Acción']" caption="Derivaciones emitidas">
            @foreach($page['derivaciones'] as $item)<tr>
                <td>{{ $item['codDer'] }}<small>{{ $item['fechaDerivacion'] }}</small></td>
                <td>{{ $item['nombrePaciente'] }}<small>{{ $item['codCitOrigen'] }}</small></td>
                <td>{{ $item['nombreEspecialidad'] }}<small>{{ $item['nombreMedicoDestino'] ?? 'Sin destinatario específico' }}</small></td>
                <td>{{ $item['codCitHija'] ?? 'Pendiente de cita hija' }}</td>
                <td><x-admin.badge :value="$item['estado']" /></td>
                <td><a href="{{ route('medico.derivaciones.show', $item['codDer']) }}" aria-label="Ver detalle de derivación {{ $item['codDer'] }}">Ver detalle</a></td>
            </tr>@endforeach
        </x-admin.table>
    @else
        <x-admin.empty title="No hay derivaciones en esta consulta" description="Las derivaciones se emiten desde una cita propia ya atendida." />
    @endif
    <x-admin.pagination :page="$page" :count="count($page['derivaciones'])" />
@endsection
