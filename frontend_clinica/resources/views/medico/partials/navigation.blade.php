<nav class="{{ !empty($mobile) ? 'm-bottom-nav' : 'a-nav' }}" aria-label="Módulos del Médico">
    @foreach([
        ['medico.home', !empty($mobile) ? 'Hoy' : 'Resumen del día', 'home', request()->routeIs('medico.home')],
        ['medico.agenda.index', 'Agenda', 'calendar', request()->routeIs('medico.agenda.*', 'medico.citas.*', 'medico.analisis.*')],
        ['medico.historial.index', 'Historial', 'history', request()->routeIs('medico.historial.*')],
        ['medico.derivaciones.index', 'Derivaciones', 'people', request()->routeIs('medico.derivaciones.*')],
    ] as [$destination, $label, $icon, $active])
        <a href="{{ route($destination) }}" @if($active) aria-current="{{ request()->routeIs($destination) ? 'page' : 'location' }}" @endif><x-admin.icon :name="$icon" />{{ $label }}</a>
    @endforeach
</nav>
