@extends('layouts.app')
@section('title')
    @yield('admin_title', 'Administración') | Proyecto Clínica
@endsection
@section('body_class', 'admin-page')
@section('content')
    <aside class="a-sidebar">
        @include('administracion.partials.brand')
        @include('administracion.partials.navigation')
        <div class="a-sidebar-bottom"><form method="POST" action="{{ route('logout') }}">@csrf<x-admin.button variant="text"><x-admin.icon name="logout" />Cerrar sesión</x-admin.button></form></div>
    </aside>
    <div class="a-workspace">
        <header class="a-topbar">
            <x-admin.button type="button" variant="secondary" class="a-mobile-toggle" data-open-admin-menu aria-haspopup="dialog" aria-controls="admin-mobile-menu" aria-expanded="false"><x-admin.icon name="menu" />Menú</x-admin.button>
            <span>@yield('admin_title', 'Administración')</span>
            <div class="a-account">{{ session('nombre_usuario') }}<small>{{ session('email_usuario') }} · Administrador</small></div>
        </header>
        <main id="contenido-principal" class="a-sheet" tabindex="-1">
            <div class="a-canvas">
                <nav aria-label="Ruta de navegación" class="a-breadcrumb"><a href="{{ route('administracion.home') }}">Administración</a><span aria-hidden="true">/</span>@yield('breadcrumb', 'Inicio')</nav>
                <x-admin.alerts />
                @yield('admin_content')
            </div>
        </main>
    </div>
    <dialog id="admin-mobile-menu" class="a-mobile-dialog" aria-label="Navegación administrativa">
        <div class="a-actions"><x-admin.button type="button" variant="secondary" data-close-dialog autofocus>Cerrar menú</x-admin.button></div>
        @include('administracion.partials.brand')
        @include('administracion.partials.navigation')
        <form method="POST" action="{{ route('logout') }}">@csrf<x-admin.button variant="text"><x-admin.icon name="logout" />Cerrar sesión</x-admin.button></form>
    </dialog>
    <dialog data-admin-confirm aria-labelledby="admin-confirm-title" aria-describedby="admin-confirm-description">
        <div class="a-dialog-content">
            <h2 id="admin-confirm-title">Confirmar operación</h2>
            <p id="admin-confirm-description" data-confirm-message></p>
            <div class="a-actions"><x-admin.button type="button" variant="secondary" data-close-dialog autofocus>Volver</x-admin.button><x-admin.button type="button" data-confirm-accept>Confirmar y continuar</x-admin.button></div>
        </div>
    </dialog>
@endsection
