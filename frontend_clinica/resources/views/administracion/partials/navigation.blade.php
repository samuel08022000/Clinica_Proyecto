<nav class="a-nav" aria-label="Administración">
    <a href="{{ route('administracion.home') }}" @if(request()->routeIs('administracion.home')) aria-current="page" @endif><x-admin.icon name="home" />Dashboard</a>
    <details @if(request()->routeIs('administracion.medicos.*', 'administracion.secretarias.*')) open @endif>
        <summary><x-admin.icon name="people" />Gestión de personal</summary>
        <a href="{{ route('administracion.medicos.index') }}" @if(request()->routeIs('administracion.medicos.*')) aria-current="page" @endif>Médicos</a>
        <a href="{{ route('administracion.secretarias.index') }}" @if(request()->routeIs('administracion.secretarias.*')) aria-current="page" @endif>Secretarios</a>
    </details>
    @foreach([
        ['especialidades', 'Especialidades', 'specialty'], ['agendas', 'Agendas y horarios', 'calendar'],
        ['bloqueos', 'Bloqueos médicos', 'block'], ['pacientes', 'Pacientes', 'people'], ['sanciones', 'Sanciones', 'shield']
    ] as [$module, $label, $icon])
        <a href="{{ route('administracion.'.$module.'.index') }}" @if(request()->routeIs('administracion.'.$module.'.*')) aria-current="page" @endif><x-admin.icon :name="$icon" />{{ $label }}</a>
    @endforeach
</nav>
