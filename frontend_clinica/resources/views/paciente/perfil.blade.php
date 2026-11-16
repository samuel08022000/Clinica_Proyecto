@extends('layouts.paciente')

@section('title', 'Mi perfil | '.config('clinic.identity.name'))

@section('panel')
    <section class="pac-head" aria-labelledby="pac-title">
        <h1 id="pac-title">Mi perfil</h1>
    </section>

    @if ($perfil)
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
            <form method="POST" action="{{ route('paciente.perfil.update') }}" class="pac-booking">
                @csrf
                @method('PUT')

                <div class="field-grid">
                    <div class="field">
                        <label for="telefono">Teléfono</label>
                        <input id="telefono" name="telefono" type="tel" autocomplete="tel" maxlength="25" required
                               value="{{ old('telefono', $perfil['telefono']) }}">
                    </div>
                    <div class="field">
                        <label for="ciudad_residencia">Ciudad de residencia</label>
                        <input id="ciudad_residencia" name="ciudad_residencia" type="text" autocomplete="address-level2" maxlength="100" required
                               value="{{ old('ciudad_residencia', $perfil['ciudadResidencia']) }}">
                    </div>
                </div>

                <button class="button button--primary" type="submit">Guardar cambios</button>
            </form>
        </section>
    @endif
@endsection
