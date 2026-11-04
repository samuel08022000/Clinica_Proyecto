@extends('layouts.app')

@section('title', 'Inicio | '.config('clinic.identity.name'))
@section('body_class', 'dashboard-page')

@section('content')
    <header class="dashboard-header">
        <a class="brand" href="{{ route('home') }}" aria-label="Ir al portal de la clínica">
            <svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 5v30M5 20h30" /><path d="M11 11h18v18H11z" /></svg>
            <span>{{ config('clinic.identity.short_name') }}</span>
        </a>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="button button--secondary" type="submit">Cerrar sesión</button>
        </form>
    </header>

    <main id="contenido-principal" class="dashboard-main">
        @include('layouts.alerts')

        <section class="dashboard-intro" aria-labelledby="dashboard-title">
            <div>
                <h1 id="dashboard-title">Inicio de {{ $user['roleName'] }}</h1>
                <p>Tu acceso está autenticado y limitado a las funciones del rol {{ $user['roleName'] }}.</p>
            </div>
            <span class="account-state">Cuenta {{ strtolower($user['accountStatus'] ?? 'activa') }}</span>
        </section>

        <section class="dashboard-account" aria-labelledby="account-title">
            <h2 id="account-title">Cuenta conectada</h2>
            <dl>
                <div>
                    <dt>Nombre</dt>
                    <dd>{{ $user['name'] }}</dd>
                </div>
                <div>
                    <dt>Correo</dt>
                    <dd>{{ $user['email'] }}</dd>
                </div>
                <div>
                    <dt>Rol autorizado</dt>
                    <dd>{{ $user['roleName'] }} <span>({{ $user['roleCode'] }})</span></dd>
                </div>
            </dl>
        </section>

        <p class="dashboard-note">Los módulos operativos de este perfil se implementarán en las siguientes fases.</p>
    </main>
@endsection
