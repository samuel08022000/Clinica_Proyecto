@extends('layouts.app')
@section('title')
    @yield('reception_title', 'Secretaría') | Proyecto Clínica
@endsection
@section('body_class', 'admin-page reception-page')
@section('content')
    <x-workspace :title="trim($__env->yieldContent('reception_title', 'Secretaría'))" home="secretaria.home" area="Secretaría" role="Secretario" navigation="secretaria.partials.navigation" :validation-errors="$errors">
        <x-slot:breadcrumb>@yield('breadcrumb', 'Inicio')</x-slot:breadcrumb>
        @yield('reception_content')
    </x-workspace>
@endsection
