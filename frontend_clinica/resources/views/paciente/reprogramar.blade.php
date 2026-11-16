@extends('layouts.paciente')

@section('title', 'Reprogramar cita | '.config('clinic.identity.name'))

@section('panel')
    <section class="pac-head" aria-labelledby="pac-title">
        <h1 id="pac-title">Reprogramar cita</h1>
        <p>Elige una nueva fecha y horario para {{ $cita['nombreEspecialidad'] }}.</p>
    </section>

    <section class="pac-panel" aria-labelledby="paso1">
        <h2 id="paso1">1. Médico y fecha</h2>
        <form method="GET" action="{{ route('paciente.citas.reprogramar', $cita['codCit']) }}" class="pac-inline-form">
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
                <span class="field__help">Puedes elegir desde hoy hasta dentro de 14 días.</span>
            </div>
            <button class="button button--primary" type="submit">Buscar horarios</button>
        </form>
    </section>

    @if ($fecha)
        <section class="pac-panel" aria-labelledby="paso2">
            <h2 id="paso2">2. Nuevo horario</h2>
            @if ($cupos->isEmpty())
                <div class="pac-empty">
                    <p>No hay horarios disponibles para esa fecha. Prueba con otro día u otro médico.</p>
                </div>
            @else
                <form method="POST" action="{{ route('paciente.citas.reschedule', $cita['codCit']) }}" class="pac-booking">
                    @csrf
                    @foreach ($cupos as $nombreMedico => $lista)
                        <fieldset class="pac-slots">
                            <legend>{{ $nombreMedico }}</legend>
                            <div class="pac-slots__grid">
                                @foreach ($lista as $cupo)
                                    <label class="pac-slot">
                                        <input type="radio" name="codSlotNuevo" value="{{ $cupo['codSlot'] }}" required>
                                        <span>{{ substr($cupo['horaInicio'], 0, 5) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                    <div class="field">
                        <label for="motivo">Motivo de reprogramación</label>
                        <textarea id="motivo" name="motivo" rows="3" maxlength="2000" required></textarea>
                    </div>
                    <label><input type="checkbox" name="confirmacion" value="1" required> Confirmo que deseo reprogramar esta cita.</label>
                    <button class="button button--primary" type="submit">Confirmar reprogramación</button>
                </form>
            @endif
        </section>
    @endif
@endsection
