@extends('layouts.app')

@section('body_class', 'paciente-page')

@section('content')
    <header class="pac-topbar">
        <div class="pac-topbar__inner">
            <a class="brand" href="{{ route('paciente.home') }}" aria-label="Ir al inicio del paciente">
                <svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 5v30M5 20h30" /><path d="M11 11h18v18H11z" /></svg>
                <span>{{ config('clinic.identity.short_name') }}<small>Portal del paciente</small></span>
            </a>

            <div class="pac-user">
                <span class="pac-user__name">{{ session('nombre_usuario') }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="pac-signout" type="submit"><x-admin.icon name="logout" /> Cerrar sesión</button>
                </form>
            </div>
        </div>
    </header>

    <div class="pac-shell">
        <nav class="pac-nav" aria-label="Menú del paciente">
            @foreach ([
                ['paciente.home', 'home', 'Inicio', 'Inicio'],
                ['paciente.citas', 'calendar', 'Mis citas', 'Mis citas'],
                ['paciente.citas.nueva', 'plus', 'Agendar cita', 'Agendar'],
                ['paciente.notificaciones', 'bell', 'Notificaciones', 'Avisos'],
                ['paciente.perfil', 'person', 'Mi perfil', 'Perfil'],
            ] as [$ruta, $icono, $texto, $corto])
                @php($enSeccion = request()->routeIs($ruta) || ($ruta === 'paciente.citas' && request()->routeIs('paciente.citas.show', 'paciente.citas.reprogramar')))
                <a href="{{ route($ruta) }}"
                   class="pac-nav__link"
                   @if ($enSeccion) aria-current="{{ request()->routeIs($ruta) ? 'page' : 'location' }}" @endif>
                    <x-admin.icon :name="$icono" />
                    <span class="pac-nav__full">{{ $texto }}</span><span class="pac-nav__short">{{ $corto }}</span>
                </a>
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

            @if(request()->routeIs('paciente.home', 'paciente.citas', 'paciente.citas.show', 'paciente.notificaciones'))
                <p class="a-help" role="status" data-clinical-live-status hidden></p>
                <div data-clinical-live-content>@yield('panel')</div>
            @else
                @yield('panel')
            @endif
        </main>
    </div>
@endsection
