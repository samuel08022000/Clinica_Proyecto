<div data-clinical-live-content data-clinical-live-message="Se actualizó la lista de derivaciones pendientes.">
    @if(count($referrals['derivaciones']))
        <ul class="r-referral-list" role="list">
            @foreach($referrals['derivaciones'] as $referral)
                @php($issuedAt = \Carbon\CarbonImmutable::parse($referral['fechaDerivacion'])->setTimezone(config('database.connections.clinical.timezone')))
                <li class="r-referral-row">
                    <div class="r-referral-info">
                        <div class="r-referral-patient">
                            <strong>{{ $referral['nombrePaciente'] }}</strong>
                            <span class="a-badge a-badge--danger">Prioridad alta</span>
                        </div>
                        <p class="a-help">{{ $referral['nombreEspecialidadDestino'] }} · Derivó {{ $referral['nombreMedicoEmisor'] }}</p>
                        <time class="a-small a-muted" datetime="{{ $issuedAt->toIso8601String() }}">{{ $issuedAt->format('d/m/Y H:i') }}</time>
                    </div>
                    <x-admin.button variant="text" :href="route('secretaria.derivaciones.show', $referral['codDer'])" :aria-label="'Ver derivación '.$referral['codDer'].' de '.$referral['nombrePaciente']">Ver derivación <x-admin.icon name="arrow" /></x-admin.button>
                </li>
            @endforeach
        </ul>
        @if($referrals['hayMas'])
            <x-admin.button variant="text" :href="route('secretaria.derivaciones.index', ['estado' => 'Pendiente'])">Ver todas las pendientes</x-admin.button>
        @endif
    @else
        <p class="a-help">No hay derivaciones pendientes de agendar.</p>
    @endif
</div>
