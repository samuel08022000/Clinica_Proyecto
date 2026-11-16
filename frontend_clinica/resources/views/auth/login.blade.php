@extends('layouts.app')

@section('title', 'Iniciar sesión | '.config('clinic.identity.name'))
@section('body_class', 'auth-page')

@section('content')
    <main id="contenido-principal" class="auth-shell">
        <section class="auth-visual" aria-labelledby="auth-visual-title">
            <a class="brand brand--light" href="{{ route('home') }}" aria-label="Volver al inicio">
                <svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 5v30M5 20h30" /><path d="M11 11h18v18H11z" /></svg>
                <span>{{ config('clinic.identity.short_name') }}</span>
            </a>
            <img
                src="{{ asset('images/clinic/appointment-calendar.png') }}"
                alt="Profesional de salud revisa una agenda de citas"
                width="1120"
                height="1400"
                fetchpriority="high"
            >
            <div class="auth-visual__scrim" aria-hidden="true"></div>
            <div class="auth-visual__copy">
                <h1 id="auth-visual-title">Tu portal de salud</h1>
                <p>Consulta y organiza tus citas desde un acceso protegido por roles.</p>
            </div>
        </section>

        <section class="auth-panel" aria-labelledby="login-title">
            <div class="auth-panel__inner">
                <div class="auth-mobile-brand">
                    <a class="brand" href="{{ route('home') }}">
                        <svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 5v30M5 20h30" /><path d="M11 11h18v18H11z" /></svg>
                        <span>{{ config('clinic.identity.short_name') }}</span>
                    </a>
                </div>

                <header class="form-heading">
                    <h2 id="login-title">Iniciar sesión</h2>
                    <p>Usa el correo registrado en el sistema.</p>
                </header>

                @include('layouts.alerts')

                <form class="auth-form" method="POST" action="{{ route('login.attempt') }}" data-submit-form>
                    @csrf
                    <div class="field">
                        <label for="email">Correo electrónico</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            autocomplete="username"
                            inputmode="email"
                            required
                            autofocus
                            aria-describedby="email-help @error('email') email-error @enderror"
                            @error('email') aria-invalid="true" @enderror
                        >
                        <p id="email-help" class="field__help">El backend actual identifica usuarios mediante correo electrónico.</p>
                        @error('email')
                            <p id="email-error" class="field__error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="password">Contraseña</label>
                        <div class="password-field">
                            <input
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                required
                                @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                            >
                            <button type="button" class="password-toggle" aria-controls="password" aria-pressed="false" data-password-toggle>
                                <span>Mostrar</span>
                            </button>
                        </div>
                        @error('password')
                            <p id="password-error" class="field__error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-support">
                        <span>La sesión se valida de forma segura con el servicio de la clínica.</span>
                        <a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
                    </div>

                    <button class="button button--primary button--full" type="submit" data-submit-button>
                        <span data-button-label>Iniciar sesión</span>
                        <span class="button__loader" aria-hidden="true"></span>
                    </button>
                </form>

                <div class="oauth-separator"><span>Otra opción</span></div>
                <a class="button button--google button--full" href="{{ $googleEnabled ? route('google.login') : '#' }}" @unless($googleEnabled) aria-disabled="true" tabindex="-1" @endunless aria-describedby="google-status">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.2c0-.7-.1-1.4-.2-2H12v3.7h5a4.3 4.3 0 0 1-1.9 2.8v2.4h3.1c1.8-1.7 2.8-4.1 2.8-6.9Z" /><path d="M12 21c2.5 0 4.6-.8 6.2-2.2l-3.1-2.4c-.8.6-1.9.9-3.1.9a5.4 5.4 0 0 1-5.1-3.7H3.7V16A9.3 9.3 0 0 0 12 21Z" /><path d="M6.9 13.6a5.6 5.6 0 0 1 0-3.3V7.9H3.7a9 9 0 0 0 0 8.1l3.2-2.4Z" /><path d="M12 6.6c1.4 0 2.6.5 3.6 1.4l2.7-2.7A9 9 0 0 0 3.7 7.9l3.2 2.4A5.4 5.4 0 0 1 12 6.6Z" /></svg>
                    Continuar con Google
                </a>
                <p id="google-status" class="integration-note">
                    {{ $googleEnabled ? 'Google validará tu identidad; la clínica mantendrá su propia sesión segura.' : 'Disponible cuando se configuren las credenciales OAuth locales.' }}
                </p>

                <p class="integration-note">Si esta instalación aún no tiene cuentas de personal, solicita al responsable su alta autorizada. No hay accesos predeterminados.</p>

                <p class="auth-switch">¿Aún no tienes cuenta? <a href="{{ route('register') }}">Registrarte como paciente</a></p>
            </div>
        </section>
    </main>
@endsection
