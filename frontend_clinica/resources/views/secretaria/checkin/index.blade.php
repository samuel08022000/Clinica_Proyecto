@extends('layouts.secretaria')
@section('reception_title', 'Check-in QR')
@section('breadcrumb', 'Check-in QR')
@section('reception_content')
    <x-admin.heading title="Check-in QR" description="Valida el QR del paciente y registra su llegada." />
    <section class="a-panel a-stack" data-qr-scanner>
        <h2>Escanear con cámara</h2>
        <video data-qr-video playsinline muted hidden aria-label="Vista de la cámara para escanear QR"></video>
        <div class="a-actions"><button type="button" class="a-button" data-qr-start>Activar cámara</button><button type="button" class="a-button a-button--secondary" data-qr-stop disabled>Detener cámara</button></div>
        <p class="a-help" role="status" aria-live="polite" data-qr-status>Permite el acceso a la cámara para leer el QR. También puedes usar lector USB o entrada manual.</p>
    </section>
    <x-admin.form :action="route('secretaria.checkin.store')" autocomplete="off" data-qr-form :data-preview-url="route('secretaria.checkin.preview')">
        <x-admin.field name="tokenQr" label="Contenido del QR" type="password" required maxlength="4096" autocomplete="off" spellcheck="false" data-qr-input help="Escanea con el lector conectado o pega el contenido autorizado. El dato se oculta y no se conserva al enviar." />
        <input type="hidden" name="codCit" data-qr-appointment>
        <section class="a-stack" data-qr-preview hidden><h2>Comprueba la identidad del paciente</h2><p data-qr-summary></p></section>
        <div class="a-actions"><x-admin.button data-qr-submit>Validar y registrar llegada</x-admin.button><x-admin.button variant="secondary" :href="route('secretaria.citas.index')">Consultar citas</x-admin.button></div>
    </x-admin.form>
    <section class="a-stack r-checkin-help"><h2>Recepción con lector QR</h2><p>Selecciona el campo y escanea. Un lector que actúe como teclado puede enviar con Enter. La validación y el registro se confirman juntos en el servicio.</p><p class="a-help">La consulta de acreditación y asistencia está disponible desde el detalle de la cita.</p></section>
@endsection
