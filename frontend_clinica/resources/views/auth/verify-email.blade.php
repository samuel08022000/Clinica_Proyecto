@extends('layouts.app')

@section('title', 'Verificación de correo | '.config('clinic.identity.name'))
@section('body_class', 'auth-page auth-page--compact')

@section('content')
    <main id="contenido-principal" class="compact-auth-shell">
        <a class="brand" href="{{ route('home') }}" aria-label="Volver al inicio">
            <svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 5v30M5 20h30" /><path d="M11 11h18v18H11z" /></svg>
            <span>{{ config('clinic.identity.short_name') }}</span>
        </a>

        <section class="compact-auth-card verification-card" aria-labelledby="email-verification-title">
            <div class="verification-card__icon">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18v14H3z" /><path d="m3 6 9 7 9-7" /></svg>
            </div>
            <header class="form-heading">
                <h1 id="email-verification-title">Confirma tu correo</h1>
                <p>La confirmación por correo será un flujo separado de Google OAuth y de la verificación telefónica por OTP.</p>
            </header>
            <div class="verification-status" role="status">
                <span>Estado</span>
                <strong>No iniciado · backend pendiente</strong>
            </div>
            <button class="button button--primary button--full" type="button" disabled>Reenviar enlace</button>
            <a class="text-link verification-card__back" href="{{ route('login') }}">Volver al acceso</a>
        </section>
    </main>
@endsection
