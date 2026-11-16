@props(['page', 'key' => 'page', 'count' => null, 'anchor' => ''])
@php($number = (int) $page['page'])
<nav class="a-pagination" aria-label="Paginación {{ $key }}">
    <p class="a-small a-muted">Página {{ $number + 1 }}@if($count !== null) · {{ $count }} registros en esta página @endif</p>
    <div class="a-actions">
        @if($number > 0)<x-admin.button variant="secondary" :href="request()->fullUrlWithQuery([$key => $number - 1]).$anchor">Anterior</x-admin.button>@endif
        @if($page['hayMas'])<x-admin.button variant="secondary" :href="request()->fullUrlWithQuery([$key => $number + 1]).$anchor">Siguiente</x-admin.button>@endif
    </div>
</nav>
