@extends('layouts.secretaria')
@section('reception_title', 'Nueva reserva presencial')
@section('breadcrumb', 'Nueva reserva')
@section('reception_content')
    <x-admin.heading title="Nueva reserva presencial" description="Selecciona al paciente y encuentra un turno disponible." />
    @if(!$patient)
        <form method="GET" class="a-filter" action="{{ route('secretaria.reservas.create') }}">
            <x-admin.field name="q" label="Buscar paciente por CI, nombre o correo" :value="$filters['q'] ?? ''" minlength="3" maxlength="100" help="Al menos tres letras o números. Hasta 20 coincidencias." />
            <x-admin.button>Buscar paciente</x-admin.button>
        </form>
        <x-patient-results :patients="$patients" destination="reservation" />
    @else
        <section class="r-patient-strip" aria-labelledby="reservation-patient"><div><h2 id="reservation-patient">{{ $patient['nombreCompleto'] }}</h2><p class="a-help">{{ $patient['codPac'] }} · CI {{ $patient['ci'] ?? 'No informado' }}</p></div><x-admin.button variant="text" :href="route('secretaria.reservas.create')">Cambiar paciente</x-admin.button></section>
        @if(!$patient['habilitadoParaReservar'])
            <x-admin.notice type="warning">El paciente no está habilitado para nuevas reservas: {{ $patient['motivoInhabilitacion'] ?? 'consulta con Administración.' }}</x-admin.notice>
            <x-admin.button variant="secondary" :href="route('secretaria.pacientes.show', $patient['codPac'])">Ver perfil administrativo</x-admin.button>
        @elseif(!count($specialties))
            <x-admin.empty title="No hay especialidades disponibles" description="Consulta con Administración antes de registrar una reserva." />
        @else
            <details class="r-filters" @if(!count($slots)) open @endif>
            <summary>Especialidad, médico y fecha</summary>
            <form method="GET" action="{{ route('secretaria.reservas.create') }}" class="a-form r-availability-form">
                <input type="hidden" name="codPac" value="{{ $patient['codPac'] }}">
                <fieldset><legend>Buscar disponibilidad</legend><div class="a-form-grid">
                    <x-admin.field name="codEsp" label="Especialidad" type="select" :value="$filters['codEsp'] ?? ''" :options="['' => 'Selecciona una especialidad'] + collect($specialties)->mapWithKeys(fn($item) => [$item['codEsp'] => $item['nombreEspecialidad'].' · '.$item['duracionConsultaMin'].' min'])->all()" required data-reservation-specialty />
                    <x-admin.field name="fecha" label="Fecha de atención" type="date" :value="$filters['fecha'] ?? $today" :min="$today" :max="$lastDay" required :help="'Desde '.$today.' hasta '.$lastDay.'. El servicio confirma la ventana clínica.'" />
                    @if($specialty && count($doctors))<x-admin.field name="codMed" label="Médico" type="select" :value="$filters['codMed'] ?? ''" :options="['' => 'Cualquier médico disponible'] + collect($doctors)->pluck('nombreCompleto', 'codMed')->all()" data-reservation-doctor />@endif
                </div></fieldset>
                <div class="a-actions"><x-admin.button>Consultar turnos</x-admin.button><x-admin.button variant="text" :href="route('secretaria.pacientes.show', $patient['codPac'])">Ver paciente</x-admin.button></div>
            </form>
            </details>
            @if($specialty)
                <section class="a-stack" aria-labelledby="slots-title"><div><h2 id="slots-title">Turnos disponibles</h2><p class="a-help">{{ $specialty['nombreEspecialidad'] }} · {{ $filters['fecha'] ?? 'Selecciona una fecha' }}. Se excluyen los turnos superpuestos con las citas del paciente.</p></div>
                    @if(!count($doctors))<x-admin.empty title="No hay médicos habilitados" description="Elige otra especialidad o consulta con Administración." />
                    @elseif(!count($slots))<x-admin.empty title="No hay turnos disponibles" description="Prueba otra fecha o consulta con otro médico." />
                    @else
                        @foreach(collect($slots)->groupBy('codMed') as $doctorSlots)
                            <section class="r-slot-group"><h3>{{ $doctorSlots->first()['nombreMedico'] }}</h3><div class="r-slots">
                                @foreach($doctorSlots as $slot)<a class="a-button a-button--secondary r-slot" href="{{ route('secretaria.reservas.create', array_intersect_key($filters, array_flip(['codPac', 'codEsp', 'codMed', 'fecha'])) + ['codSlot' => $slot['codSlot']]) }}" aria-label="Seleccionar {{ substr($slot['horaInicio'], 0, 5) }} con {{ $slot['nombreMedico'] }}"><span class="a-number">{{ substr($slot['horaInicio'], 0, 5) }}–{{ substr($slot['horaFin'], 0, 5) }}</span><small>Seleccionar turno</small></a>@endforeach
                            </div></section>
                        @endforeach
                    @endif
                </section>
            @endif
        @endif
    @endif
@endsection
