@extends('layouts.secretaria')
@section('reception_title', 'Derivaciones pendientes')
@section('breadcrumb', 'Derivaciones')
@section('reception_content')
    <x-admin.heading title="Derivaciones" description="Programa interconsultas pendientes sin alterar su origen ni destinatario.">
        <x-admin.button variant="secondary" :href="route('secretaria.reservas.create')">Reserva tradicional</x-admin.button>
    </x-admin.heading>
    <form method="GET" action="{{ route('secretaria.derivaciones.index') }}" class="a-filter a-filter--compact">
        <x-admin.field name="estado" label="Estado" type="select" :value="$filters['estado']" :options="['Pendiente' => 'Pendiente', 'Agendada' => 'Agendada', 'Completada' => 'Completada', 'Cancelada' => 'Cancelada']" />
        <x-admin.button>Aplicar filtro</x-admin.button>
    </form>
    @if(count($page['derivaciones']))
        <x-admin.table :headings="['Derivación', 'Paciente y origen', 'Destino', 'Estado', 'Acción']" caption="Derivaciones disponibles para Secretaría">
            @foreach($page['derivaciones'] as $item)
                <tr>
                    <td>{{ $item['codDer'] }}<small>{{ $item['fechaDerivacion'] }}</small></td>
                    <td>{{ $item['nombrePaciente'] }}<small>{{ $item['codCitOrigen'] }}</small></td>
                    <td>{{ $item['nombreEspecialidadDestino'] }}<small>{{ $item['nombreMedicoDestino'] ?? 'Cualquier médico habilitado' }}</small></td>
                    <td><x-admin.badge :value="$item['estado']" /></td>
                    <td><a href="{{ route('secretaria.derivaciones.show', $item['codDer']) }}" aria-label="Ver detalle de derivación {{ $item['codDer'] }}">Ver detalle</a></td>
                </tr>
            @endforeach
        </x-admin.table>
    @else
        <x-admin.empty title="No hay derivaciones en este estado" description="Las nuevas interconsultas médicas aparecerán aquí cuando estén disponibles." />
    @endif
    <x-admin.pagination :page="$page" :count="count($page['derivaciones'])" />
@endsection
