@extends('layouts.medico')
@section('medico_title', 'Agenda médica')
@section('breadcrumb', 'Agenda')
@section('medico_content')
    <x-admin.heading title="Agenda médica" description="Consulta citas propias por fecha clínica." />
    <form method="GET" action="{{ route('medico.agenda.index') }}" class="a-filter m-date-filter">
        <x-admin.field name="fecha" label="Fecha" type="date" :value="$fecha" required />
        <x-admin.button>Consultar fecha</x-admin.button>
        <x-admin.button variant="text" :href="route('medico.agenda.index')">Volver a hoy</x-admin.button>
    </form>
    <p class="a-help">Agenda del {{ $fecha }}. Solo se muestran citas asignadas a tu perfil médico.</p>
    @include('medico.partials.appointments-table', ['appointments' => $citas])
@endsection
