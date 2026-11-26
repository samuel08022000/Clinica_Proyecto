@php
    $scope = $queues['resumen_global'];
    $utilization = $scope['utilizacion'];
    $congested = count(array_filter($queues['por_medico'], fn ($row) => in_array($row['utilizacion']['estado'], ['alerta_congestion', 'saturado'], true)));
    $minutes = fn ($value) => $value === null ? '—' : number_format($value, 1, ',', '.').' min';
    $percent = fn ($value) => $value === null ? '—' : number_format($value, 1, ',', '.').' %';
@endphp
<div class="ad-reports-grid">
    <section class="a-panel ad-chart-panel" aria-labelledby="doctor-utilization-title">
        <div class="ad-panel-heading"><div><h3 id="doctor-utilization-title">Utilización estimada por médico</h3><p>{{ $scope['ambito'] }} · {{ $period['label'] }}</p></div></div>
        <dl class="ad-queue-metrics">
            <div><dt>{{ $scope['ambito'] === 'Clínica completa' ? 'Utilización global estimada' : 'Utilización estimada del ámbito' }}</dt><dd>{{ $percent($utilization['valor']) }}</dd><dd>{{ $utilization['etiqueta'] }}</dd></div>
            <div><dt>Médicos con alta congestión o saturación</dt><dd>{{ $congested }}</dd></div>
        </dl>
        @include('administracion.partials.utilization-bars', ['rows' => $queues['por_medico'], 'showDoctors' => true])
        <p class="ad-chart-note">ρ estima la carga operativa de la agenda a partir de las llegadas y la capacidad de servicio del período. El conteo en espera corresponde a la captura actual.</p>
    </section>
    <section class="a-panel ad-chart-panel" aria-labelledby="waiting-trend-title">
        <div class="ad-panel-heading"><div><h3 id="waiting-trend-title">Evolución de la espera observada</h3><p>Minutos promedio desde el check-in hasta el inicio de atención · {{ $period['label'] }}</p></div></div>
        @if(count($queues['evolucion_espera']))
            <x-admin.dashboard-trend :rows="$queues['evolucion_espera']" unit="min" title="Evolución diaria de la espera observada" />
            <details class="ad-data-table"><summary>Ver datos por día</summary><div class="ad-data-scroll"><table>
                <caption>Espera observada por fecha de la cita</caption>
                <thead><tr><th scope="col">Fecha</th><th scope="col">Promedio</th><th scope="col">Muestra</th></tr></thead>
                <tbody>@foreach($queues['evolucion_espera'] as $row)<tr><th scope="row">{{ $row['date'] }}</th><td>{{ $minutes($row['value']) }}</td><td>{{ $row['sample'] }}</td></tr>@endforeach</tbody>
            </table></div></details>
        @else
            <p class="ad-empty">Sin registros válidos de inicio de atención en el período.</p>
        @endif
    </section>
    <section class="a-panel ad-chart-panel" aria-labelledby="specialty-congestion-title">
        <div class="ad-panel-heading"><div><h3 id="specialty-congestion-title">Congestión por especialidad</h3><p>Utilización estimada · {{ $period['label'] }}</p></div></div>
        @include('administracion.partials.utilization-bars', ['rows' => $queues['por_especialidad'], 'showDoctors' => false])
    </section>
</div>
