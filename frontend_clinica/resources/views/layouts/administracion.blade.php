@extends('layouts.app')
@section('title')
    @yield('admin_title', 'Administración') | Proyecto Clínica
@endsection
@section('body_class', 'admin-page')
@section('content')
    <x-workspace :title="trim($__env->yieldContent('admin_title', 'Administración'))" home="administracion.home" area="Administración" role="Administrador" navigation="administracion.partials.navigation">
        <x-slot:breadcrumb>@yield('breadcrumb', 'Inicio')</x-slot:breadcrumb>
        @yield('admin_content')
    </x-workspace>
@endsection
