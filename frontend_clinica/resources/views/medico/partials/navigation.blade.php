<nav class="a-nav" aria-label="Módulos del Médico">
    @foreach([
        ['medico.home', 'Resumen del día', 'home', request()->routeIs('medico.home')],
        ['medico.agenda.index', 'Agenda', 'calendar', request()->routeIs('medico.agenda.*', 'medico.citas.*')],
        ['medico.historial.index', 'Historial', 'history', request()->routeIs('medico.historial.*')],
        ['medico.derivaciones.index', 'Derivaciones', 'people', request()->routeIs('medico.derivaciones.*')],
    ] as [$destination, $label, $icon, $active])
        <a href="{{ route($destination) }}" @if($active) aria-current="page" @endif><x-admin.icon :name="$icon" />{{ $label }}</a>
    @endforeach
</nav>
