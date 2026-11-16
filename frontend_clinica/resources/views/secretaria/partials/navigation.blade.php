<nav class="a-nav" aria-label="Módulos de Secretaría">
    @foreach([
        ['secretaria.home', 'Dashboard', 'home', request()->routeIs('secretaria.home')],
        ['secretaria.pacientes.index', 'Pacientes', 'people', request()->routeIs('secretaria.pacientes.*')],
        ['secretaria.reservas.create', 'Nueva reserva', 'plus', request()->routeIs('secretaria.reservas.*')],
        ['secretaria.citas.index', 'Gestión de citas', 'calendar', request()->routeIs('secretaria.citas.*') && !request()->routeIs('secretaria.citas.asistencia', 'secretaria.citas.acreditacion')],
        ['secretaria.checkin.index', 'Check-in QR', 'qr', request()->routeIs('secretaria.checkin.*', 'secretaria.citas.asistencia', 'secretaria.citas.acreditacion')],
        ['secretaria.historial.index', 'Historial', 'history', request()->routeIs('secretaria.historial.*')],
    ] as [$destination, $label, $icon, $active])
        <a href="{{ route($destination) }}" @if($active) aria-current="page" @endif><x-admin.icon :name="$icon" />{{ $label }}</a>
    @endforeach
</nav>
