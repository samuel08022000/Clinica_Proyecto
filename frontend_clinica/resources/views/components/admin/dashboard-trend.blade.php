@props(['rows'])
@php
    $maximum = max(0, ...array_column($rows, 'value'));
    $rawStep = max(1, $maximum / 4);
    $magnitude = 10 ** floor(log10($rawStep));
    $step = $magnitude;
    foreach ([1, 2, 5, 10] as $multiple) {
        if ($multiple * $magnitude >= $rawStep) { $step = $multiple * $magnitude; break; }
    }
    $ceiling = max(4 * $step, (floor($maximum / $step) + 1) * $step);
    $count = count($rows);
    $points = [];
    foreach ($rows as $index => $row) {
        $points[] = ['x' => $count > 1 ? 42 + $index / ($count - 1) * 570 : 327, 'y' => 142 - $row['value'] / $ceiling * 124, 'row' => $row];
    }
    $labelStep = max(1, (int) ceil(max(1, $count - 1) / 5));
@endphp
<div class="ad-trend" data-dashboard-trend>
    <svg viewBox="0 0 640 180" role="img" tabindex="0" aria-label="Evolución diaria de pacientes atendidos. Usa las flechas izquierda y derecha para explorar los días, o abre Ver datos por día.">
        @for($value = 0; $value <= $ceiling; $value += $step)
            @php($y = 142 - $value / $ceiling * 124)
            <line class="ad-gridline" x1="42" x2="612" y1="{{ $y }}" y2="{{ $y }}" />
            <text class="ad-axis" x="32" y="{{ $y + 4 }}" text-anchor="end">{{ number_format($value, 0, ',', '.') }}</text>
        @endfor
        @if($count > 1)<polyline class="ad-trend-line" points="{{ implode(' ', array_map(fn ($point) => $point['x'].','.$point['y'], $points)) }}" />@endif
        @foreach($points as $point)
            @if($count === 1 || $loop->index % $labelStep === 0)
                <text class="ad-axis" data-chart-tick data-index="{{ $loop->index }}" x="{{ $point['x'] }}" y="168" text-anchor="{{ $loop->first && $count > 1 ? 'start' : ($loop->last && $count > 1 ? 'end' : 'middle') }}">{{ $point['row']['label'] }}</text>
            @endif
            <circle @class(['ad-trend-point', 'ad-trend-point--single' => $count === 1]) cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="{{ $count === 1 ? 4 : 9 }}" data-chart-point data-label="{{ $point['row']['date'] }}" data-value="{{ $point['row']['value'] }}"><title>{{ $point['row']['date'] }}: {{ $point['row']['value'] }} pacientes</title></circle>
        @endforeach
    </svg>
    <output class="ad-chart-tooltip" data-chart-tooltip aria-live="polite" aria-atomic="true" hidden></output>
</div>
@if($maximum === 0)<p class="ad-chart-note">No se registraron pacientes atendidos en este período.</p>@else<p class="ad-chart-note">Cada persona se cuenta una vez por día.</p>@endif
