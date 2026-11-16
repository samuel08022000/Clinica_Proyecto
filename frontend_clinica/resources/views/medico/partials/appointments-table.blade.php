@props(['appointments', 'history' => false, 'overview' => false])
@if(count($appointments))
    <x-admin.table :headings="$overview ? ['Horario', 'Paciente', 'Especialidad', 'Estado', 'Acción'] : ['Cita y fecha', 'Horario', 'Paciente', 'Especialidad', 'Asistencia', 'Estado', 'Acción']" caption="Agenda del médico" scroll-hint="Desplaza para ver todas las columnas de la agenda.">
        @foreach($appointments as $appointment)
            <tr>
                @unless($overview)<td>{{ $appointment['codCit'] }}<small>{{ $appointment['fecha'] }}</small></td>@endunless
                <td class="m-time">{{ substr($appointment['horaInicio'], 0, 5) }}–{{ substr($appointment['horaFin'], 0, 5) }}</td>
                <td>{{ $appointment['nombrePaciente'] }}<small>{{ $appointment['codPac'] }}</small></td>
                <td>{{ $appointment['nombreEspecialidad'] }}</td>
                @unless($overview)<td>{{ $appointment['checkinRegistrado'] ? 'Check-in registrado' : 'Pendiente de llegada' }}@if($appointment['horaLlegadaCheckin'])<small>{{ $appointment['horaLlegadaCheckin'] }}</small>@endif</td>@endunless
                <td><x-appointment-status :value="$appointment['estado']" /></td>
                <td><a href="{{ route('medico.citas.show', $appointment['codCit']) }}" aria-label="Ver cita {{ $appointment['codCit'] }}">Ver detalle</a></td>
            </tr>
        @endforeach
    </x-admin.table>
@else
    <x-admin.empty title="No hay citas en esta consulta" description="La agenda conserva su marco aunque no existan atenciones para la fecha seleccionada." />
@endif
