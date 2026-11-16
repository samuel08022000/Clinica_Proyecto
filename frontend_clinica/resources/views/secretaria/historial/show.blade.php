@extends('layouts.secretaria')
@section('reception_title', 'Historial administrativo')
@section('breadcrumb')
    <a href="{{ route('secretaria.pacientes.show', $patient['codPac']) }}">{{ $patient['nombreCompleto'] }}</a><span aria-hidden="true">/</span>Historial
@endsection
@section('reception_content')
    <x-admin.heading title="Historial administrativo" :description="$patient['nombreCompleto'].' · '.$patient['codPac']"><x-admin.button variant="secondary" :href="route('secretaria.pacientes.show', $patient['codPac'])">Ver paciente</x-admin.button></x-admin.heading>
    @include('secretaria.partials.appointment-filters', ['history' => true])
    <x-appointments-table :appointments="$page['citas']" history />
    <x-admin.pagination :page="$page" :count="count($page['citas'])" />
@endsection
