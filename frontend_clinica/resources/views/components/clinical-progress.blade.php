@props(['state', 'referral' => false])
@php($steps = $referral ? ['Pendiente', 'Agendada', 'Completada'] : ['Confirmada', 'En Espera', 'En Atención', 'Atendida'])
<section class="clinical-progress" aria-label="{{ $referral ? 'Progreso de derivación' : 'Progreso de cita' }}">
    <ol>
        @foreach($steps as $step)
            <li @if($state === $step) aria-current="step" @endif>{{ $step }}</li>
        @endforeach
    </ol>
    @if(!in_array($state, $steps, true))<p>Estado alternativo: <strong>{{ $state }}</strong></p>@endif
    @if($state === 'Afectada/Pendiente Reprogramación')<p>La agenda fue bloqueada. Selecciona una nueva fecha disponible o solicita ayuda a Secretaría.</p>@endif
</section>
