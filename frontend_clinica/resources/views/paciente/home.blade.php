@extends('layouts.paciente')

@section('title', 'Inicio | '.config('clinic.identity.name'))

@section('panel')
    <section class="pac-head" aria-labelledby="pac-title">
        <h1 id="pac-title">Hola, {{ \Illuminate\Support\Str::of($nombre)->explode(' ')->first() }}</h1>
        <p>Aquí ves tu próxima cita y desde aquí puedes agendar o revisar las que ya tienes.</p>
    </section>

    <section class="pac-panel" aria-labelledby="proxima-title">
        <h2 id="proxima-title">Tu próxima cita</h2>

        @if ($proxima)
            @include('paciente._cita', ['cita' => $proxima, 'mostrarAcciones' => false])
            <a class="pac-more" href="{{ route('paciente.citas') }}">Ver todas mis citas</a>
        @else
            <div class="pac-empty">
                <p>No tienes citas próximas.</p>
                <a class="button button--primary" href="{{ route('paciente.citas.nueva') }}">Agendar una cita</a>
            </div>
        @endif
    </section>

    <div class="pac-grid">
        <section class="pac-panel" aria-labelledby="resumen-title">
            <h2 id="resumen-title">Resumen</h2>
            <dl class="pac-stats">
                <div><dt>Citas próximas</dt><dd>{{ $totalProximas }}</dd></div>
                <div><dt>Citas atendidas</dt><dd>{{ $totalAtendidas }}</dd></div>
            </dl>
        </section>

        <section class="pac-panel" aria-labelledby="acciones-title">
            <h2 id="acciones-title">¿Qué necesitas hacer?</h2>
            <div class="pac-actions">
                <a class="button button--primary" href="{{ route('paciente.citas.nueva') }}">Agendar cita</a>
                <a class="button button--secondary" href="{{ route('paciente.perfil') }}">Consultar mis datos</a>
            </div>
        </section>
    </div>
@endsection
