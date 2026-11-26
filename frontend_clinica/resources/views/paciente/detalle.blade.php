@extends('layouts.paciente')

@section('title', 'Detalle de cita | '.config('clinic.identity.name'))

@section('panel')
    <section class="pac-head" aria-labelledby="pac-title">
        <h1 id="pac-title">Detalle de cita</h1>
        <a class="pac-more" href="{{ route('paciente.citas') }}">Volver a mis citas</a>
    </section>

    @include('paciente._cita', ['cita' => $cita, 'mostrarAcciones' => false])
    @if (! empty($qrBase64))
        <section class="pac-panel pac-pass" aria-labelledby="qr-cita-title">
            <div>
                <h2 id="qr-cita-title">Pase QR de confirmación</h2>
                <p>Presenta este código en recepción al llegar a la clínica.</p>
            </div>
            <div class="pac-qr-box__frame">
                <img width="216" height="216" src="data:image/png;base64,{{ $qrBase64 }}" alt="Código QR de cita {{ $cita['codCit'] }}">
            </div>
        </section>
    @endif
    <x-clinical-progress :state="\App\Support\AppointmentPresentation::operationalState($cita)" />
    @if(isset($cita['correoEstado']))
        <section class="pac-panel"><h2>Correo del pase QR</h2>
            <p>{{ match($cita['correoEstado']) {
                'Enviada' => 'Aceptado por el transporte de correo. Esto no acredita recepción en tu bandeja.',
                'EnProceso' => 'En proceso de envío.',
                'Fallida' => 'Se agotaron los reintentos. Contacta a Secretaría; tu pase sigue disponible aquí.',
                'Cancelada' => 'Envío cancelado por un cambio de la cita.',
                default => ($cita['correoIntentos'] ?? 0) > 0 ? 'Error recuperable. Hay un reintento programado.' : 'Pendiente de envío.',
            } }}</p>
            @if($cita['correoEstado'] === 'Pendiente' && $cita['estado'] === 'Confirmada')
                <form method="POST" action="{{ route('paciente.citas.correo-qr', $cita['codCit']) }}" data-qr-resend-form data-retry-until="{{ now()->getTimestamp() + ($qrRetryAfter ?? 0) }}">
                    @csrf
                    <button class="button button--secondary" data-qr-resend-button @disabled(($qrRetryAfter ?? 0) > 0)>{{ ($cita['correoIntentos'] ?? 0) > 0 ? 'Reintentar correo' : 'Enviar QR a mi correo' }}</button>
                    <p class="a-help" role="status" aria-live="polite" data-qr-resend-status>@if(($qrRetryAfter ?? 0) > 0)Podrás reintentar en {{ $qrRetryAfter }} segundos.@endif</p>
                </form>
            @endif
        </section>
    @endif

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
                            <textarea id="motivo" name="motivo" rows="3" maxlength="2000" required>{{ old('motivo') }}</textarea>
                        </div>
                        <label class="pac-confirm"><input type="checkbox" name="confirmacion" value="1" required> Confirmo que deseo cancelar esta cita.</label>
                        <button class="pac-link-danger" type="submit">Cancelar cita</button>
                    </form>
                </section>
            @endif
        </div>
    @endif
@endsection
