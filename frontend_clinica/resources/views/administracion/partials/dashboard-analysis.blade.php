@php
    $preset = $period['preset'] ?? ($filters['periodo'] ?? 'mes');
    $oldPreset = old('periodo');
    if (is_string($oldPreset) && in_array($oldPreset, ['hoy', 'semana', 'mes', 'personalizado'], true)) { $preset = $oldPreset; }
    $fromInput = old('desde', $period['from'] ?? ($filters['desde'] ?? ''));
    $toInput = old('hasta', $period['to'] ?? ($filters['hasta'] ?? ''));
@endphp
<section class="ad-analysis" aria-labelledby="dashboard-analysis-title">
    <h2 id="dashboard-analysis-title">Actividad por período</h2>
    <form method="GET" action="{{ route('administracion.home') }}" class="ad-toolbar" data-dashboard-filter>
        <input type="hidden" name="vista" value="analisis">
        <div class="ad-period-control">
            <label for="periodo">Período</label>
            <select id="periodo" name="periodo" data-dashboard-period @error('periodo') aria-invalid="true" aria-describedby="periodo-error" @enderror>
                @foreach(['hoy' => 'Hoy', 'semana' => 'Esta semana', 'mes' => 'Este mes', 'personalizado' => 'Personalizado'] as $value => $label)
                    <option value="{{ $value }}" @selected($preset === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('periodo')<span class="ad-field-error" id="periodo-error">{{ $message }}</span>@enderror
        </div>
        <div class="ad-custom-dates" data-dashboard-dates>
            <div class="ad-period-control"><label for="desde">Desde</label><input type="date" id="desde" name="desde" value="{{ is_string($fromInput) ? $fromInput : '' }}" @error('desde') aria-invalid="true" aria-describedby="desde-error" @enderror>@error('desde')<span class="ad-field-error" id="desde-error">{{ $message }}</span>@enderror</div>
            <div class="ad-period-control"><label for="hasta">Hasta</label><input type="date" id="hasta" name="hasta" value="{{ is_string($toInput) ? $toInput : '' }}" @error('hasta') aria-invalid="true" aria-describedby="hasta-error" @enderror>@error('hasta')<span class="ad-field-error" id="hasta-error">{{ $message }}</span>@enderror</div>
        </div>
        @if($queues)
            <details class="ad-filter-extras" @if(!empty($filters['especialidad']) || !empty($filters['medico']) || $errors->hasAny(['especialidad', 'medico'])) open @endif>
                <summary>Filtros de colas</summary>
                <div class="ad-filter-fields">
                    @foreach(['especialidad' => ['Especialidad', 'especialidades', 'codEsp', 'nombreEspecialidad'], 'medico' => ['Médico', 'medicos', 'codMed', 'nombreCompleto']] as $field => [$label, $catalog, $code, $name])
                        <div class="ad-period-control">
                            <label for="dashboard-{{ $field }}">{{ $label }}</label>
                            <select id="dashboard-{{ $field }}" name="{{ $field }}">
                            <option value="">{{ $field === 'especialidad' ? 'Todas' : 'Todos' }}</option>
                                @foreach($queues['catalogos'][$catalog] as $option)
                                    <option value="{{ $option[$code] }}" @selected(old($field, $filters[$field] ?? null) === $option[$code])>{{ $option[$name] }}</option>
                                @endforeach
                            </select>
                            @error($field)<span class="ad-field-error">{{ $message }}</span>@enderror
                        </div>
                    @endforeach
                </div>
            </details>
        @else
            @error('especialidad')<p class="ad-field-error">{{ $message }}</p>@enderror
            @error('medico')<p class="ad-field-error">{{ $message }}</p>@enderror
        @endif
        <x-admin.button variant="secondary" data-dashboard-submit>Actualizar</x-admin.button>
        @if($dashboard)<p class="ad-freshness"><span>{{ $period['label'] }}</span><small>Actualizado {{ $dashboard['updatedAt'] }} · Bolivia</small></p>@endif
    </form>
</section>
@if($dashboard)
    <div class="ad-main-grid">
        <section class="a-panel ad-chart-panel" aria-labelledby="daily-title">
            <div class="ad-panel-heading"><div><h2 id="daily-title">Pacientes atendidos por día</h2><p>{{ $number($totals['attendedPatients']) }} pacientes distintos en el período</p></div><span class="ad-unit">Pacientes</span></div>
            <x-admin.dashboard-trend :rows="$dashboard['daily']" />
            <details class="ad-data-table"><summary>Ver datos por día</summary><div class="ad-data-scroll"><table><caption>Pacientes atendidos, contados una vez por día</caption><thead><tr><th scope="col">Fecha</th><th scope="col">Pacientes</th></tr></thead><tbody>
                @foreach($dashboard['daily'] as $row)<tr><th scope="row">{{ $row['date'] }}</th><td>{{ $number($row['value']) }}</td></tr>@endforeach
            </tbody></table></div></details>
        </section>
        <section class="a-panel ad-chart-panel" aria-labelledby="specialties-title">
            <div class="ad-panel-heading"><div><h2 id="specialties-title">Especialidades más solicitadas</h2><p>Reservas según la fecha de la cita</p></div></div>
            <x-admin.dashboard-bars :rows="array_slice($dashboard['specialties'], 0, 5)" empty="No hay reservas en este período." />
            <p class="ad-chart-note">Excluye citas reemplazadas por reprogramación.</p>
            @if(count($dashboard['specialties']) > 5)
                <details class="ad-data-table"><summary>Ver las {{ count($dashboard['specialties']) }} especialidades</summary><div class="ad-data-scroll"><table><caption>Reservas por especialidad</caption><thead><tr><th scope="col">Especialidad</th><th scope="col">Reservas</th></tr></thead><tbody>
                    @foreach($dashboard['specialties'] as $row)<tr><th scope="row">{{ $row['label'] }}</th><td>{{ $number($row['value']) }}</td></tr>@endforeach
                </tbody></table></div></details>
            @endif
        </section>
    </div>
    <div class="ad-secondary-grid">
        <section class="a-panel ad-chart-panel" aria-labelledby="states-title">
            <div class="ad-panel-heading"><div><h2 id="states-title">Estado de las citas</h2><p>{{ $number($totals['appointments']) }} citas en el período</p></div></div>
            <x-admin.dashboard-bars :rows="$dashboard['states']" :statuses="true" />
        </section>
        <section class="a-panel ad-chart-panel" aria-labelledby="channels-title">
            <div class="ad-panel-heading"><div><h2 id="channels-title">Canales de reserva</h2><p>¿Cómo se reservaron las citas?</p></div></div>
            <x-admin.dashboard-donut :rows="$dashboard['channels']" label="citas" title="Citas por canal de reserva" />
        </section>
        <section class="a-panel ad-chart-panel" aria-labelledby="referrals-title">
            <div class="ad-panel-heading"><div><h2 id="referrals-title">Derivaciones</h2><p>Creadas en el período seleccionado</p></div></div>
            <x-admin.dashboard-donut :rows="$dashboard['referrals']" label="derivaciones" title="Derivaciones por estado actual" />
        </section>
    </div>
    <div class="ad-period-summary"><p><strong>{{ $number($totals['newPatients']) }}</strong> nuevos pacientes registrados en el período.</p><a href="{{ route('administracion.pacientes.index') }}" class="ad-text-link">Consultar pacientes<x-admin.icon /></a></div>
    @if($queues)
        <section class="ad-section" aria-labelledby="dashboard-capacity-title">
            <h2 id="dashboard-capacity-title">Capacidad y tiempos de espera</h2>
            @include('administracion.partials.dashboard-queues')
        </section>
    @endif
@endif
