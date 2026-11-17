@extends('layouts.paciente')

@section('title', 'Detalle de cita | '.config('clinic.identity.name'))

@section('panel')
    <section class="pac-head" aria-labelledby="pac-title">
        <h1 id="pac-title">Detalle de cita</h1>
        <a class="pac-more" href="{{ route('paciente.citas') }}">Volver a mis citas</a>
    </section>

    @include('paciente._cita', ['cita' => $cita, 'mostrarAcciones' => false])

    <section class="pac-panel" aria-labelledby="datos-cita-title">
        <h2 id="datos-cita-title">Información de la cita</h2>
        <dl class="pac-data">
            <div><dt>Código de cita</dt><dd>{{ $cita['codCit'] }}</dd></div>
            <div><dt>Especialidad</dt><dd>{{ $cita['nombreEspecialidad'] }}</dd></div>
            <div><dt>Médico</dt><dd>{{ $cita['nombreMedico'] }}</dd></div>
            <div><dt>Fecha</dt><dd>{{ $cita['fechaTexto'] }}</dd></div>
            <div><dt>Horario</dt><dd>{{ $cita['horaTexto'] }}</dd></div>
            <div><dt>Estado</dt><dd>{{ $cita['estadoTexto'] }}</dd></div>
        </dl>
    </section>

    @if ($cita['reprogramable'] || $cita['cancelable'])
        <div class="pac-grid">
            @if ($cita['reprogramable'])
                <section class="pac-panel" aria-labelledby="reprogramar-title">
                    <h2 id="reprogramar-title">Reprogramar</h2>
                    <p>Busca otro horario disponible para esta especialidad.</p>
                    <a class="button button--secondary" href="{{ route('paciente.citas.reprogramar', $cita['codCit']) }}">Buscar otro horario</a>
                </section>
            @endif

            @if ($cita['cancelable'])
                <section id="cancelar-cita" class="pac-panel" aria-labelledby="cancelar-title">
                    <h2 id="cancelar-title">Cancelar cita</h2>
                    <form method="POST" action="{{ route('paciente.citas.cancel', $cita['codCit']) }}" class="pac-booking"
                          onsubmit="return confirm('¿Seguro que quieres cancelar esta cita?');">
                        @csrf
                        <div class="field">
                            <label for="motivo">Motivo de cancelación</label>
                            <textarea id="motivo" name="motivo" rows="3" maxlength="2000" required></textarea>
                        </div>
                        <label><input type="checkbox" name="confirmacion" value="1" required> Confirmo que deseo cancelar esta cita.</label>
                        <button class="pac-link-danger" type="submit">Cancelar cita</button>
                    </form>
                </section>
            @endif
        </div>
    @endif
@endsection

@if (! empty($qrBase64))
        <section class="pac-panel" aria-labelledby="qr-cita-title">
            <h2 id="qr-cita-title">Pase QR de confirmación</h2>
            <div class="pac-qr-box">
                <div class="pac-qr-box__frame">
                    <img src="data:image/png;base64,{{ $qrBase64 }}"
                         alt="Código QR de cita {{ $cita['codCit'] }}">
                </div>
                <p class="pac-qr-box__note">
                    Presenta este código en recepción al llegar a la clínica para confirmar tu identidad. También lo enviamos a tu correo electrónico.
                </p>
            </div>
        </section>
    @endif