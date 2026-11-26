@props(['rows', 'unit' => 'pacientes', 'title' => 'Evolución diaria de pacientes atendidos'])
@php
    $maximum = max([0, ...array_column($rows, 'value')]);
    $rawStep = max(1, $maximum / 4);
    $magnitude = 10 ** floor(log10($rawStep));
    $step = $magnitude;
    foreach ([1, 2, 5, 10] as $multiple) {
        if ($multiple * $magnitude >= $rawStep) { $step = $multiple * $magnitude; break; }
    }
    $ceiling = max(4 * $step, (floor($maximum / $step) + 1) * $step);
    $count = count($rows);
    $points = [];
    $firstDate = $count > 0 ? new DateTimeImmutable($rows[0]['date']) : null;
    $days = $count > 1 ? $firstDate->diff(new DateTimeImmutable($rows[$count - 1]['date']))->days : 0;
    foreach ($rows as $index => $row) {
        $position = $days > 0 ? $firstDate->diff(new DateTimeImmutable($row['date']))->days / $days : 0.5;
        $points[] = ['x' => 42 + $position * 570, 'position' => $position, 'y' => 142 - $row['value'] / $ceiling * 124, 'row' => $row];
    }
    $labelStep = max(1, (int) ceil(max(1, $count - 1) / 5));
@endphp
<div class="ad-trend" data-dashboard-trend data-unit="{{ $unit }}">
    <svg viewBox="0 0 640 180" role="img" tabindex="0" aria-label="{{ $title }}. Usa las flechas izquierda y derecha para explorar los días, o abre Ver datos por día.">
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
            <circle @class(['ad-trend-point', 'ad-trend-point--single' => $count === 1, 'ad-trend-point--observed' => $unit === 'min']) cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="{{ $count === 1 || $unit === 'min' ? 4 : 9 }}" data-chart-point data-position="{{ $point['position'] }}" data-label="{{ $point['row']['date'] }}" data-value="{{ $point['row']['value'] }}"><title>{{ $point['row']['date'] }} · {{ number_format($point['row']['value'], $unit === 'min' ? 1 : 0, ',', '.') }} {{ $unit }}</title></circle>
        @endforeach
    </svg>
    <output class="ad-chart-tooltip" data-chart-tooltip aria-live="polite" aria-atomic="true" hidden></output>
</div>
@if($unit === 'min')
    <p class="ad-chart-note">Cada punto representa una fecha con observaciones válidas.</p>
@elseif($maximum === 0)
    <p class="ad-chart-note">No se registraron pacientes atendidos en este período.</p>
@else
    <p class="ad-chart-note">Cada persona se cuenta una vez por día.</p>
@endif
