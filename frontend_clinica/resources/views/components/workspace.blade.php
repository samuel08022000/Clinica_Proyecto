@props(['title', 'home', 'area', 'role', 'navigation', 'validationErrors' => null])
<aside class="a-sidebar">
    <x-workspace-brand :home="$home" :area="$area" />
    @include($navigation)
    <div class="a-sidebar-bottom"><form method="POST" action="{{ route('logout') }}">@csrf<x-admin.button variant="text"><x-admin.icon name="logout" />Cerrar sesión</x-admin.button></form></div>
</aside>
<div class="a-workspace">
    <header class="a-topbar">
        <x-admin.button type="button" variant="secondary" class="a-mobile-toggle" data-open-admin-menu aria-haspopup="dialog" aria-controls="admin-mobile-menu" aria-expanded="false"><x-admin.icon name="menu" />Menú</x-admin.button>
        <span>{{ $title }}</span>
        <div class="a-account">{{ session('nombre_usuario') }}<small>{{ session('email_usuario') }} · {{ $role }}</small></div>
    </header>
    <main id="contenido-principal" class="a-sheet" tabindex="-1">
        <div class="a-canvas">
            <nav aria-label="Ruta de navegación" class="a-breadcrumb"><a href="{{ route($home) }}">{{ $area }}</a><span aria-hidden="true">/</span>{{ $breadcrumb ?? 'Inicio' }}</nav>
            <x-admin.alerts :validation-errors="$validationErrors" />
            {{ $slot }}
        </div>
    </main>
</div>
<dialog id="admin-mobile-menu" class="a-mobile-dialog" aria-label="Navegación de {{ $area }}">
    <div class="a-actions"><x-admin.button type="button" variant="secondary" data-close-dialog autofocus>Cerrar menú</x-admin.button></div>
    <x-workspace-brand :home="$home" :area="$area" />
    @include($navigation)
    <form method="POST" action="{{ route('logout') }}">@csrf<x-admin.button variant="text"><x-admin.icon name="logout" />Cerrar sesión</x-admin.button></form>
</dialog>
<dialog data-admin-confirm aria-labelledby="admin-confirm-title" aria-describedby="admin-confirm-description">
    <div class="a-dialog-content">
        <h2 id="admin-confirm-title">Confirmar operación</h2>
        <p id="admin-confirm-description" data-confirm-message></p>
        <div class="a-actions"><x-admin.button type="button" variant="secondary" data-close-dialog autofocus>Volver</x-admin.button><x-admin.button type="button" data-confirm-accept>Confirmar y continuar</x-admin.button></div>
    </div>
</dialog>
