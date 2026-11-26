@extends('layouts.administracion')
@section('admin_title', 'Panel de administración')
@section('admin_content')
    @php
        $period = $dashboard['period'] ?? null;
        $totals = $dashboard['totals'] ?? null;
        $queues = $dashboard['queues'] ?? null;
        $number = fn ($value) => $value === null ? '—' : number_format($value, 0, ',', '.');
        $sections = ['hoy' => 'Hoy', 'analisis' => 'Análisis', 'gestion' => 'Directorio'];
    @endphp
    <div class="ad-dashboard ad-dashboard--{{ $section }}" data-dashboard>
        <x-admin.heading title="Panel de administración">
            @if($section !== 'analisis')
                <x-admin.button variant="secondary" :href="route('administracion.home', ['vista' => $section] + $filters)">Actualizar</x-admin.button>
            @endif
        </x-admin.heading>

        <nav class="ad-section-nav" aria-label="Vistas del dashboard">
            @foreach($sections as $key => $label)
                <a href="{{ route('administracion.home', ['vista' => $key] + $filters) }}" @if($section === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        @unless($dashboard)
            <x-admin.notice type="warning" role="status"><strong>Las estadísticas no están disponibles.</strong><p>Actualiza el panel para volver a consultarlas.</p></x-admin.notice>
        @endunless

        @switch($section)
            @case('analisis')
                @include('administracion.partials.dashboard-analysis')
                @break
            @case('gestion')
                @include('administracion.partials.dashboard-management')
                @break
            @default
                @if($dashboard)
                    <div class="ad-operations-grid">
                        @if($queues)
                            @include('administracion.partials.dashboard-current-wait')
                        @endif

                        <section class="a-panel ad-chart-panel ad-day-panel" aria-labelledby="dashboard-day-title">
                            <div class="ad-panel-heading">
                                <div><h2 id="dashboard-day-title">Hoy en la clínica</h2><p>{{ $period['today'] }}</p></div>
                            </div>
                            <dl class="ad-queue-metrics ad-day-metrics">
                                <div><dt>Citas programadas</dt><dd>{{ $number($totals['todayAppointments']) }}</dd></div>
                                <div><dt>Pacientes atendidos</dt><dd>{{ $number($totals['todayAttended']) }}</dd></div>
                                @unless($queues)
                                    <div><dt>En espera</dt><dd>{{ $number($totals['todayWaiting']) }}</dd></div>
                                @endunless
                            </dl>
                            <div class="ad-day-actions">
                                <a href="{{ route('administracion.agendas.index') }}" class="ad-text-link">Gestionar agendas<x-admin.icon /></a>
                                <a href="{{ route('administracion.bloqueos.index') }}" class="ad-text-link">Revisar bloqueos<x-admin.icon /></a>
                            </div>
                        </section>
                    </div>

                    @if($queues)
                        @include('administracion.partials.dashboard-waiting-by-doctor')
                    @endif
                @endif
        @endswitch
    </div>
@endsection
