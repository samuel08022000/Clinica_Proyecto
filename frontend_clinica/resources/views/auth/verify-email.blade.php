@extends('layouts.app')

@section('title', 'Verificar correo | '.config('clinic.identity.name'))
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
                <h1>Confirma tu correo</h1>
                <p>El código tiene seis dígitos, vence en cinco minutos y solo puede utilizarse una vez.</p>
                <div class="registration-fact">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 5 3.4 8.2 8 9 4.6-.8 8-4 8-9V7l-8-4Z" /><path d="m9 12 2 2 4-5" /></svg>
                    <p>Nunca te pediremos el código por llamada o mensajería instantánea.</p>
                </div>
            </aside>

            <section class="registration-card" aria-labelledby="email-verification-title">
                <h2 id="email-verification-title">Verificación de correo</h2>
                @include('layouts.alerts')

                @if ($hasChallenge)
                    @if (strtoupper((string) $deliveryMode) === 'DEMO')
                        <div class="delivery-notice delivery-notice--demo" role="status">
                            <strong>Modo DEMO local</strong>
                            <p>No se envió un correo real. Consulta el código únicamente con el script local protegido.</p>
                        </div>
                    @elseif ($deliveryConfirmed)
                        <div class="delivery-notice" role="status">
                            <strong>Correo entregado al proveedor SMTP</strong>
                            <p>Revisa la bandeja de entrada y la carpeta de correo no deseado.</p>
                        </div>
                    @endif

                    <p class="form-step__intro">Código destinado a <strong>{{ $maskedDestination }}</strong>.</p>

                    <form method="POST" action="{{ route('verification.email.verify') }}" data-submit-form data-otp-form>
                        @csrf
                        <input type="hidden" name="code" value="" data-otp-combined>

                        <fieldset class="form-step field--otp">
                            <legend class="field__label">Código de verificación</legend>
                            <div class="otp-fields" aria-label="Código OTP de seis dígitos" data-otp-fields>
                                @for ($digit = 0; $digit < 6; $digit++)
                                    <input
                                        type="text"
                                        name="code_digits[]"
                                        value=""
                                        inputmode="numeric"
                                        pattern="[0-9]"
                                        maxlength="1"
                                        class="otp-digit"
                                        data-otp-digit
                                        data-index="{{ $digit }}"
                                        aria-label="Dígito {{ $digit + 1 }} de 6"
                                        aria-describedby="otp-help @error('code') otp-code-error @enderror"
                                        @if ($digit === 0) autocomplete="one-time-code" autofocus @else autocomplete="off" @endif
                                        required>
                                @endfor
                            </div>
                            <p id="otp-help" class="field__help">Escribe o pega los seis dígitos. Puedes usar las flechas para moverte.</p>
                            @error('code')<p id="otp-code-error" class="field__error">{{ $message }}</p>@enderror
                        </fieldset>

                        <p class="otp-expiry" aria-live="polite" data-otp-expiry data-expires-at="{{ $otpExpiresAt }}"></p>

                        <button id="btn-verificar-otp" class="button button--primary button--full" type="submit" data-submit-button data-loading-label="Verificando…">
                            <span data-button-label>Verificar correo</span>
                        </button>
                    </form>

                    <form method="POST" action="{{ route('verification.email.resend') }}" class="otp-resend-form" data-resend-form data-resend-at="{{ $resendAvailableAt }}">
                        @csrf
                        <button class="text-button" type="submit" data-resend-button>Reenviar código</button>
                        <p class="field__help" aria-live="polite" data-resend-status></p>
                    </form>
                @else
                    <p class="form-step__intro">Si ya creaste la cuenta pero perdiste la sesión de verificación, confirma tus credenciales para solicitar otro código.</p>

                    <form method="POST" action="{{ route('verification.email.request') }}" data-submit-form>
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
