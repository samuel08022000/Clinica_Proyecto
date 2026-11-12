@extends('layouts.secretaria')
@section('reception_title', 'Check-in QR')
@section('breadcrumb', 'Check-in QR')
@section('reception_content')
    <x-admin.heading title="Check-in QR" description="Valida el QR del paciente y registra su llegada." />
    <x-admin.form :action="route('secretaria.checkin.store')" autocomplete="off">
        <x-admin.field name="tokenQr" label="Contenido del QR" type="password" required maxlength="4096" autocomplete="off" spellcheck="false" data-qr-input help="Escanea con el lector conectado o pega el contenido autorizado. El dato se oculta y no se conserva al enviar." />
        <div class="a-actions"><x-admin.button>Validar y registrar llegada</x-admin.button><x-admin.button variant="secondary" :href="route('secretaria.citas.index')">Consultar citas</x-admin.button></div>
    </x-admin.form>
    <section class="a-stack r-checkin-help"><h2>Recepción con lector QR</h2><p>Selecciona el campo y escanea. Un lector que actúe como teclado puede enviar con Enter. La validación y el registro se confirman juntos en el servicio.</p><p class="a-help">La consulta de acreditación y asistencia está disponible desde el detalle de la cita.</p></section>
@endsection
