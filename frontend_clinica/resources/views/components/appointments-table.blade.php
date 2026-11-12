@props(['appointments', 'history' => false, 'overview' => false])
@if(count($appointments))
    <x-admin.table :headings="$overview ? ['Horario', 'Paciente', 'Médico y especialidad', 'Estado', 'Acciones'] : ['Cita y fecha', 'Horario', 'Paciente', 'Médico y especialidad', 'Estado', 'Canal', 'Acciones']" caption="Citas administrativas" scroll-hint="Desplaza para ver todas las columnas.">
        @foreach($appointments as $appointment)
            <tr>
                @unless($overview)<td>{{ $appointment['codCit'] }}<small>{{ $appointment['fecha'] }}</small></td>@endunless
                <td class="r-time">{{ substr($appointment['horaInicio'], 0, 5) }}–{{ substr($appointment['horaFin'], 0, 5) }}</td>
                <td><a href="{{ route('secretaria.pacientes.show', $appointment['codPac']) }}">{{ $appointment['nombrePaciente'] }}</a><small>{{ $appointment['codPac'] }}</small></td>
                <td>{{ $appointment['nombreMedico'] }}<small>{{ $appointment['nombreEspecialidad'] }}</small></td>
                <td><x-appointment-status :value="$appointment['estado']" />
                    @if($history && filled($appointment['motivoCancelacion'] ?? $appointment['motivoInasistencia'] ?? null))<small>{{ $appointment['motivoCancelacion'] ?? $appointment['motivoInasistencia'] }}</small>@endif
                </td>
                @unless($overview)<td>{{ $appointment['canalReserva'] }}</td>@endunless
                <td><a href="{{ route('secretaria.citas.show', $appointment['codCit']) }}" aria-label="Ver cita {{ $appointment['codCit'] }}">Ver detalle</a></td>
            </tr>
        @endforeach
    </x-admin.table>
@else
    <x-admin.empty title="No hay citas en esta consulta" description="Prueba otra fecha o inicia una nueva reserva para un paciente habilitado." />
@endif
