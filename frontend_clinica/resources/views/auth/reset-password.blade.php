@extends('layouts.app')

@section('title', 'Nueva contraseña | '.config('clinic.identity.name'))
@section('body_class', 'auth-page auth-page--compact')

@section('content')
    <main id="contenido-principal" class="compact-auth-shell">
        <a class="brand" href="{{ route('home') }}" aria-label="Volver al inicio">
            <svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 5v30M5 20h30" /><path d="M11 11h18v18H11z" /></svg>
            <span>{{ config('clinic.identity.short_name') }}</span>
        </a>

        <section class="compact-auth-card" aria-labelledby="reset-title">
            <a class="back-link" href="{{ route('login') }}">Volver al acceso</a>
            <header class="form-heading">
                <h1 id="reset-title">Define una nueva contraseña</h1>
                <p>El token del enlace y la dirección de correo se validarán en Quarkus antes de realizar cualquier cambio.</p>
            </header>

            @include('layouts.alerts')

            <div class="service-notice" role="status">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v5m0 4h.01M4 4h16v16H4z" /></svg>
                <p><strong>Validación no disponible.</strong> Esta interfaz no modificará contraseñas hasta que exista el endpoint correspondiente.</p>
            </div>

            <form class="auth-form" method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="field">
                    <label for="reset_email">Correo electrónico</label>
                    <input id="reset_email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" inputmode="email" required>
                </div>
                <div class="field">
                    <label for="new_password">Nueva contraseña</label>
                    <div class="password-field">
                        <input id="new_password" name="password" type="password" autocomplete="new-password" minlength="10" required>
                        <button type="button" class="password-toggle" aria-controls="new_password" aria-pressed="false" data-password-toggle><span>Mostrar</span></button>
                    </div>
                </div>
                <div class="field">
                    <label for="new_password_confirmation">Confirmar contraseña</label>
                    <div class="password-field">
                        <input id="new_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="10" required>
                        <button type="button" class="password-toggle" aria-controls="new_password_confirmation" aria-pressed="false" data-password-toggle><span>Mostrar</span></button>
                    </div>
                </div>
                <button class="button button--primary button--full" type="submit" disabled>Guardar nueva contraseña</button>
            </form>
        </section>
    </main>
@endsection
