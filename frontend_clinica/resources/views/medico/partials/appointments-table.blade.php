@props(['appointments', 'history' => false, 'overview' => false])
@if(count($appointments))
    <x-admin.table :headings="$overview ? ['Horario', 'Paciente', 'Especialidad', 'Estado', 'Acción'] : ['Cita y fecha', 'Horario', 'Paciente', 'Especialidad', 'Asistencia', 'Estado', 'Acción']" caption="Agenda del médico" scroll-hint="Desplaza para ver todas las columnas de la agenda.">
        @foreach($appointments as $appointment)
            <tr class="m-appointment">
                @unless($overview)<td data-label="Cita y fecha">{{ $appointment['codCit'] }}<small>{{ $appointment['fecha'] }}</small></td>@endunless
                <td data-label="Horario" class="m-time">{{ substr($appointment['horaInicio'], 0, 5) }}–{{ substr($appointment['horaFin'], 0, 5) }}</td>
                <td class="m-patient-name" data-label="Paciente">{{ $appointment['nombrePaciente'] }}<small>{{ $appointment['codPac'] }}</small></td>
                <td data-label="Especialidad">{{ $appointment['nombreEspecialidad'] }}</td>
                @unless($overview)<td data-label="Asistencia">{{ $appointment['checkinRegistrado'] ? 'Check-in registrado' : 'Pendiente de llegada' }}@if($appointment['horaLlegadaCheckin'])<small>{{ $appointment['horaLlegadaCheckin'] }}</small>@endif</td>@endunless
                <td class="m-appointment-state" data-label="Estado"><x-appointment-status :value="\App\Support\AppointmentPresentation::operationalState($appointment)" /></td>
                <td data-label="Acción"><a href="{{ route('medico.citas.show', $appointment['codCit']) }}" aria-label="Ver cita {{ $appointment['codCit'] }}">Ver detalle</a></td>
            </tr>
        @endforeach
    </x-admin.table>
@else
    <x-admin.empty title="No hay citas en esta consulta" description="Elige otra fecha para consultar más citas." />
@endif
