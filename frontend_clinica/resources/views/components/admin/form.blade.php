@props(['action', 'method' => 'POST', 'confirm' => null])
<form action="{{ $action }}" method="POST" data-admin-form @if($confirm) data-confirm="{{ $confirm }}" @endif {{ $attributes->class('a-form') }}>
    @csrf
    @if(strtoupper($method) !== 'POST') @method($method) @endif
    {{ $slot }}
    <p class="a-sending a-small" role="status" data-admin-sending hidden>Enviando solicitud. Espera la confirmación.</p>
</form>
