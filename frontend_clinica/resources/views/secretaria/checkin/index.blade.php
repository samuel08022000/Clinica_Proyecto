@extends('layouts.secretaria')
@section('reception_title', 'Check-in QR')
@section('breadcrumb', 'Check-in QR')
@section('reception_content')
    <x-admin.heading title="Check-in QR" description="Valida el QR del paciente y registra su llegada." />
    <div class="qr-workspace">
    <section class="a-panel a-stack" data-qr-scanner>
        <h2>Escanear con cámara</h2>
        <div class="qr-camera" data-qr-camera data-state="idle">
            <video data-qr-video playsinline muted aria-label="Vista en directo de la cámara para escanear QR"></video>
            <div class="qr-camera__placeholder" data-qr-placeholder><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h4l2-3h4l2 3h4v13H4z"/><circle cx="12" cy="13" r="4"/></svg><span>Activa la cámara para ver el QR aquí</span></div>
            <div class="qr-camera__frame" aria-hidden="true"></div>
            <span class="qr-camera__indicator" data-qr-indicator>Cámara detenida</span>
        </div>
        <div class="a-actions"><button type="button" class="a-button" data-qr-start>Activar cámara</button><button type="button" class="a-button a-button--secondary" data-qr-stop disabled>Detener cámara</button></div>
        <p class="a-help" role="status" aria-live="polite" data-qr-status>Al leer un QR válido se registra automáticamente la llegada y la cita pasa a En Espera.</p>
    </section>
    <div class="a-stack">
    <x-admin.form :action="route('secretaria.checkin.store')" autocomplete="off" data-qr-form>
        <section class="a-panel qr-result" data-qr-result hidden aria-live="polite" tabindex="-1"><h2 data-qr-result-title>Resultado del escaneo</h2><p data-qr-summary></p></section>
        <x-admin.field name="tokenQr" label="Contenido del QR" type="password" required maxlength="4096" autocomplete="off" spellcheck="false" data-qr-input help="Escanea con el lector conectado o pega el contenido autorizado. El dato se oculta y no se conserva al enviar." />
        <div class="a-actions"><x-admin.button data-qr-submit>Validar y registrar llegada</x-admin.button><x-admin.button variant="secondary" :href="route('secretaria.citas.index')">Consultar citas</x-admin.button></div>
    </x-admin.form>
    <section class="a-stack r-checkin-help"><h2>Recepción con lector QR</h2><p>Selecciona el campo y escanea. Un lector que actúe como teclado puede enviar con Enter. La validación y el registro se confirman juntos en el servidor.</p><p class="a-help">El paciente recibirá una notificación de su llegada. El mismo QR no registra una segunda llegada.</p></section>
    </div>
    </div>
@endsection
