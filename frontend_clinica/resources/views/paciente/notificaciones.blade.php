@extends('layouts.paciente')
@section('title', 'Notificaciones')
@section('panel')
    <section class="pac-head"><h1>Notificaciones de citas</h1></section>
    @forelse($avisos as $aviso)
        <article class="pac-panel">
            <h2>{{ str_replace('_', ' ', $aviso['tipo_alerta']) }}</h2>
            <p>{{ $aviso['mensaje'] }}</p>
            <p>{{ $aviso['creado_en'] }}</p>
            @if($aviso['cod_cit'])<a class="pac-more" href="{{ route('paciente.citas.show', $aviso['cod_cit']) }}">Consultar cita</a>@endif
        </article>
    @empty
        <section class="pac-panel"><p>No tienes avisos administrativos por ahora.</p></section>
    @endforelse
@endsection
