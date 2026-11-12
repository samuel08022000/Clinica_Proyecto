@extends('layouts.secretaria')
@section('reception_title', $history ? 'Historial administrativo' : 'Buscar pacientes')
@section('breadcrumb', $history ? 'Historial' : 'Pacientes')
@section('reception_content')
    <x-admin.heading :title="$history ? 'Historial administrativo' : 'Buscar pacientes'" :description="$history ? 'Localiza al paciente para consultar sus citas e incidencias administrativas.' : 'Encuentra al paciente por CI, nombre o correo.'" />
    <form method="GET" class="a-filter" action="{{ route($history ? 'secretaria.historial.index' : 'secretaria.pacientes.index') }}">
        <x-admin.field name="q" label="CI, nombre o correo" :value="$q" maxlength="100" minlength="3" help="Al menos tres letras o números. Máximo 20 coincidencias; precisa la búsqueda si es muy amplia." />
        <x-admin.button>Buscar</x-admin.button><x-admin.button variant="text" :href="route($history ? 'secretaria.historial.index' : 'secretaria.pacientes.index')">Limpiar</x-admin.button>
    </form>
    <x-patient-results :patients="$patients" />
@endsection
