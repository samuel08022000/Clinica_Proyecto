@props(['appointment'])
<dl class="a-details">
    @foreach(['codCit' => 'Código de cita', 'nombrePaciente' => 'Paciente', 'nombreMedico' => 'Médico', 'nombreEspecialidad' => 'Especialidad', 'fecha' => 'Fecha'] as $key => $label)
        @if(isset($appointment[$key]))<div><dt>{{ $label }}</dt><dd>{{ $appointment[$key] }}</dd></div>@endif
    @endforeach
    <div><dt>Horario</dt><dd class="a-number">{{ substr($appointment['horaInicio'], 0, 5) }}–{{ substr($appointment['horaFin'], 0, 5) }}</dd></div>
    @if(isset($appointment['estado']))<div><dt>Estado</dt><dd><x-appointment-status :value="$appointment['estado']" /></dd></div>@endif
    @if(isset($appointment['canalReserva']))<div><dt>Canal</dt><dd>{{ $appointment['canalReserva'] }}</dd></div>@endif
    @if(filled($appointment['codSec'] ?? null))<div><dt>Secretario responsable</dt><dd>{{ $appointment['codSec'] }}</dd></div>@endif
</dl>
