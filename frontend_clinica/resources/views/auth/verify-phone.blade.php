@extends('layouts.app')

@section('title', 'Verificar teléfono | '.config('clinic.identity.name'))
@section('body_class', 'auth-page auth-page--registration')

@section('content')
    <main id="contenido-principal" class="registration-shell">
        <header class="registration-header">
            <a class="brand" href="{{ route('home') }}" aria-label="Volver al inicio">
                <svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 5v30M5 20h30" /><path d="M11 11h18v18H11z" /></svg>
                <span>{{ config('clinic.identity.short_name') }}</span>
            </a>
            <a class="text-link" href="{{ route('login') }}">Volver al inicio de sesión</a>
        </header>

        <div class="registration-layout registration-layout--verification">
            <aside class="registration-intro">
                <h1>Confirma tu teléfono</h1>
                <p>El código tiene seis dígitos, vence en cinco minutos y solo puede utilizarse una vez.</p>
                <div class="registration-fact">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 5 3.4 8.2 8 9 4.6-.8 8-4 8-9V7l-8-4Z" /><path d="m9 12 2 2 4-5" /></svg>
                    <p>Nunca te pediremos el código por llamada, correo o mensajería instantánea.</p>
                </div>
            </aside>

            <section class="registration-card" aria-labelledby="phone-verification-title">
                <h2 id="phone-verification-title">Verificación telefónica</h2>
                @include('layouts.alerts')

                @if ($hasChallenge)
                    <p class="form-step__intro">Enviamos un código a <strong>{{ $maskedDestination }}</strong>.</p>

                    <form method="POST" action="{{ route('verification.phone.verify') }}" data-submit-form>
                        @csrf
                        <div class="field field--otp">
                            <label for="otp_code">Código de verificación</label>
                            <input
                                id="otp_code"
                                name="code"
                                type="text"
                                value=""
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                pattern="[0-9]{6}"
                                minlength="6"
                                maxlength="6"
                                aria-describedby="otp-help @error('code') otp-code-error @enderror"
                                @error('code') aria-invalid="true" @enderror
                                required
                                autofocus>
                            <p id="otp-help" class="field__help">Puedes escribir o pegar los seis dígitos.</p>
                            @error('code')<p id="otp-code-error" class="field__error">{{ $message }}</p>@enderror
                        </div>

                        <button class="button button--primary button--full" type="submit" data-submit-button data-loading-label="Verificando…">
                            <span data-button-label>Verificar teléfono</span>
                        </button>
                    </form>

                    <form method="POST" action="{{ route('verification.phone.resend') }}" class="otp-resend-form" data-resend-form data-resend-at="{{ $resendAvailableAt }}">
                        @csrf
                        <button class="text-button" type="submit" data-resend-button>Reenviar código</button>
                        <p class="field__help" aria-live="polite" data-resend-status></p>
                    </form>
                @else
                    <p class="form-step__intro">Si ya creaste la cuenta pero perdiste la sesión de verificación, confirma tus credenciales para solicitar otro código.</p>

                    <form method="POST" action="{{ route('verification.phone.request') }}" data-submit-form>
                        @csrf
                        <div class="field">
                            <label for="verification_email">Correo electrónico</label>
                            <input id="verification_email" name="email" type="email" value="{{ old('email') }}" maxlength="254" autocomplete="email" required>
                        </div>
                        <div class="field">
                            <label for="verification_password">Contraseña</label>
                            <div class="password-field">
                                <input id="verification_password" name="password" type="password" autocomplete="current-password" required>
                                <button type="button" class="password-toggle" aria-controls="verification_password" aria-pressed="false" data-password-toggle><span>Mostrar</span></button>
                            </div>
                        </div>
                        <button class="button button--primary button--full" type="submit" data-submit-button data-loading-label="Solicitando…">
                            <span data-button-label>Solicitar código</span>
                        </button>
                    </form>
                @endif
            </section>
        </div>
    </main>
@endsection
