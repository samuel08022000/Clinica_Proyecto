@extends('administracion.pdf.layout')
@section('pdf_title', $title)
@section('pdf_body_class', 'profile-sheet')
@section('pdf_content')
    <div class="scope"><p><strong>{{ $record['nombreCompleto'] }}</strong></p><p>{{ $code }} · Datos administrativos actuales</p></div>
    <table class="profile"><tbody>
        @foreach(['ci' => 'Cédula de identidad', 'email' => 'Correo', 'telefono' => 'Teléfono', 'ciudadResidencia' => 'Ciudad de residencia', 'matriculaProfesional' => 'Matrícula profesional', 'estado' => 'Estado', 'estadoCuenta' => 'Estado de cuenta', 'estadoUsuario' => 'Estado del usuario'] as $key => $label)
            @if(array_key_exists($key, $record))<tr><td>{{ $label }}</td><td>{{ $record[$key] ?: 'Sin dato registrado' }}</td></tr>@endif
        @endforeach
        @if($section === 'medicos')<tr><td>Duración general de consulta</td><td>{{ $record['duracionConsultaMin'] ? $record['duracionConsultaMin'].' minutos' : 'Sin duración general' }}</td></tr>@endif
        @if($section === 'pacientes')<tr><td>Habilitación para reservar</td><td>{{ $record['habilitadoParaReservar'] ? 'Habilitado' : 'Restringido' }}@if($record['motivoInhabilitacion'])<br>{{ $record['motivoInhabilitacion'] }}@endif</td></tr><tr><td>Correo verificado</td><td>{{ $record['emailVerificado'] ? 'Sí' : 'No' }}</td></tr>@endif
    </tbody></table>
    @if($section === 'medicos')
        <h2>Especialidades vinculadas</h2><table><thead><tr><th>Especialidad</th><th>Duración</th><th>Estado</th></tr></thead><tbody>
            @forelse($record['especialidades'] as $specialty)<tr><td>{{ $specialty['nombreEspecialidad'] }}</td><td>{{ $specialty['duracionConsultaMin'] }} min</td><td>{{ $specialty['estado'] }}</td></tr>@empty<tr><td colspan="3">Sin especialidades vinculadas.</td></tr>@endforelse
        </tbody></table>
        @if($record['resumenCurricular'])<h2>Resumen curricular</h2><p class="note">{{ $record['resumenCurricular'] }}</p>@endif
    @endif
@endsection
