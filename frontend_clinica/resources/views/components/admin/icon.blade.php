@props(['name' => 'arrow'])
<svg {{ $attributes->class('a-icon') }} viewBox="0 0 24 24" aria-hidden="true">
    @switch($name)
        @case('home') <path d="m3 10 9-7 9 7v10H3zM9 20v-7h6v7"/> @break
        @case('people') <path d="M16 21v-3a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v3m20 0v-3a4 4 0 0 0-3-3.87M12 3a4 4 0 1 1 0 8m7-7a4 4 0 0 1 0 7"/><circle cx="8" cy="7" r="4"/> @break
        @case('specialty') <path d="M9 3h6v6h6v6h-6v6H9v-6H3V9h6z"/> @break
        @case('calendar') <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18m-12 4h2m2 0h2"/> @break
        @case('block') <circle cx="12" cy="12" r="9"/><path d="m6 6 12 12"/> @break
        @case('shield') <path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6zM12 8v5m0 3h.01"/> @break
        @case('logout') <path d="M9 21H3V3h6m7 4 5 5-5 5m-9-5h14"/> @break
        @case('menu') <path d="M4 6h16M4 12h16M4 18h16"/> @break
        @case('plus') <path d="M12 5v14M5 12h14"/> @break
        @default <path d="M5 12h14m-6-6 6 6-6 6"/>
    @endswitch
</svg>
