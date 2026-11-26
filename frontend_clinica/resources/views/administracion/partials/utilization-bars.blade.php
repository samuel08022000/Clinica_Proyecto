@php
    usort($rows, fn ($a, $b) => ($b['utilizacion']['valor'] ?? -1) <=> ($a['utilizacion']['valor'] ?? -1));
    $scale = max([100, ...array_column(array_column($rows, 'utilizacion'), 'valor')]);
    $groups = array_filter([array_slice($rows, 0, 5), array_slice($rows, 5)]);
@endphp
@forelse($groups as $group)
    @if(!$loop->first)
        <details class="ad-data-table ad-report-more">
            <summary>{{ $showDoctors ? 'Ver más médicos' : 'Ver más especialidades' }} ({{ count($group) }})</summary>
    @endif
    <ul class="ad-bars ad-queue-bars">
    @foreach($group as $row)
        @php($usage = $row['utilizacion'])
        <li class="ad-bar">
            <div class="ad-bar-label"><strong>{{ $row['nombre'] }}</strong><span>{{ $percent($usage['valor']) }}</span></div>
            @if($usage['valor'] !== null)
                <div class="ad-bar-track ad-usage-{{ $usage['estado'] }}" aria-hidden="true"><span style="width: {{ $usage['valor'] / $scale * 100 }}%"></span></div>
            @endif
            <p class="ad-chart-note">{{ $usage['etiqueta'] }}@if($showDoctors) · {{ $row['especialidades_texto'] }} · {{ $row['en_espera_hoy'] }} en espera @endif</p>
        </li>
    @endforeach
    </ul>
    @if(!$loop->first)</details>@endif
@empty
    <p class="ad-empty">Sin resultados para los filtros seleccionados.</p>
@endforelse
