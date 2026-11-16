@extends('layouts.medico')
@section('medico_title', 'Historial de atenciones')
@section('breadcrumb', 'Historial')
@section('medico_content')
    <x-admin.heading title="Historial de atenciones" description="Citas atendidas por tu perfil, con orden cronológico descendente." />
    <form method="GET" action="{{ route('medico.historial.index') }}" class="a-filter">
        <x-admin.field name="desde" label="Desde" type="date" :value="$filters['desde'] ?? ''" />
        <x-admin.field name="hasta" label="Hasta" type="date" :value="$filters['hasta'] ?? ''" />
        <x-admin.button>Aplicar rango</x-admin.button>
        <x-admin.button variant="text" :href="route('medico.historial.index')">Limpiar</x-admin.button>
    </form>
    @include('medico.partials.appointments-table', ['appointments' => $page['citas'], 'history' => true])
    <x-admin.pagination :page="$page" :count="count($page['citas'])" />
@endsection
