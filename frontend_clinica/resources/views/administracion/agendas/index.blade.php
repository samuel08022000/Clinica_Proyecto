@extends('layouts.administracion')
@section('admin_title', 'Agendas y horarios')
@section('breadcrumb', 'Agendas y horarios')
@section('admin_content')
    <x-admin.heading title="Agendas y horarios" description="Organiza la semana de atención de cada médico.">
        @if($doctor)<x-admin.button :href="route('administracion.agendas.create', $doctor['codMed'])"><x-admin.icon name="plus" />Agregar horario</x-admin.button>@endif
    </x-admin.heading>
    <form method="GET" class="a-filter">@include('administracion.partials.doctor-selector')<x-admin.button variant="secondary">Consultar agenda</x-admin.button></form>
    @if($doctor)
        <section class="a-stack" aria-labelledby="week-title">
            <div class="a-actions"><h2 id="week-title">Semana de {{ $doctor['nombreCompleto'] }}</h2><x-admin.badge :value="$doctor['estado']" /></div>
            <div class="a-week">
                @foreach([1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'] as $day => $label)
                    <div class="a-day"><h3>{{ $label }}</h3><div class="a-stack">
                        @forelse(collect($schedules)->where('diaSemana', $day)->sortBy('horaInicio') as $schedule)
                            <div>
                                <div class="a-shift"><span>{{ substr($schedule['horaInicio'], 0, 5) }} – {{ substr($schedule['horaFin'], 0, 5) }}</span><span class="a-muted">{{ $schedule['pausaInicio'] ? 'Descanso '.substr($schedule['pausaInicio'], 0, 5).' – '.substr($schedule['pausaFin'], 0, 5) : 'Sin descanso' }}</span><x-admin.badge :value="$schedule['estado']" /><a href="{{ route('administracion.agendas.edit', [$doctor['codMed'], $schedule['codHor']]) }}" aria-label="Editar horario del {{ strtolower($label) }} a las {{ substr($schedule['horaInicio'], 0, 5) }}">Editar</a></div>
                                <x-admin.state :action="route('administracion.agendas.estado', [$doctor['codMed'], $schedule['codHor']])" :state="$schedule['estado']" :name="'horario '.$schedule['codHor']" impact="El cambio modifica la disponibilidad futura. Si afecta reservas vigentes, el servicio informará el conflicto y conservará el horario." />
                            </div>
                        @empty<p class="a-muted a-small">Sin horario registrado.</p>@endforelse
                    </div></div>
                @endforeach
            </div>
        </section>
        <section class="a-panel a-stack" aria-labelledby="duration-title">
            <h2 id="duration-title">Duración general de consulta</h2>
            <p class="a-muted">Actual: {{ $doctor['duracionConsultaMin'] ? $doctor['duracionConsultaMin'].' minutos' : 'sin duración general' }}. El cambio también alinea la duración de sus vínculos activos por especialidad. Las citas vigentes pueden impedirlo.</p>
            <x-admin.form :action="route('administracion.agendas.duracion', $doctor['codMed'])" method="PUT" confirm="Cambiará la duración general de consulta. Las citas anteriores no se modificarán silenciosamente. ¿Deseas continuar?">
                <x-admin.field name="duracionConsultaMin" label="Nueva duración" type="select" :value="$doctor['duracionConsultaMin'] ?? 30" required :options="[20 => '20 minutos', 30 => '30 minutos', 45 => '45 minutos', 60 => '60 minutos']" />
                <x-admin.confirmation message="Confirmo el cambio de duración general de consulta." />
                <div><x-admin.button variant="secondary">Guardar duración</x-admin.button></div>
            </x-admin.form>
        </section>
    @else<x-admin.empty title="Selecciona un médico" description="Consulta sus siete días de atención, registra horarios y configura la duración general de consulta." />@endif
@endsection
