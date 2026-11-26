@extends('layouts.paciente')

@section('title', 'Mis citas | '.config('clinic.identity.name'))

@section('panel')
    <section class="pac-head" aria-labelledby="pac-title">
        <h1 id="pac-title">Mis citas</h1>
        <a class="button button--primary pac-btn-sm" href="{{ route('paciente.citas.nueva') }}">Agendar cita</a>
    </section>

    <div class="pac-tabs" role="navigation" aria-label="Filtrar citas">
        @foreach (['proximas' => 'Próximas', 'pasadas' => 'Pasadas', 'canceladas' => 'Canceladas'] as $clave => $texto)
            <a href="{{ route('paciente.citas', ['tab' => $clave]) }}"
               class="pac-tab {{ $tab === $clave ? 'is-active' : '' }}"
               @if ($tab === $clave) aria-current="page" @endif>
                {{ $texto }} <span>{{ $conteos[$clave] }}</span>
            </a>
        @endforeach
    </div>

    <section class="pac-list" aria-live="polite">
        @forelse ($citas as $cita)
            @include('paciente._cita', ['cita' => $cita, 'mostrarAcciones' => $tab === 'proximas'])
        @empty
            <div class="pac-empty">
                <p>
                    @if ($tab === 'proximas') No tienes citas próximas.
                    @elseif ($tab === 'pasadas') Todavía no tienes citas pasadas.
                    @else No tienes citas canceladas. @endif
                </p>
            </div>
        @endforelse
    </section>
@endsection
