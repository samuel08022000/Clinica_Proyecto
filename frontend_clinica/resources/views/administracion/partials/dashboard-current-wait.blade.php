@php
    $waiting = $queues['espera_actual'];
    $current = $waiting['global'];
    $minutes = fn ($value) => $value === null ? '—' : number_format($value, 1, ',', '.').' min';
@endphp
<section class="a-panel ad-chart-panel ad-live-panel" aria-labelledby="current-wait-title">
    <div class="ad-panel-heading">
        <div>
            <h2 id="current-wait-title">Sala de espera actual</h2>
            <p>Toda la clínica · {{ $waiting['actualizado'] }} · Bolivia</p>
        </div>
        <a class="ad-text-link ad-queue-link" href="{{ route('administracion.colas.index', array_filter($queues['filtros_aplicados'])) }}">Ver teoría de colas<x-admin.icon /></a>
    </div>
    <dl class="ad-queue-metrics">
        <div><dt>En espera</dt><dd>{{ $current['total'] }}</dd></div>
        <div><dt>En atención</dt><dd>{{ $current['en_atencion'] }}</dd></div>
        <div><dt>Espera promedio</dt><dd>{{ $minutes($current['promedio']) }}</dd></div>
        <div><dt>Espera máxima</dt><dd>{{ $minutes($current['maximo']) }}</dd></div>
        <div @class(['ad-metric-alert' => $current['fuera'] > 0])><dt>Fuera de la meta</dt><dd>{{ $current['fuera'] }}</dd></div>
        <div><dt>Sin tiempo válido</dt><dd>{{ $current['sin_tiempo'] }}</dd></div>
    </dl>
    <p class="ad-chart-note">Meta administrativa de {{ $waiting['meta'] }} min. Demora moderada hasta {{ $waiting['demora_elevada'] }} min y elevada por encima de ese límite.</p>
</section>
