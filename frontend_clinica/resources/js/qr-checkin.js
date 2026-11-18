import QrScanner from 'qr-scanner';

export function normalizeQr(value) {
    const raw = value.trim();
    if (!raw || raw.length > 4096) throw new Error('Código vacío o demasiado largo.');
    if (raw.includes('/pase-qr/')) {
        const url = new URL(raw, window.location.origin);
        const match = url.pathname.match(/^\/pase-qr\/([^/]+)$/);
        if (!match) throw new Error('El enlace no contiene un pase válido.');
        return decodeURIComponent(match[1]);
    }
    return raw;
}

const root = document.querySelector('[data-qr-scanner]');
if (root) {
    const video = root.querySelector('[data-qr-video]');
    const start = root.querySelector('[data-qr-start]');
    const stop = root.querySelector('[data-qr-stop]');
    const status = root.querySelector('[data-qr-status]');
    const form = document.querySelector('[data-qr-form]');
    const input = form.querySelector('[data-qr-input]');
    const code = form.querySelector('[data-qr-appointment]');
    const preview = form.querySelector('[data-qr-preview]');
    const summary = form.querySelector('[data-qr-summary]');
    const submit = form.querySelector('[data-qr-submit]');
    let validated = null;
    let busy = false;
    let starting = false;
    let generation = 0;
    const scanner = new QrScanner(video, ({ data }) => {
        if (busy || validated) return;
        input.value = data;
        stopCamera();
        form.requestSubmit();
    }, { preferredCamera: 'environment', maxScansPerSecond: 5, returnDetailedScanResult: true });

    const stopCamera = () => {
        generation++;
        scanner.stop();
        video.hidden = true;
        stop.disabled = true;
        start.disabled = false;
        starting = false;
    };
    const reset = () => {
        validated = null;
        code.value = '';
        preview.hidden = true;
        submit.textContent = 'Validar QR';
    };
    start.addEventListener('click', async () => {
        if (starting) return;
        reset();
        input.value = '';
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            status.textContent = 'La cámara requiere HTTPS o localhost y un navegador compatible. Usa lector USB o entrada manual.';
            return;
        }
        starting = true;
        start.disabled = true;
        stop.disabled = false;
        const attempt = ++generation;
        try {
            video.hidden = false;
            await scanner.start();
            if (attempt !== generation) { scanner.stop(); return; }
            status.textContent = 'Cámara activa. Enfoca el QR del pase.';
        } catch (error) {
            stopCamera();
            status.textContent = error?.name === 'NotAllowedError'
                ? 'Permiso de cámara denegado. Puedes habilitarlo en el navegador o usar lector USB o entrada manual.'
                : 'No se pudo abrir la cámara. Comprueba que exista y no esté ocupada. Puedes usar lector USB o entrada manual.';
        } finally { starting = false; }
    });
    stop.addEventListener('click', () => { stopCamera(); status.textContent = 'Cámara detenida.'; });
    input.addEventListener('input', reset);
    form.addEventListener('submit', async (event) => {
        if (busy) { event.preventDefault(); return; }
        if (validated && input.value === validated) {
            busy = true;
            stopCamera();
            submit.disabled = true;
            return;
        }
        event.preventDefault();
        busy = true;
        submit.disabled = true;
        stopCamera();
        try {
            const token = normalizeQr(input.value);
            const response = await fetch(form.dataset.previewUrl, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name=_token]').value },
                body: JSON.stringify({ tokenQr: token }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message ?? 'No se pudo validar el QR.');
            input.value = token;
            validated = token;
            code.value = data.codCit;
            summary.textContent = `${data.nombrePaciente} · ${data.codPac} · ${data.codCit} · ${data.fecha} ${data.horaInicio} · ${data.estado}`;
            preview.hidden = false;
            submit.textContent = 'Confirmar llegada de este paciente';
            status.textContent = 'QR validado. Comprueba la identidad antes de confirmar la llegada.';
            submit.focus();
        } catch (error) {
            reset();
            status.textContent = error.message || 'No se pudo validar. Consulta la asistencia antes de reintentar.';
        } finally { busy = false; submit.disabled = false; }
    });
    window.addEventListener('pagehide', () => { stopCamera(); input.value = ''; reset(); });
    document.addEventListener('visibilitychange', () => { if (document.hidden) stopCamera(); });
    reset();
}
