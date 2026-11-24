@extends('layouts.administracion')
@section('admin_title', 'Panel de administración')
@section('admin_content')
    @php
        $period = $dashboard['period'] ?? null;
        $preset = $period['preset'] ?? ($filters['periodo'] ?? 'mes');
        $oldPreset = old('periodo');
        if (is_string($oldPreset) && in_array($oldPreset, ['hoy', 'semana', 'mes', 'personalizado'], true)) { $preset = $oldPreset; }
        $fromInput = old('desde', $period['from'] ?? ($filters['desde'] ?? ''));
        $toInput = old('hasta', $period['to'] ?? ($filters['hasta'] ?? ''));
        $totals = $dashboard['totals'] ?? null;
        $number = fn ($value) => $value === null ? '—' : number_format($value, 0, ',', '.');
    @endphp
    <div class="ad-dashboard" data-dashboard>
        <x-admin.heading title="Panel de administración" description="Actividad de la clínica, atención y demanda de especialidades.">
            <x-admin.button :href="route('administracion.agendas.index')">Gestionar agendas<x-admin.icon /></x-admin.button>
        </x-admin.heading>
        <form method="GET" action="{{ route('administracion.home') }}" class="ad-toolbar" data-dashboard-filter>
            <div class="ad-period-control">
                <label for="periodo">Período</label>
                <select id="periodo" name="periodo" data-dashboard-period>
                    @foreach(['hoy' => 'Hoy', 'semana' => 'Esta semana', 'mes' => 'Este mes', 'personalizado' => 'Personalizado'] as $value => $label)
                        <option value="{{ $value }}" @selected($preset === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ad-custom-dates" data-dashboard-dates>
                <div class="ad-period-control"><label for="desde">Desde</label><input type="date" id="desde" name="desde" value="{{ is_string($fromInput) ? $fromInput : '' }}" @error('desde') aria-invalid="true" aria-describedby="desde-error" @enderror>@error('desde')<span class="ad-field-error" id="desde-error">{{ $message }}</span>@enderror</div>
                <div class="ad-period-control"><label for="hasta">Hasta</label><input type="date" id="hasta" name="hasta" value="{{ is_string($toInput) ? $toInput : '' }}" @error('hasta') aria-invalid="true" aria-describedby="hasta-error" @enderror>@error('hasta')<span class="ad-field-error" id="hasta-error">{{ $message }}</span>@enderror</div>
            </div>
            <x-admin.button variant="secondary" data-dashboard-submit>Actualizar</x-admin.button>
            @if($dashboard)<p class="ad-freshness"><span>{{ $period['label'] }}</span><small>Actualizado {{ $dashboard['updatedAt'] }} · Bolivia</small></p>@endif
        </form>
        @if(! $dashboard)
            <x-admin.notice type="warning" role="status"><strong>Las estadísticas no están disponibles.</strong><p>No pudimos consultar la actividad de la clínica. Actualiza el panel para volver a intentarlo.</p></x-admin.notice>
        @endif
        <section aria-label="Personal y pacientes actuales" class="ad-overview">
            @foreach([
                ['doctors', 'Médicos activos', 'administracion.medicos.index', ['estado' => 'Activo']],
                ['secretaries', 'Secretarios activos', 'administracion.secretarias.index', ['estado' => 'Activo']],
                ['patients', 'Pacientes registrados', 'administracion.pacientes.index', []],
                ['specialties', 'Especialidades activas', 'administracion.especialidades.index', ['estado' => 'Activo']],
            ] as [$key, $label, $route, $parameters])
                <a href="{{ route($route, $parameters) }}"><dl><dt>{{ $label }}</dt><dd class="a-number">{{ $number($totals[$key] ?? null) }}</dd></dl><small>{{ $totals === null ? 'No disponible' : 'Total actual' }}</small></a>
            @endforeach
        </section>
        @if($dashboard)
            <section class="ad-today" aria-label="Actividad del día clínico">
                <div><h2>Hoy en la clínica</h2><span>{{ $period['today'] }}</span></div>
                <dl><div><dt>Citas</dt><dd>{{ $number($totals['todayAppointments']) }}</dd></div><div><dt>Atendidas</dt><dd>{{ $number($totals['todayAttended']) }}</dd></div><div><dt>En espera</dt><dd>{{ $number($totals['todayWaiting']) }}</dd></div></dl>
                <a href="{{ route('administracion.bloqueos.index') }}" class="ad-text-link">Revisar bloqueos<x-admin.icon /></a>
            </section>
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
        @else
            <div class="ad-unavailable-grid" aria-label="Estadísticas pendientes de consulta">
                @foreach(['Pacientes atendidos por día', 'Especialidades más solicitadas', 'Estado de las citas', 'Canales de reserva', 'Derivaciones'] as $title)
                    <section class="a-panel ad-chart-panel"><h2>{{ $title }}</h2><p class="ad-empty">Datos no disponibles. Vuelve a actualizar el panel.</p></section>
                @endforeach
            </div>
        @endif
        <nav class="ad-shortcuts" aria-label="Gestión de la clínica">
            <a href="{{ route('administracion.medicos.create') }}">Registrar médico<x-admin.icon name="plus" /></a>
            <a href="{{ route('administracion.secretarias.create') }}">Registrar secretario<x-admin.icon name="plus" /></a>
            <a href="{{ route('administracion.especialidades.create') }}">Nueva especialidad<x-admin.icon name="plus" /></a>
            <a href="{{ route('administracion.sanciones.index') }}">Sanciones temporales<x-admin.icon /></a>
        </nav>
    </div>
@endsection
