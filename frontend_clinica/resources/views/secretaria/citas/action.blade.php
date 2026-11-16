@extends('layouts.secretaria')
@php($isCancellation = $action === 'cancelar')
@php($allowed = $isCancellation ? $canCancel : $canMarkAbsence)
@section('reception_title', $isCancellation ? 'Cancelar cita' : 'Registrar inasistencia')
@section('breadcrumb')
    <a href="{{ route('secretaria.citas.show', $appointment['codCit']) }}">{{ $appointment['codCit'] }}</a><span aria-hidden="true">/</span>{{ $isCancellation ? 'Cancelación' : 'Inasistencia' }}
@endsection
@section('reception_content')
    <x-admin.heading :title="$isCancellation ? 'Cancelar cita' : 'Registrar inasistencia'" :description="$isCancellation ? 'La cancelación libera el turno mediante el servicio de citas.' : 'Registra la ausencia sin generar una incidencia adicional.'" />
    <section class="a-panel"><h2>Cita seleccionada</h2><x-appointment-summary :appointment="$appointment" /></section>
    <p>{{ $attendance['checkinRegistrado'] ? 'La llegada ya está registrada.' : 'No hay check-in registrado.' }}</p>
    @if($allowed)
        <x-admin.form :action="route('secretaria.citas.'.$action.'.store', $appointment['codCit'])" :confirm="$isCancellation ? '¿Confirmas cancelar esta cita? El turno quedará libre.' : '¿Confirmas registrar la inasistencia de este paciente?'">
            @if($isCancellation)<x-admin.field name="motivo" label="Motivo de cancelación" type="textarea" maxlength="2000" required help="Hasta 2000 caracteres. Describe únicamente el motivo administrativo." />
            @else<x-admin.notice type="warning">El registro corresponde a una cita confirmada cuyo turno finalizó, sin llegada registrada. La hora clínica y la transición se validan nuevamente en el servicio.</x-admin.notice>@endif
            <x-admin.confirmation :message="$isCancellation ? 'Confirmo la cancelación de la cita seleccionada.' : 'Confirmo el registro de la inasistencia.'" />
            <div class="a-actions"><x-admin.button variant="danger">{{ $isCancellation ? 'Confirmar cancelación' : 'Registrar inasistencia' }}</x-admin.button><x-admin.button variant="secondary" :href="route('secretaria.citas.show', $appointment['codCit'])">Volver al detalle</x-admin.button></div>
        </x-admin.form>
    @else
        <x-admin.notice type="warning">{{ $isCancellation ? 'La cita no admite cancelación: debe estar confirmada o afectada y no tener llegada previa.' : 'No se puede registrar la inasistencia. La cita debe estar confirmada, haber finalizado y no tener check-in.' }}</x-admin.notice>
        <x-admin.button variant="secondary" :href="route('secretaria.citas.show', $appointment['codCit'])">Volver al detalle</x-admin.button>
    @endif
@endsection
