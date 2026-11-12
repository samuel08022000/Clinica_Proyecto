@props(['action', 'state', 'name', 'impact'])
@php($next = $state === 'Activo' ? 'Inactivo' : 'Activo')
<details class="a-state a-sensitive">
    <summary>{{ $next === 'Inactivo' ? 'Inactivar' : 'Activar' }} {{ $name }}</summary>
    <x-admin.form :action="$action" method="PUT" :confirm="'¿Confirmas cambiar el estado de '.$name.' a '.$next.'?'">
        <input type="hidden" name="estado" value="{{ $next }}">
        <p class="a-muted">{{ $impact }}</p>
        <x-admin.confirmation :id="'confirmacion-'.substr(hash('sha256', $action), 0, 12)" :message="'Confirmo el cambio de estado de '.$name.'.'" />
        <div><x-admin.button :variant="$next === 'Inactivo' ? 'danger' : 'primary'">Confirmar {{ strtolower($next === 'Inactivo' ? 'inactivación' : 'activación') }}</x-admin.button></div>
    </x-admin.form>
</details>
