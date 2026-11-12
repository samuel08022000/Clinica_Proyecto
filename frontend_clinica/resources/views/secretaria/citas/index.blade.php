@extends('layouts.secretaria')
@section('reception_title', 'Gestión de citas')
@section('breadcrumb', 'Gestión de citas')
@section('reception_content')
    <x-admin.heading title="Gestión de citas" description="Consulta las reservas y accede a sus operaciones autorizadas."><x-admin.button :href="route('secretaria.reservas.create')">Nueva reserva</x-admin.button></x-admin.heading>
    @include('secretaria.partials.appointment-filters')
    @if(!isset($filters['fecha']) && !isset($filters['desde']) && !isset($filters['hasta']))<p class="a-help">Agenda del día clínico actual.</p>@endif
    <x-appointments-table :appointments="$page['citas']" />
    <x-admin.pagination :page="$page" :count="count($page['citas'])" />
@endsection
