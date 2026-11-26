@extends('layouts.app')
@section('title')
    @yield('medico_title', 'Médico') | Proyecto Clínica
@endsection
@section('body_class', 'admin-page medico-page')
@section('content')
    <x-workspace :title="trim($__env->yieldContent('medico_title', 'Médico'))" home="medico.home" area="Médico" role="Médico" navigation="medico.partials.navigation" :validation-errors="$errors">
        <x-slot:mobileHeader>
            <div class="m-phone-header">
                <x-workspace-brand home="medico.home" area="Portal médico" />
                <form method="POST" action="{{ route('logout') }}">@csrf<x-admin.button variant="text"><x-admin.icon name="logout" />Salir</x-admin.button></form>
            </div>
        </x-slot:mobileHeader>
        <x-slot:breadcrumb>@yield('breadcrumb', 'Inicio')</x-slot:breadcrumb>
        @yield('medico_content')
    </x-workspace>
    @include('medico.partials.navigation', ['mobile' => true])
@endsection
