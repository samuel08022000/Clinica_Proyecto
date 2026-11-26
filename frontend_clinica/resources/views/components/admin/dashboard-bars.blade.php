@props(['rows', 'statuses' => false, 'empty' => 'Sin registros en este período.', 'label' => null])
@php($maximum = max(array_merge([1], array_column($rows, 'value'))))
@if(count($rows))
    <ol @class(['ad-bars', 'ad-bars--states' => $statuses]) aria-label="{{ $label ?? ($statuses ? 'Cantidad de citas por estado' : 'Ranking de reservas por especialidad') }}">
        @foreach($rows as $row)
            <li @class(['ad-bar', 'ad-state-'.$loop->index => $statuses])>
                <div class="ad-bar-label"><span>{{ $row['label'] }}</span><strong>{{ number_format($row['value'], 0, ',', '.') }}</strong></div>
                <div class="ad-bar-track" aria-hidden="true"><span style="width: {{ $row['value'] / $maximum * 100 }}%"></span></div>
            </li>
        @endforeach
    </ol>
    @if(array_sum(array_column($rows, 'value')) === 0)<p class="ad-chart-note">{{ $empty }}</p>@endif
@else
    <p class="ad-empty">{{ $empty }}</p>
@endif
