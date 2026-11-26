<section class="ad-section" aria-labelledby="dashboard-directory-title">
    <h2 id="dashboard-directory-title">Personal y catálogos</h2>
    <div class="ad-overview">
    @foreach([
        ['doctors', 'Médicos activos', 'administracion.medicos.index', ['estado' => 'Activo']],
        ['secretaries', 'Secretarios activos', 'administracion.secretarias.index', ['estado' => 'Activo']],
        ['patients', 'Pacientes registrados', 'administracion.pacientes.index', []],
        ['specialties', 'Especialidades activas', 'administracion.especialidades.index', ['estado' => 'Activo']],
    ] as [$key, $label, $route, $parameters])
        <a href="{{ route($route, $parameters) }}"><dl><dt>{{ $label }}</dt><dd class="a-number">{{ $number($totals[$key] ?? null) }}</dd></dl>@if($totals === null)<small>No disponible</small>@endif</a>
    @endforeach
    </div>
</section>
@if($totals !== null)
    <div class="ad-directory-charts">
        <section class="a-panel ad-chart-panel" aria-labelledby="directory-staff-title">
            <div class="ad-panel-heading">
                <div><h2 id="directory-staff-title">Personal activo por rol</h2><p>Médicos y secretarios con acceso activo.</p></div>
            </div>
            <x-admin.dashboard-bars
                :rows="[
                    ['label' => 'Médicos', 'value' => $totals['doctors']],
                    ['label' => 'Secretarios', 'value' => $totals['secretaries']],
                ]"
                label="Cantidad de personal activo por rol"
                empty="No hay médicos ni secretarios activos."
            />
        </section>
        <section class="a-panel ad-chart-panel" aria-labelledby="directory-patients-title">
            <div class="ad-panel-heading">
                <div><h2 id="directory-patients-title">Registros de pacientes</h2><p>{{ $period['label'] }}</p></div>
            </div>
            <x-admin.dashboard-donut
                :rows="[
                    ['label' => 'En el período', 'value' => $totals['newPatients']],
                    ['label' => 'Fuera del período', 'value' => $totals['patients'] - $totals['newPatients']],
                ]"
                label="pacientes"
                title="Pacientes registrados dentro y fuera del período"
            />
        </section>
    </div>
@endif
<section class="ad-section" aria-labelledby="dashboard-actions-title">
    <h2 id="dashboard-actions-title">Acciones rápidas</h2>
    <nav class="ad-shortcuts" aria-labelledby="dashboard-actions-title">
    <a href="{{ route('administracion.medicos.create') }}">Registrar médico<x-admin.icon name="plus" /></a>
    <a href="{{ route('administracion.secretarias.create') }}">Registrar secretario<x-admin.icon name="plus" /></a>
    <a href="{{ route('administracion.especialidades.create') }}">Nueva especialidad<x-admin.icon name="plus" /></a>
    <a href="{{ route('administracion.sanciones.index') }}">Sanciones temporales<x-admin.icon /></a>
    </nav>
</section>
