@extends('layouts.medico')
@section('medico_title', 'Agenda médica')
@section('breadcrumb', 'Agenda')
@section('medico_content')
    @php($dia = \Carbon\CarbonImmutable::parse($fecha, 'America/La_Paz')->locale('es'))
    <x-admin.heading title="Agenda médica" :description="ucfirst($dia->isoFormat('dddd D [de] MMMM [de] YYYY'))" />
    <nav class="m-day-nav" aria-label="Cambiar día de la agenda">
        <a href="{{ route('medico.agenda.index', ['fecha' => $dia->subDay()->toDateString()]) }}">← Anterior</a>
        <a href="{{ route('medico.agenda.index') }}">Hoy</a>
        <a href="{{ route('medico.agenda.index', ['fecha' => $dia->addDay()->toDateString()]) }}">Siguiente →</a>
    </nav>
    <details class="m-date-picker" @if($errors->has('fecha')) open @endif>
        <summary>Elegir otra fecha</summary>
    <form method="GET" action="{{ route('medico.agenda.index') }}" class="a-filter m-date-filter">
        <x-admin.field name="fecha" label="Fecha" type="date" :value="$fecha" required />
        <x-admin.button>Consultar fecha</x-admin.button>
    </form>
    </details>
    @include('medico.partials.appointments-table', ['appointments' => $citas])
@endsection
