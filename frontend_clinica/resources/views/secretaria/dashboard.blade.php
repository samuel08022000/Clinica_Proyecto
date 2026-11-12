@extends('layouts.secretaria')
@section('reception_title', 'Panel de Secretaría')
@section('reception_content')
    <x-admin.heading title="Panel de Secretaría" description="Recepción, reservas y llegadas en un mismo lugar."><x-admin.button :href="route('secretaria.reservas.create')"><x-admin.icon name="plus" />Nueva reserva</x-admin.button></x-admin.heading>
    <div class="r-quick-actions a-actions" aria-label="Operaciones frecuentes">
        <x-admin.button variant="secondary" :href="route('secretaria.pacientes.index')">Buscar paciente</x-admin.button>
        <x-admin.button variant="secondary" :href="route('secretaria.checkin.index')">Registrar check-in</x-admin.button>
    </div>
    <section class="a-stack" aria-labelledby="today-title">
        <div><h2 id="today-title">Citas del día clínico</h2><p class="a-help">Consulta y gestiona las atenciones de hoy.</p></div>
        <x-appointments-table :appointments="$page['citas']" overview />
        @if(count($page['citas']))<p class="a-small a-muted">{{ count($page['citas']) }} citas mostradas en esta página. @if($page['hayMas'])Hay más citas disponibles. @endif</p>@endif
        <div class="a-actions"><x-admin.button variant="secondary" :href="route('secretaria.citas.index')">Consultar citas</x-admin.button><x-admin.button variant="text" :href="route('secretaria.historial.index')">Consultar historial</x-admin.button></div>
    </section>
@endsection
