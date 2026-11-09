@extends('layouts.app')

@section('title', 'Completar registro con Google | '.config('clinic.identity.name'))
@section('body_class', 'auth-page auth-page--compact')

@section('content')
    <main id="contenido-principal" class="compact-auth-shell">
        <a class="brand" href="{{ route('home') }}" aria-label="Volver al inicio">
            <svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 5v30M5 20h30" /><path d="M11 11h18v18H11z" /></svg>
            <span>{{ config('clinic.identity.short_name') }}</span>
        </a>

        <section class="compact-auth-card" aria-labelledby="google-registration-title">
            <a class="back-link" href="{{ route('register') }}">Volver al registro</a>
            <header class="form-heading">
                <h1 id="google-registration-title">Completa tu perfil de paciente</h1>
                <p>Google confirmó tu identidad. La clínica necesita estos datos para crear el perfil asistencial.</p>
            </header>

            @include('layouts.alerts')

            <div class="verification-states" role="status">
                <p><strong>Nombre:</strong> {{ $googleName }}</p>
                <p><strong>Correo:</strong> {{ $googleEmail }}</p>
            </div>

            <form class="auth-form" method="POST" action="{{ route('google.registration.store') }}" data-submit-form>
                @csrf
                <div class="field">
                    <label for="google_ci">Documento de identidad (CI)</label>
                    <input id="google_ci" name="ci" type="text" value="{{ old('ci') }}" maxlength="30" required>
                    @error('ci')<p class="field__error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="google_birth_date">Fecha de nacimiento</label>
                    <input id="google_birth_date" name="fecha_nacimiento" type="date" value="{{ old('fecha_nacimiento') }}" max="{{ now()->toDateString() }}" autocomplete="bday" required>
                    @error('fecha_nacimiento')<p class="field__error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="google_phone">Teléfono de contacto</label>
                    <input id="google_phone" name="telefono" type="tel" value="{{ old('telefono') }}" maxlength="25" autocomplete="tel" inputmode="tel" required>
                    @error('telefono')<p class="field__error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="google_city">Ciudad de residencia</label>
                    <input id="google_city" name="ciudad_residencia" type="text" value="{{ old('ciudad_residencia') }}" maxlength="100" autocomplete="address-level2" required>
                    @error('ciudad_residencia')<p class="field__error">{{ $message }}</p>@enderror
                </div>
                <p class="integration-note">Google no verifica tu teléfono. Solo se marcará el correo cuando la identidad OIDC lo confirme.</p>
                <button class="button button--primary button--full" type="submit" data-submit-button>Crear perfil de paciente</button>
            </form>
        </section>
    </main>
@endsection
