@if(count($page['incidencias']))
    <x-admin.table :headings="$sanctionList ? ['Código', 'Motivo', 'Aplicación', 'Vencimiento', 'Responsable', 'Vigencia'] : ['Código', 'Tipo', 'Motivo', 'Registro', 'Responsable', 'Cita']" :caption="$sanctionList ? 'Sanciones del paciente' : 'Incidencias del paciente'">
        @foreach($page['incidencias'] as $incident)<tr>
            <td>@if($sanctionList)<a href="{{ route('administracion.sanciones.show', [$record['codPac'], $incident['codInc']]) }}">{{ $incident['codInc'] }}</a>@else{{ $incident['codInc'] }}@endif</td>
            @if(!$sanctionList)<td>{{ str_replace('_', ' ', $incident['tipoIncidencia']) }}</td>@endif
            <td>{{ $incident['motivo'] }}</td><td>{{ $incident['fechaRegistro'] }}</td>
            @if($sanctionList)<td>{{ $incident['fechaFinSancion'] ?: '—' }}</td>@endif
            <td>{{ $incident['registradoPorUsu'] }}</td>
            <td>@if($sanctionList)<x-admin.badge :value="$incident['vigente'] ? 'Vigente' : 'No vigente'" />@else{{ $incident['codCit'] ?: '—' }}@endif</td>
        </tr>@endforeach
    </x-admin.table>
@else<x-admin.empty :title="$sanctionList ? 'Sin sanciones en este resultado' : 'Sin incidencias en este resultado'" description="El servicio no devolvió registros para los filtros actuales." />@endif
