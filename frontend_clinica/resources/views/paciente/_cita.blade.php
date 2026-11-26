<article class="pac-cita">
    <div class="pac-cita__when">
        <strong>{{ $cita['fechaTexto'] }}</strong>
        <span>{{ $cita['horaTexto'] }}</span>
    </div>

    <div class="pac-cita__body">
        <h2>{{ $cita['nombreEspecialidad'] }}</h2>
        <p class="pac-cita__doctor">{{ $cita['nombreMedico'] }}</p>
        @if ($cita['requiereReprogramacion'])
            <p class="pac-cita__motivo">Un cambio en la agenda del médico afectó esta cita. Contacta con la clínica para reprogramarla.</p>
        @endif
    </div>

    <div class="pac-cita__side">
        <span class="pac-badge pac-badge--{{ $cita['estadoClase'] }}">{{ $cita['estadoTexto'] }}</span>

        @unless (request()->routeIs('paciente.citas.show'))
            <a class="pac-more" href="{{ route('paciente.citas.show', $cita['codCit']) }}">Ver detalle <x-admin.icon name="arrow" /></a>
        @endunless
        @if (! empty($mostrarAcciones) && $cita['cancelable'])
            <a class="pac-link-danger" href="{{ route('paciente.citas.show', $cita['codCit']) }}#cancelar-cita">Cancelar cita</a>
        @elseif (! empty($mostrarAcciones) && $cita['esProxima'])
            <p class="pac-cita__nota">Esta cita ya no admite cambios desde el portal.</p>
        @endif
    </div>
</article>
