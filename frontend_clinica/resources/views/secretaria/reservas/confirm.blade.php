@extends('layouts.secretaria')
@section('reception_title', 'Confirmar reserva')
@section('breadcrumb')
    <a href="{{ route('secretaria.reservas.create', array_diff_key($filters, ['codSlot' => true])) }}">Nueva reserva</a><span aria-hidden="true">/</span>Confirmación
@endsection
@section('reception_content')
    <x-admin.heading title="Confirmar reserva" description="Revisa al paciente y el turno antes de registrar la cita." />
    <section class="a-panel"><h2>Resumen de la reserva</h2><x-appointment-summary :appointment="$selected + ['nombrePaciente' => $patient['nombreCompleto']]" /></section>
    <x-admin.notice>El turno se confirma al guardar. Si otra persona lo ocupa antes, podrás elegir uno nuevo.</x-admin.notice>
    <x-admin.form :action="route('secretaria.reservas.store')" confirm="¿Confirmas registrar esta cita presencial para el paciente seleccionado?">
        <input type="hidden" name="codPac" value="{{ $patient['codPac'] }}"><input type="hidden" name="codSlot" value="{{ $selected['codSlot'] }}">
        @foreach(['codEsp', 'codMed', 'fecha'] as $key)@if(isset($filters[$key]))<input type="hidden" name="{{ $key }}" value="{{ $filters[$key] }}">@endif @endforeach
        <x-admin.confirmation message="He comprobado el paciente, la fecha y el horario." />
        <div class="a-actions"><x-admin.button>Confirmar reserva</x-admin.button><x-admin.button variant="secondary" :href="route('secretaria.reservas.create', array_diff_key($filters, ['codSlot' => true]))">Elegir otro turno</x-admin.button></div>
    </x-admin.form>
@endsection
