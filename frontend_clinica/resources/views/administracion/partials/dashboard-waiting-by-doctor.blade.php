@php
    $waiting = $queues['espera_actual']['por_medico'];
    $doctors = array_filter($queues['medicos_espera'], fn ($doctor) => ($waiting[$doctor['cod_med']]['total'] ?? 0) > 0);
    $minutes = fn ($value) => $value === null ? '—' : number_format($value, 1, ',', '.').' min';
    usort($doctors, fn ($a, $b) => ($waiting[$b['cod_med']]['maximo'] ?? -1) <=> ($waiting[$a['cod_med']]['maximo'] ?? -1));
@endphp
<section class="a-panel ad-chart-panel ad-waiting-list" aria-labelledby="doctor-wait-title">
    <div class="ad-panel-heading">
        <div>
            <h2 id="doctor-wait-title">Espera por médico</h2>
            @if($doctors)<p>Ordenada por tiempo máximo de espera.</p>@endif
        </div>
    </div>
    @if($doctors)
        <ul class="ad-bars ad-queue-bars">
            @foreach($doctors as $doctor)
                @php($row = $waiting[$doctor['cod_med']])
                <li class="ad-doctor-row">
                    <div class="ad-doctor-identity">
                        <strong>{{ $doctor['nombre_completo'] }}</strong>
                        <span>{{ $row['total'] }} en espera</span>
                    </div>
                    <div class="ad-doctor-breakdown">
                        <div class="ad-bar-track ad-wait-track" aria-hidden="true">
                            @foreach(['dentro', 'fuera', 'sin_tiempo'] as $segment)
                                <span class="ad-wait-{{ $segment }}" style="width: {{ $row[$segment] / $row['total'] * 100 }}%"></span>
                            @endforeach
                        </div>
                        <p class="ad-chart-note">{{ $row['dentro'] }} dentro de la meta · {{ $row['fuera'] }} fuera · {{ $row['sin_tiempo'] }} sin tiempo válido</p>
                        @if($row['fuera'] > 0)<p class="ad-chart-note">Demora moderada {{ $row['moderada'] }} · Elevada {{ $row['elevada'] }}</p>@endif
                    </div>
                    <dl class="ad-doctor-times">
                        <div><dt>Dentro de la meta</dt><dd>{{ $row['porcentaje'] === null ? '—' : number_format($row['porcentaje'], 1, ',', '.').' %' }}</dd></div>
                        <div><dt>Promedio</dt><dd>{{ $minutes($row['promedio']) }}</dd></div>
                        <div><dt>Máximo</dt><dd>{{ $minutes($row['maximo']) }}</dd></div>
                    </dl>
                </li>
            @endforeach
        </ul>
    @else
        <p class="ad-empty">Sin pacientes en espera</p>
    @endif
</section>
