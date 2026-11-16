@extends('layouts.medico')
@section('medico_title', 'Emitir derivación')
@section('breadcrumb')
    <a href="{{ route('medico.citas.show', $appointment['codCit']) }}">{{ $appointment['codCit'] }}</a><span aria-hidden="true">/</span>Nueva derivación
@endsection
@section('medico_content')
    <x-admin.heading title="Emitir derivación" :description="$appointment['nombrePaciente'].' · '.$appointment['nombreEspecialidad']" />
    @if($appointment['estado'] !== 'Atendida')
        <x-admin.notice type="warning"><h2>La cita todavía no admite derivaciones</h2><p>Primero debe finalizarse la consulta. El servidor volverá a verificar esta regla al enviar el formulario.</p></x-admin.notice>
    @elseif(!count($especialidades))
        <x-admin.notice type="warning"><h2>No hay especialidades activas disponibles</h2><p>No es posible emitir derivaciones mientras no existan especialidades destino habilitadas en el sistema.</p></x-admin.notice>
    @else
        <x-admin.form :action="route('medico.derivaciones.store', $appointment['codCit'])" class="a-form" confirm="Emitirá una derivación para Secretaría. Verifica la especialidad y el destinatario antes de continuar.">
            <fieldset><legend>Destino de la interconsulta</legend><div class="a-form-grid">
                <x-admin.field name="codEspDestino" id="codEspDestino" label="Especialidad destino" type="select" required :options="['' => 'Selecciona una especialidad'] + collect($especialidades)->pluck('nombreEspecialidad', 'codEsp')->all()" data-medico-specialty />
                <div class="a-field"><label for="codMedDestino">Médico destinatario <span class="a-muted">(opcional)</span></label>
                    <select name="codMedDestino" id="codMedDestino" data-medico-destination @if($errors->has('codMedDestino')) aria-invalid="true" aria-describedby="codMedDestino-error" @endif>
                        <option value="">Cualquier médico habilitado</option>
                        @foreach($medicos as $medico)<option value="{{ $medico['codMed'] }}" data-specialty="{{ $medico['codEsp'] }}" @selected(old('codMedDestino') === $medico['codMed'])>{{ $medico['nombreCompleto'] }}</option>@endforeach
                    </select>
                    <p class="a-help">La lista se limita a perfiles activos vinculados con la especialidad.</p>
                    @error('codMedDestino')<p id="codMedDestino-error" class="a-field-error">{{ $message }}</p>@enderror
                </div>
                <div class="a-full">
                    <x-admin.field name="motivo" label="Motivo de derivación" type="textarea" required maxlength="2000" help="Hasta 2.000 caracteres. No incluyas información ajena a la interconsulta." />
                </div>
            </div></fieldset>
            <x-admin.confirmation message="Confirmo que el destino corresponde a la atención finalizada." />
            <div class="a-actions"><x-admin.button>Emitir derivación</x-admin.button><x-admin.button variant="text" :href="route('medico.citas.show', $appointment['codCit'])">Cancelar</x-admin.button></div>
        </x-admin.form>
    @endif
@endsection
