@extends('layouts.app')

@section('body_class', 'dashboard-page paciente-page')

@section('content')
    <header class="dashboard-header">
        <a class="brand" href="{{ route('paciente.home') }}" aria-label="Ir al inicio del paciente">
            <svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 5v30M5 20h30" /><path d="M11 11h18v18H11z" /></svg>
            <span>{{ config('clinic.identity.short_name') }}</span>
        </a>

        <div class="pac-user">
            <span class="pac-user__name">{{ session('nombre_usuario') }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="button button--secondary pac-btn-sm" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </header>

    <div class="pac-shell">
        <nav class="pac-nav" aria-label="Menú del paciente">
            @foreach ([
                ['paciente.home', 'Inicio'],
                ['paciente.citas.nueva', 'Agendar cita'],
                ['paciente.citas', 'Mis citas'],
                ['paciente.notificaciones', 'Notificaciones'],
                ['paciente.perfil', 'Mi perfil'],
            ] as [$ruta, $texto])
                <a href="{{ route($ruta) }}"
                   class="pac-nav__link {{ request()->routeIs($ruta) ? 'is-active' : '' }}"
                   @if (request()->routeIs($ruta)) aria-current="page" @endif>{{ $texto }}</a>
            @endforeach
        </nav>

        <main id="contenido-principal" class="pac-main">
            @include('layouts.alerts')

            @isset($errorServicio)
                @if ($errorServicio)
                    <div class="alert alert--error" role="alert">
                        <svg class="alert__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v5m0 4h.01M4.9 20h14.2a2 2 0 0 0 1.73-3L13.73 4.7a2 2 0 0 0-3.46 0L3.17 17a2 2 0 0 0 1.73 3Z" /></svg>
                        <div><strong>No pudimos cargar la información</strong><p>{{ $errorServicio }}</p></div>
                    </div>
                @endif
            @endisset

            @yield('panel')
        </main>
    </div>
@endsection
