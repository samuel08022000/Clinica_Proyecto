@extends('layouts.paciente')
@section('title', 'Notificaciones | '.config('clinic.identity.name'))
@section('panel')
    <section class="pac-head"><h1>Notificaciones de citas</h1></section>
    @forelse($avisos as $aviso)
        <article class="pac-panel pac-notice">
            <div class="pac-section-head">
                <h2>{{ match ($aviso['tipo_alerta']) {
                    'Confirmacion' => 'Cita confirmada',
                    'Recordatorio_24h' => 'Recordatorio de cita',
                    'Cita_Cancelada' => 'Cita cancelada',
                    'Cita_Afectada' => 'Cambio en la agenda',
                    default => str_replace('_', ' ', $aviso['tipo_alerta']),
                } }}</h2>
                @php($fechaAviso = \Illuminate\Support\Carbon::parse($aviso['creado_en'])->timezone('America/La_Paz')->locale('es'))
                <time datetime="{{ $fechaAviso->toIso8601String() }}">{{ $fechaAviso->isoFormat('D MMM YYYY · HH:mm') }}</time>
            </div>
            <p>{{ $aviso['mensaje'] }}</p>
            @if($aviso['cod_cit'])<a class="pac-more" href="{{ route('paciente.citas.show', $aviso['cod_cit']) }}">Consultar cita</a>@endif
        </article>
    @empty
        <section class="pac-panel"><p>No tienes avisos administrativos por ahora.</p></section>
    @endforelse
@endsection
