@extends('layouts.paciente')

@section('title', 'Agendar cita | '.config('clinic.identity.name'))

@section('panel')
    <section class="pac-head" aria-labelledby="pac-title">
        <h1 id="pac-title">Agendar cita</h1>
    </section>

    {{-- Paso 1 --}}
    <section class="pac-panel" aria-labelledby="paso1">
        <h2 id="paso1">1. Especialidad</h2>
        <form method="GET" action="{{ route('paciente.citas.nueva') }}#paso2" class="pac-inline-form">
            <div class="field">
                <label for="esp">¿Con qué especialidad quieres atenderte?</label>
                <select id="esp" name="codEsp" required>
                    <option value="" disabled @selected(! $esp)>Selecciona una especialidad</option>
                    @foreach ($especialidades as $e)
                        <option value="{{ $e['codEsp'] }}" @selected($esp === $e['codEsp'])>{{ $e['nombre'] }}</option>
                    @endforeach
                </select>
            </div>
            <button class="button {{ $esp ? 'button--secondary' : 'button--primary' }}" type="submit">
                {{ $esp ? 'Cambiar' : 'Continuar' }}
            </button>
        </form>
    </section>

    {{-- Paso 2 --}}
    @if ($esp)
        <section class="pac-panel" aria-labelledby="paso2">
            <h2 id="paso2">2. Médico y fecha</h2>
            <form method="GET" action="{{ route('paciente.citas.nueva') }}#paso3" class="pac-inline-form">
                <input type="hidden" name="codEsp" value="{{ $esp }}">
                <div class="field">
                    <label for="med">Médico</label>
                    <select id="med" name="codMed">
                        <option value="">Cualquiera disponible</option>
                        @foreach ($medicos as $m)
                            <option value="{{ $m['codMed'] }}" @selected($med === $m['codMed'])>{{ $m['nombre'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="fecha">Fecha</label>
                    <input id="fecha" type="date" name="fecha" value="{{ $fecha }}" min="{{ $fechaMin }}" max="{{ $fechaMax }}" required>
                    <span class="field__help">Puedes agendar desde hoy hasta dentro de 14 días.</span>
                </div>
                <button class="button button--primary" type="submit">Buscar horarios</button>
            </form>
        </section>
    @endif

    {{-- Paso 3 --}}
    @if ($esp && $fecha)
        <section class="pac-panel" aria-labelledby="paso3">
            <h2 id="paso3">3. Horario</h2>

            @if ($cupos->isEmpty())
                <div class="pac-empty">
                    <p>No hay horarios disponibles para esa fecha. Prueba con otro día u otro médico.</p>
                </div>
            @else
                <form method="POST" action="{{ route('paciente.citas.store') }}" class="pac-booking">
                    @csrf

                    @foreach ($cupos as $nombreMedico => $lista)
                        <fieldset class="pac-slots">
                            <legend>{{ $nombreMedico }}</legend>
                            <div class="pac-slots__grid">
                                @foreach ($lista as $cupo)
                                    <label class="pac-slot">
                                        <input type="radio" name="codSlot" value="{{ $cupo['codSlot'] }}"
                                               @checked(old('codSlot') === $cupo['codSlot']) required>
                                        <span>{{ substr($cupo['horaInicio'], 0, 5) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach

                    <button class="button button--primary" type="submit">Confirmar cita</button>
                </form>
            @endif
        </section>
    @endif
@endsection
