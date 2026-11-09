@extends('layouts.app')

@section('title', 'Recuperar contraseña | '.config('clinic.identity.name'))
@section('body_class', 'auth-page auth-page--compact')

@section('content')
    <main id="contenido-principal" class="compact-auth-shell">
        <a class="brand" href="{{ route('home') }}" aria-label="Volver al inicio">
            <svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 5v30M5 20h30" /><path d="M11 11h18v18H11z" /></svg>
            <span>{{ config('clinic.identity.short_name') }}</span>
        </a>

        <section class="compact-auth-card" aria-labelledby="recovery-title">
            <a class="back-link" href="{{ route('login') }}">Volver al acceso</a>
            <header class="form-heading">
                <h1 id="recovery-title">Recupera tu contraseña</h1>
                <p>El flujo enviará un enlace temporal al correo registrado. No corresponde al OTP de registro.</p>
            </header>

            @include('layouts.alerts')

            <form class="auth-form" method="POST" action="{{ route('password.email') }}">
                @csrf
                <div class="field">
                    <label for="recovery_email">Correo electrónico</label>
                    <input id="recovery_email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" inputmode="email" required>
                </div>
                @error('email')<p id="recovery-email-error" class="field__error">{{ $message }}</p>@enderror
                <button class="button button--primary button--full" type="submit" data-submit-button>Enviar enlace de recuperación</button>
                <p class="integration-note">Por privacidad, la respuesta será la misma exista o no una cuenta asociada.</p>
            </form>
        </section>
    </main>
@endsection
