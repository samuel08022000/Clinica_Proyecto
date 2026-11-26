@extends('layouts.paciente')

@section('title', 'Mi perfil | '.config('clinic.identity.name'))

@section('panel')
    <section class="pac-head" aria-labelledby="pac-title">
        <h1 id="pac-title">Mi perfil</h1>
    </section>

    @if ($perfil)
        <div class="pac-grid">
            <section class="pac-panel" aria-labelledby="datos-title">
                <h2 id="datos-title">Datos personales</h2>
                <dl class="pac-data">
                    <div><dt>Nombre</dt><dd>{{ $perfil['nombre'] }}</dd></div>
                    <div><dt>Correo</dt><dd>{{ $perfil['email'] }}</dd></div>
                    <div><dt>Documento (CI)</dt><dd>{{ $perfil['ci'] }}</dd></div>
                    <div><dt>Fecha de nacimiento</dt><dd>{{ \Illuminate\Support\Carbon::parse($perfil['fechaNacimiento'])->format('d/m/Y') }}</dd></div>
                </dl>
                <p class="field__help">Para corregir estos datos, contacta con la clínica.</p>
            </section>

            <section class="pac-panel" aria-labelledby="contacto-title">
                <h2 id="contacto-title">Datos de contacto</h2>
                <dl class="pac-data">
                    <div><dt>Teléfono</dt><dd>{{ $perfil['telefono'] }}</dd></div>
                    <div><dt>Ciudad de residencia</dt><dd>{{ $perfil['ciudadResidencia'] }}</dd></div>
                    <div><dt>Estado de cuenta</dt><dd>{{ $perfil['estadoCuenta'] }}</dd></div>
                    <div><dt>Estado para reservar</dt><dd>{{ $perfil['estadoReserva'] }}</dd></div>
                </dl>
                <p class="field__help">Para actualizar tus datos de contacto, comunícate con la clínica.</p>
            </section>
        </div>
    @endif
@endsection
