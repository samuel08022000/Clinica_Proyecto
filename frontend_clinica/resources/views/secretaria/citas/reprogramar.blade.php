@extends('layouts.secretaria')
@section('reception_title', 'Reprogramar cita')
@section('breadcrumb', 'Reprogramar cita')
@section('reception_content')
    <x-admin.heading title="Reprogramar cita" :description="$appointment['codCit'].' · '.$appointment['nombrePaciente']" />
    <section class="a-panel"><x-appointment-summary :appointment="$appointment" /><x-clinical-progress :state="$appointment['estado']" /></section>
    <form method="GET" class="a-form">
        <div class="a-form-grid">
            <x-admin.field name="fecha" label="Nueva fecha" type="date" :value="$filters['fecha'] ?? ''" :min="$today" :max="$lastDay" required />
            <x-admin.field name="codMed" label="Médico" type="select" :value="$filters['codMed'] ?? ''" :options="['' => 'Cualquier médico disponible'] + collect($doctors)->pluck('nombreCompleto', 'codMed')->all()" />
        </div>
        <x-admin.button>Buscar turnos</x-admin.button>
    </form>
    @if(isset($filters['fecha']))
        @if(!count($slots))<x-admin.empty title="Sin turnos disponibles" description="Selecciona otra fecha o médico. Los horarios bloqueados no están disponibles." />
        @else
            <x-admin.form :action="route('secretaria.citas.reprogramar.store', $appointment['codCit'])">
                <x-admin.field name="codSlot" label="Nuevo turno" type="select" :options="collect($slots)->mapWithKeys(fn($s) => [$s['codSlot'] => $s['nombreMedico'].' · '.$s['fecha'].' · '.substr($s['horaInicio'],0,5).'–'.substr($s['horaFin'],0,5)])->all()" required />
                <x-admin.field name="motivo" label="Motivo de reprogramación" type="textarea" maxlength="2000" required />
                <label><input type="checkbox" name="confirmacion" value="1" required> Confirmo el nuevo turno para este paciente.</label>
                <p class="a-help">Se conservará la cita original y se emitirá un nuevo pase QR. El servidor vuelve a validar la disponibilidad al confirmar.</p>
                <x-admin.button>Confirmar reprogramación</x-admin.button>
            </x-admin.form>
        @endif
    @endif
@endsection
