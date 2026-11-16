@extends('layouts.paciente')

@section('title', 'No se pudo completar la operación | '.config('clinic.identity.name'))

@section('panel')
    <section class="pac-panel" aria-labelledby="error-title">
        <h1 id="error-title">No se pudo completar la operación</h1>
        <p>{{ $message }}</p>
        <a class="button button--secondary" href="{{ route('paciente.home') }}">Volver al inicio</a>
    </section>
@endsection
