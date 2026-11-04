@extends('layouts.app')

@section('title', 'Registro de paciente | '.config('clinic.identity.name'))
@section('body_class', 'auth-page auth-page--registration')

@section('content')
    <main id="contenido-principal" class="registration-shell">
        <header class="registration-header">
            <a class="brand" href="{{ route('home') }}" aria-label="Volver al inicio">
                <svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 5v30M5 20h30" /><path d="M11 11h18v18H11z" /></svg>
                <span>{{ config('clinic.identity.short_name') }}</span>
            </a>
            <a class="text-link" href="{{ route('login') }}">Ya tengo una cuenta</a>
        </header>

        <div class="registration-layout">
            <aside class="registration-intro">
                <h1>Crea tu cuenta de paciente</h1>
                <p>La información se divide en grupos breves. Nada se almacena en el navegador mientras el backend de registro no esté disponible.</p>
                <div class="registration-fact">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 5 3.4 8.2 8 9 4.6-.8 8-4 8-9V7l-8-4Z" /><path d="m9 12 2 2 4-5" /></svg>
                    <p>La verificación telefónica usa un OTP independiente del correo y del acceso con Google.</p>
                </div>
            </aside>

            <section class="registration-card" aria-labelledby="registration-form-title">
                <h2 id="registration-form-title" class="visually-hidden">Formulario de registro</h2>

                <div class="step-progress" aria-label="Progreso del registro" data-step-progress>
                    <div class="step-progress__track"><span data-progress-bar></span></div>
                    <ol>
                        <li aria-current="step" data-step-label>Identidad</li>
                        <li data-step-label>Contacto</li>
                        <li data-step-label>Verificación</li>
                    </ol>
                </div>

                @include('layouts.alerts')

                @unless ($registrationAvailable)
                    <div class="service-notice" role="status">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v5m0 4h.01M4 4h16v16H4z" /></svg>
                        <p><strong>Registro preparado, API pendiente.</strong> El formulario no creará cuentas hasta que Quarkus exponga registro y OTP.</p>
                    </div>
                @endunless

                <form method="POST" action="{{ route('register.store') }}" data-multistep-form novalidate>
                    @csrf

                    <fieldset class="form-step" data-step-panel>
                        <legend>Identidad</legend>
                        <p class="form-step__intro">Datos necesarios para vincular la cuenta con el perfil de paciente.</p>

                        <div class="field">
                            <label for="ci">Documento de identidad (CI)</label>
                            <input id="ci" name="ci" type="text" value="{{ old('ci') }}" maxlength="30" autocomplete="off" required>
                        </div>
                        <div class="field">
                            <label for="nombre_completo">Nombre completo</label>
                            <input id="nombre_completo" name="nombre_completo" type="text" value="{{ old('nombre_completo') }}" maxlength="150" autocomplete="name" required>
                        </div>
                        <div class="field">
                            <label for="fecha_nacimiento">Fecha de nacimiento</label>
                            <input id="fecha_nacimiento" name="fecha_nacimiento" type="date" value="{{ old('fecha_nacimiento') }}" max="{{ now()->toDateString() }}" autocomplete="bday" required>
                        </div>

                        <div class="step-actions step-actions--end">
                            <button class="button button--primary" type="button" data-step-next>Continuar</button>
                        </div>
                    </fieldset>

                    <fieldset class="form-step" data-step-panel hidden>
                        <legend>Contacto y seguridad</legend>
                        <p class="form-step__intro">Usaremos estos datos para el acceso y, cuando se configure, para las notificaciones.</p>

                        <div class="field-grid">
                            <div class="field">
                                <label for="telefono">Teléfono</label>
                                <input id="telefono" name="telefono" type="tel" value="{{ old('telefono') }}" maxlength="25" autocomplete="tel" inputmode="tel" required>
                            </div>
                            <div class="field">
                                <label for="ciudad_residencia">Ciudad de residencia</label>
                                <input id="ciudad_residencia" name="ciudad_residencia" type="text" value="{{ old('ciudad_residencia') }}" maxlength="100" autocomplete="address-level2" required>
                            </div>
                        </div>
                        <div class="field">
                            <label for="register_email">Correo electrónico</label>
                            <input id="register_email" name="email" type="email" value="{{ old('email') }}" maxlength="254" autocomplete="email" inputmode="email" required>
                        </div>
                        <div class="field-grid">
                            <div class="field">
                                <label for="register_password">Contraseña</label>
                                <div class="password-field">
                                    <input id="register_password" name="password" type="password" autocomplete="new-password" minlength="10" aria-describedby="password-rules" required>
                                    <button type="button" class="password-toggle" aria-controls="register_password" aria-pressed="false" data-password-toggle><span>Mostrar</span></button>
                                </div>
                            </div>
                            <div class="field">
                                <label for="password_confirmation">Confirmar contraseña</label>
                                <div class="password-field">
                                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="10" required>
                                    <button type="button" class="password-toggle" aria-controls="password_confirmation" aria-pressed="false" data-password-toggle><span>Mostrar</span></button>
                                </div>
                            </div>
                        </div>
                        <p id="password-rules" class="field__help">Mínimo 10 caracteres, con mayúsculas, minúsculas y números. Se permite pegar desde un gestor de contraseñas.</p>

                        <div class="step-actions">
                            <button class="button button--secondary" type="button" data-step-back>Volver</button>
                            <button class="button button--primary" type="button" data-step-next>Revisar verificación</button>
                        </div>
                    </fieldset>

                    <fieldset class="form-step" data-step-panel hidden>
                        <legend>Verificación telefónica</legend>
                        <p class="form-step__intro">La base de datos contempla un código de seis dígitos, tres intentos y cinco minutos de vigencia.</p>

                        <div class="otp-status" role="status">
                            <div><span>Vigencia</span><strong>Se iniciará al enviar el OTP</strong></div>
                            <div><span>Intentos</span><strong>3 disponibles · no iniciado</strong></div>
                        </div>

                        <div class="otp-fields" aria-label="Código OTP de seis dígitos">
                            @for ($digit = 1; $digit <= 6; $digit++)
                                <input type="text" inputmode="numeric" maxlength="1" aria-label="Dígito {{ $digit }}" disabled data-otp-input>
                            @endfor
                        </div>

                        <div class="verification-states">
                            <p><strong>Teléfono:</strong> verificación pendiente de API y proveedor SMS.</p>
                            <p><strong>Correo:</strong> se confirmará mediante un flujo separado cuando el backend lo exponga.</p>
                        </div>

                        <button class="text-button" type="button" disabled>Reenviar código</button>

                        <div class="step-actions">
                            <button class="button button--secondary" type="button" data-step-back>Volver</button>
                            <button class="button button--primary" type="submit" disabled aria-describedby="registration-disabled">Confirmar registro</button>
                        </div>
                        <p id="registration-disabled" class="integration-note">La confirmación se habilitará al integrar registro, envío y validación OTP en Quarkus.</p>
                    </fieldset>
                </form>
            </section>
        </div>
    </main>
@endsection
