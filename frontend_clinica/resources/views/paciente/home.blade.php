@extends('layouts.paciente')

@section('title', 'Inicio | '.config('clinic.identity.name'))

@section('panel')
    <header class="pac-head">
        <h1>Hola, {{ \Illuminate\Support\Str::of($nombre)->explode(' ')->first() }}</h1>
        @if ($proxima)
            <a class="button button--primary" href="{{ route('paciente.citas.nueva') }}"><x-admin.icon name="plus" /> Agendar cita</a>
        @endif
    </header>

    <div class="pac-overview">
        <section class="pac-panel pac-next" aria-labelledby="proxima-title">
            <div class="pac-section-head">
                <h2 id="proxima-title">Tu próxima cita</h2>
                @if ($proxima)
                    <span class="pac-badge pac-badge--{{ $proxima['estadoClase'] }}">{{ $proxima['estadoTexto'] }}</span>
                @endif
            </div>

            @if ($proxima)
                <div class="pac-ticket">
                    <time class="pac-date" datetime="{{ $proxima['fecha'] }}" aria-label="{{ $proxima['fechaTexto'] }} de {{ $proxima['inicio']->year }}">
                        <span>{{ $proxima['inicio']->isoFormat('MMM') }}</span>
                        <strong>{{ $proxima['inicio']->day }}</strong>
                        <span>{{ $proxima['inicio']->year }}</span>
                    </time>
                    <div class="pac-ticket__body">
                        <h3>{{ $proxima['nombreEspecialidad'] }}</h3>
                        <p>{{ $proxima['nombreMedico'] }}</p>
                        <p class="pac-ticket__time"><x-admin.icon name="clock" /> {{ ucfirst($proxima['inicio']->isoFormat('dddd')) }} · {{ $proxima['horaTexto'] }}</p>
                    </div>
                </div>
                @if ($proxima['requiereReprogramacion'])
                    <p>La agenda del médico cambió. Consulta el detalle para reprogramar tu cita.</p>
                @endif
                <a class="pac-next__link" href="{{ route('paciente.citas.show', $proxima['codCit']) }}">
                    {{ $proxima['estado'] === 'Confirmada' && $proxima['esProxima'] && ! empty($proxima['qrDisponible']) ? 'Ver cita y pase QR' : 'Ver detalle de cita' }}
                    <x-admin.icon name="arrow" />
                </a>
            @else
                <div class="pac-next__empty">
                    <p>No tienes citas próximas.</p>
                    <a class="button button--primary" href="{{ route('paciente.citas.nueva') }}"><x-admin.icon name="plus" /> Agendar una cita</a>
                </div>
            @endif
        </section>

        <section class="pac-panel pac-summary" aria-labelledby="resumen-title">
            <h2 id="resumen-title">Mis citas</h2>
            <dl class="pac-stats">
                <div><dt>Próximas</dt><dd>{{ $totalProximas }}</dd></div>
                <div><dt>Atendidas</dt><dd>{{ $totalAtendidas }}</dd></div>
            </dl>
            <a class="pac-more" href="{{ route('paciente.citas') }}">Ver todas mis citas <x-admin.icon name="arrow" /></a>
        </section>
    </div>
    <section class="pac-account" aria-labelledby="cuenta-title">
        <h2 id="cuenta-title">Mi cuenta</h2>
        <div class="pac-account__links">
            <a href="{{ route('paciente.notificaciones') }}"><x-admin.icon name="bell" /><span><strong>Notificaciones</strong><small>Avisos sobre tus citas</small></span><x-admin.icon name="arrow" /></a>
            <a href="{{ route('paciente.perfil') }}"><x-admin.icon name="person" /><span><strong>Mi perfil</strong><small>Datos personales y de contacto</small></span><x-admin.icon name="arrow" /></a>
        </div>
    </section>
@endsection
