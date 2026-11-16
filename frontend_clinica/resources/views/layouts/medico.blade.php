@extends('layouts.app')
@section('title')
    @yield('medico_title', 'Médico') | Proyecto Clínica
@endsection
@section('body_class', 'admin-page medico-page')
@section('content')
    <x-workspace :title="trim($__env->yieldContent('medico_title', 'Médico'))" home="medico.home" area="Médico" role="Médico" navigation="medico.partials.navigation" :validation-errors="$errors">
        <x-slot:breadcrumb>@yield('breadcrumb', 'Inicio')</x-slot:breadcrumb>
        @yield('medico_content')
    </x-workspace>
@endsection
