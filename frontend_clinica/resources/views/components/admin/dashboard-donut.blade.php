@props(['rows', 'label', 'title'])
@php
    $total = array_sum(array_column($rows, 'value'));
    $offset = 0;
@endphp
<div class="ad-distribution">
    <svg class="ad-donut" viewBox="0 0 180 180" role="img" aria-label="{{ $title }}: {{ number_format($total, 0, ',', '.') }} {{ $label }}">
        <circle class="ad-donut-track" cx="90" cy="90" r="68" />
        <g transform="rotate(-90 90 90)">
            @foreach($rows as $row)
                @php($share = $total > 0 ? $row['value'] / $total * 100 : 0)
                @if($share > 0)
                    <circle class="ad-donut-segment ad-tone-{{ $loop->index }}" cx="90" cy="90" r="68" pathLength="100" stroke-dasharray="{{ $share }} {{ 100 - $share }}" stroke-dashoffset="{{ -$offset }}"><title>{{ $row['label'] }}: {{ $row['value'] }} ({{ number_format($share, 1, ',', '.') }} %)</title></circle>
                @endif
                @php($offset += $share)
            @endforeach
        </g>
        <text class="ad-donut-total" x="90" y="88" text-anchor="middle">{{ number_format($total, 0, ',', '.') }}</text>
        <text class="ad-donut-unit" x="90" y="109" text-anchor="middle">{{ $label }}</text>
    </svg>
    <dl class="ad-legend">
        @foreach($rows as $row)
            <div class="ad-tone-{{ $loop->index }}"><dt><span class="ad-swatch" aria-hidden="true"></span>{{ $row['label'] }}</dt><dd><strong>{{ number_format($row['value'], 0, ',', '.') }}</strong><span>{{ number_format($total > 0 ? $row['value'] / $total * 100 : 0, 1, ',', '.') }} %</span></dd></div>
        @endforeach
    </dl>
</div>
@if($total === 0)<p class="ad-chart-note">Sin registros en este período.</p>@endif
