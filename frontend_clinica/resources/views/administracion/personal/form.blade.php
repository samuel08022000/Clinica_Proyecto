@extends('layouts.administracion')
@php
    $medical = $resource === 'medicos';
    $editing = ! empty($record);
    $code = $record[$medical ? 'codMed' : 'codSec'] ?? null;
    $title = ($editing ? 'Editar ' : 'Registrar ').($medical ? 'médico' : 'secretario');
    $currentSpecialties = session()->hasOldInput() ? (array) old('codigosEspecialidades', []) : $selected;
@endphp
@section('admin_title', $title)
@section('breadcrumb')<a href="{{ route('administracion.'.$resource.'.index') }}">{{ $medical ? 'Médicos' : 'Secretarios' }}</a><span aria-hidden="true">/</span>{{ $title }}@endsection
@section('admin_content')
    <x-admin.heading :title="$title" description="Los campos con * son obligatorios." />
    <x-admin.form :action="$editing ? route('administracion.'.$resource.'.update', $code) : route('administracion.'.$resource.'.store')" :method="$editing ? 'PUT' : 'POST'" :confirm="$editing && $medical ? 'Se actualizarán el perfil y sus especialidades. Si cambias la duración general, también se alinearán sus vínculos activos. Si cambias la contraseña, se revocarán sus sesiones. ¿Deseas continuar?' : null">
        <fieldset><legend>Datos personales</legend>
            <div class="a-form-grid">
                <x-admin.field name="nombreCompleto" label="Nombre completo" :value="$record['nombreCompleto'] ?? ''" required minlength="3" maxlength="150" autocomplete="name" />
                <x-admin.field name="email" label="Correo electrónico" type="email" :value="$record['email'] ?? ''" required maxlength="254" autocomplete="email" />
                <x-admin.field name="ci" label="Cédula de identidad" :value="$record['ci'] ?? ''" maxlength="30" />
                @if(!$medical)<x-admin.field name="telefono" label="Teléfono" type="tel" :value="$record['telefono'] ?? ''" maxlength="25" autocomplete="tel" />@endif
            </div>
        </fieldset>
        @if($medical)
            <fieldset><legend>Perfil profesional</legend>
                <div class="a-form-grid">
                    <x-admin.field name="matriculaProfesional" label="Matrícula profesional" :value="$record['matriculaProfesional'] ?? ''" required maxlength="80" />
                    <x-admin.field name="duracionConsultaMin" label="Duración general de consulta" type="select" :value="$record['duracionConsultaMin'] ?? ''" :options="['' => 'Sin duración general', 20 => '20 minutos', 30 => '30 minutos', 45 => '45 minutos', 60 => '60 minutos']" help="Al cambiar la duración general también se alinean los vínculos activos por especialidad. Las citas vigentes pueden impedir el cambio." />
                    <div class="a-full"><x-admin.field name="resumenCurricular" label="Resumen curricular" type="textarea" :value="$record['resumenCurricular'] ?? ''" maxlength="10000" /></div>
                </div>
            </fieldset>
            <fieldset id="codigosEspecialidades" @if($errors->has('codigosEspecialidades')) aria-describedby="codigosEspecialidades-error" @endif>
                <legend>Especialidades *</legend>
                <p class="a-help">Selecciona al menos una especialidad. Solo se ofrecen especialidades activas y vínculos existentes que se pueden conservar.</p>
                <div class="a-choices">
                    @foreach($specialties as $specialty)
                        <label class="a-check"><input type="checkbox" name="codigosEspecialidades[]" value="{{ $specialty['codEsp'] }}" @checked(in_array($specialty['codEsp'], $currentSpecialties, true))><span>{{ $specialty['nombreEspecialidad'] }}@if($specialty['soloConservar'] ?? false)<small>Especialidad inactiva. Solo se permite conservar el vínculo actual.</small>@else<small>{{ $specialty['duracionConsultaMin'] }} minutos por especialidad</small>@endif</span></label>
                    @endforeach
                </div>
                @error('codigosEspecialidades')<p id="codigosEspecialidades-error" class="a-field-error">{{ $message }}</p>@enderror
                @if(!count($specialties))<x-admin.notice type="warning">No hay especialidades disponibles. <a href="{{ route('administracion.especialidades.create') }}">Crea una especialidad</a> antes de registrar médicos.</x-admin.notice>@endif
            </fieldset>
        @endif
        @if(!$editing || $medical)
            <fieldset><legend>{{ $editing ? 'Actualizar contraseña (opcional)' : 'Acceso inicial' }}</legend>
                @if($editing)<p class="a-help">Deja ambos campos vacíos para conservar la contraseña. Al cambiarla se revocan las sesiones de este médico.</p>@endif
                <div class="a-form-grid">
                    <x-admin.field name="password" label="{{ $editing ? 'Nueva contraseña' : 'Contraseña inicial' }}" type="password" :required="!$editing" :minlength="$medical ? 8 : 10" maxlength="72" autocomplete="new-password" :help="$medical ? 'Entre 8 y 72 bytes.' : 'Entre 10 y 72 bytes; incluye mayúsculas, minúsculas y números.'" />
                    <x-admin.field name="password_confirmation" label="Confirmar contraseña" type="password" :required="!$editing" maxlength="72" autocomplete="new-password" />
                </div>
            </fieldset>
        @endif
        <div class="a-actions"><x-admin.button>Guardar {{ $medical ? 'médico' : 'secretario' }}</x-admin.button><x-admin.button variant="secondary" :href="$editing ? route('administracion.'.$resource.'.show', $code) : route('administracion.'.$resource.'.index')">Cancelar</x-admin.button></div>
    </x-admin.form>
@endsection
