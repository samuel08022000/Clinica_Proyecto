<article class="pac-cita">
    <div class="pac-cita__when">
        <strong>{{ $cita['fechaTexto'] }}</strong>
        <span>{{ $cita['horaTexto'] }}</span>
    </div>

    <div class="pac-cita__body">
        <h3>{{ $cita['nombreEspecialidad'] }}</h3>
        <p class="pac-cita__doctor">{{ $cita['nombreMedico'] }}</p>
        @if ($cita['requiereReprogramacion'])
            <p class="pac-cita__motivo">Un cambio en la agenda del médico afectó esta cita. Contacta con la clínica para reprogramarla.</p>
        @endif
    </div>

    <div class="pac-cita__side">
        <span class="pac-badge pac-badge--{{ $cita['estadoClase'] }}">{{ $cita['estadoTexto'] }}</span>

        @if (! empty($mostrarAcciones) && $cita['cancelable'])
            <form method="POST" action="{{ route('paciente.citas.cancel', $cita['codCit']) }}"
                  onsubmit="return confirm('¿Seguro que quieres cancelar esta cita?');">
                @csrf
                <button class="pac-link-danger" type="submit">Cancelar cita</button>
            </form>
        @elseif (! empty($mostrarAcciones) && $cita['esProxima'])
            <p class="pac-cita__nota">Faltan menos de 24 horas: para cambios, contacta con la clínica.</p>
        @endif
    </div>
</article>
