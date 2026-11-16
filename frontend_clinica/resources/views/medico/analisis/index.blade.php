@extends('layouts.medico')
@section('medico_title', 'Análisis de la cita')
@section('breadcrumb')
    <a href="{{ route('medico.citas.show', $appointment['codCit']) }}">{{ $appointment['codCit'] }}</a><span aria-hidden="true">/</span>Análisis
@endsection
@section('medico_content')
    <x-admin.heading title="Análisis clínicos" :description="$appointment['nombrePaciente'].' · '.$appointment['nombreEspecialidad']">
        <x-admin.button variant="secondary" :href="route('medico.citas.show', $appointment['codCit'])">Volver a la cita</x-admin.button>
    </x-admin.heading>

    @php($errorTarget = session('medico_form_target'))
    @php($requestErrors = $errorTarget === null || $errorTarget === 'solicitud')
    @if(in_array($appointment['estado'], ['En Espera', 'Atendida'], true))
        <section class="a-panel"><h2>Solicitar estudio</h2>
            <x-admin.form :action="route('medico.analisis.store', $appointment['codCit'])" class="m-analysis-request" confirm="Registrará la solicitud para esta cita. Un estudio del mismo tipo se reutiliza en lugar de duplicarse.">
                <x-admin.field name="tipoAnalisis" label="Tipo de análisis" required maxlength="150" :show-errors="$requestErrors" help="Nombre textual del estudio, hasta 150 caracteres." />
                <x-admin.confirmation id="confirmacion-solicitud" :show-errors="$requestErrors" message="Confirmo que el estudio corresponde a esta cita." />
                <div class="a-actions"><x-admin.button>Solicitar análisis</x-admin.button></div>
            </x-admin.form>
        </section>
    @else
        <x-admin.notice type="warning">Los análisis solo pueden solicitarse cuando la cita está En Espera o Atendida.</x-admin.notice>
    @endif

    <section class="a-stack" aria-labelledby="solicitudes-analisis"><h2 id="solicitudes-analisis">Solicitudes asociadas</h2>
        @forelse($analisis as $item)
            <article id="{{ $item['codAna'] }}" class="a-panel m-analysis-card">
                <div class="m-section-heading"><div><h3>{{ $item['tipoAnalisis'] }}</h3><p class="a-help">{{ $item['codAna'] }} · Registrado {{ $item['fechaRegistro'] }}</p></div><x-admin.badge :value="$item['estado']" /></div>
                @if($item['resultado'])<div class="m-result"><h3>Resultado textual</h3><p>{{ $item['resultado'] }}</p></div>@endif
                @if(in_array($item['estado'], ['Pendiente', 'En Proceso'], true) && !$item['resultado'])
                    @php($itemErrors = $errorTarget === $item['codAna'])
                    <div class="m-analysis-actions">
                        @if($item['estado'] === 'Pendiente')
                            <x-admin.form :action="route('medico.analisis.update', $item['codAna'])" method="PATCH" confirm="Cambiará el estudio a En Proceso."><input type="hidden" name="estado" value="En Proceso"><input type="hidden" name="confirmacion" value="1"><x-admin.button variant="secondary">Marcar En Proceso</x-admin.button></x-admin.form>
                        @endif
                        <details class="a-state" @if($itemErrors && $errors->any()) open @endif><summary>Registrar resultado final</summary>
                            <x-admin.form :action="route('medico.analisis.update', $item['codAna'])" method="PATCH" confirm="Completará el análisis. El resultado final no podrá sobrescribirse.">
                                <input type="hidden" name="estado" value="Completado">
                                <x-admin.field name="resultado" :id="'resultado-'.$item['codAna']" label="Resultado textual" type="textarea" required maxlength="20000" :show-errors="$itemErrors" help="Máximo 20.000 caracteres. Al completar, el contenido queda protegido." />
                                <x-admin.confirmation :id="'confirmacion-'.$item['codAna']" :show-errors="$itemErrors" message="Confirmo que el resultado está listo para quedar finalizado." />
                                <div class="a-actions"><x-admin.button>Completar análisis</x-admin.button></div>
                            </x-admin.form>
                        </details>
                    </div>
                @elseif(!$item['resultado'])
                    <p class="a-help">El estado actual es final y no admite modificaciones desde este módulo.</p>
                @endif
            </article>
        @empty
            <x-admin.empty title="No hay solicitudes de análisis" description="Cuando la cita lo permita, registra el primer estudio con el formulario superior." />
        @endforelse
    </section>
@endsection
