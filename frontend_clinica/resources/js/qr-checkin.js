import QrScanner from 'qr-scanner';

export function normalizeQr(value) {
    const raw = value.trim();
    if (!raw || raw.length > 4096) throw new Error('Código vacío o demasiado largo.');
    if (raw.includes('/pase-qr/')) {
        const match = new URL(raw, window.location.origin).pathname.match(/^\/pase-qr\/([^/]+)$/);
        if (!match) throw new Error('El enlace no contiene un pase válido.');
        return decodeURIComponent(match[1]);
    }
    return raw;
}

const root = document.querySelector('[data-qr-scanner]');
if (root) {
    const video = root.querySelector('[data-qr-video]');
    const camera = root.querySelector('[data-qr-camera]');
    const placeholder = root.querySelector('[data-qr-placeholder]');
    const indicator = root.querySelector('[data-qr-indicator]');
    const start = root.querySelector('[data-qr-start]');
    const stop = root.querySelector('[data-qr-stop]');
    const status = root.querySelector('[data-qr-status]');
    const form = document.querySelector('[data-qr-form]');
    const input = form.querySelector('[data-qr-input]');
    const result = form.querySelector('[data-qr-result]');
    const title = form.querySelector('[data-qr-result-title]');
    const summary = form.querySelector('[data-qr-summary]');
    const submit = form.querySelector('[data-qr-submit]');
    const seen = new Set();
    let busy = false;
    let starting = false;
    let active = false;
    let generation = 0;
    const cameraState = (state, text) => { camera.dataset.state = state; indicator.textContent = text; };
    const stopCamera = () => {
        generation++;
        scanner.stop();
        active = false;
        starting = false;
        placeholder.hidden = false;
        stop.disabled = true;
        start.disabled = false;
        cameraState('idle', 'Cámara detenida');
    };
    const register = async (raw, automatic = false) => {
        if (busy) return;
        let token;
        try { token = normalizeQr(raw); }
        catch (error) { status.textContent = error.message; return; }
        if (automatic && seen.has(token)) return;
        busy = true;
        seen.add(token);
        if (seen.size > 100) seen.delete(seen.values().next().value);
        submit.disabled = true;
        result.hidden = false;
        title.textContent = 'Registrando llegada…';
        summary.textContent = 'Validando el QR y la cita en el servidor.';
        status.textContent = 'Procesando el QR. Espera el resultado.';
        cameraState('processing', 'Validando QR…');
        try {
            const response = await fetch(form.action, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name=_token]').value },
                body: JSON.stringify({ tokenQr: token }),
            });
            if (response.redirected || [401, 403, 419].includes(response.status)) {
                throw new Error('La sesión o el permiso expiró. Actualiza la página e inicia sesión en Secretaría.');
            }
            const data = await response.json();
            if (!response.ok) throw new Error(data.message ?? 'No se pudo registrar la llegada.');
            if (data.estado !== 'En Espera' || !data.codCit || !data.horaLlegadaCheckin) {
                throw new Error('No se confirmó la llegada. Consulta la asistencia antes de reintentar.');
            }
            result.dataset.outcome = 'success';
            title.textContent = 'Llegada registrada · En Espera';
            const arrival = new Date(data.horaLlegadaCheckin.replace(' ', 'T').replace(/(\.\d{3})\d+/, '$1').replace(/([+-]\d{2})$/, '$1:00'));
            const arrivalText = Number.isNaN(arrival.getTime()) ? data.horaLlegadaCheckin
                : new Intl.DateTimeFormat('es-BO', { dateStyle: 'short', timeStyle: 'medium', timeZone: 'America/La_Paz' }).format(arrival);
            summary.textContent = `${data.nombrePaciente} · Cita ${data.codCit} · Llegada: ${arrivalText}. El paciente fue notificado y su panel reflejará En Espera.`;
            status.textContent = `${data.nombrePaciente}: llegada registrada · En Espera. Puedes escanear el siguiente paciente.`;
            if ('BroadcastChannel' in window) {
                const channel = new window.BroadcastChannel('clinical-updates');
                channel.postMessage({ changed: true });
                channel.close();
            }
        } catch (error) {
            result.dataset.outcome = 'error';
            title.textContent = 'No se registró una nueva llegada';
            summary.textContent = ['TypeError', 'SyntaxError'].includes(error.name)
                ? 'No se pudo confirmar la conexión con el servidor. Consulta la asistencia antes de reintentar.'
                : (error.message || 'No se pudo confirmar la operación. Consulta la asistencia antes de reintentar.');
            status.textContent = summary.textContent;
        } finally {
            busy = false;
            input.value = '';
            submit.disabled = false;
            cameraState(active ? 'scanning' : 'idle', active ? 'Escaneando' : 'Cámara detenida');
        }
    };
    const scanner = new QrScanner(video, ({ data }) => register(data, true), {
        preferredCamera: 'environment', maxScansPerSecond: 5, returnDetailedScanResult: true,
    });
    start.addEventListener('click', async () => {
        if (starting || active) return;
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            status.textContent = 'La cámara requiere HTTPS o localhost y un navegador compatible. Usa lector USB o entrada manual.';
            return;
        }
        starting = true;
        seen.clear();
        start.disabled = true;
        stop.disabled = false;
        const attempt = ++generation;
        cameraState('starting', 'Abriendo cámara…');
        try {
            await scanner.start();
            if (attempt !== generation) { scanner.stop(); return; }
            active = true;
            placeholder.hidden = true;
            cameraState('scanning', 'Escaneando');
            status.textContent = 'Escaneando. Enfoca el QR dentro del recuadro; la llegada se registra automáticamente.';
        } catch (error) {
            stopCamera();
            status.textContent = error?.name === 'NotAllowedError'
                ? 'Permiso de cámara denegado. Habilítalo en el navegador o usa lector USB o entrada manual.'
                : 'No se pudo abrir la cámara. Comprueba que exista y no esté ocupada. Puedes usar lector USB o entrada manual.';
        } finally { starting = false; }
    });
    stop.addEventListener('click', () => { stopCamera(); status.textContent = 'Cámara detenida. Puedes seguir usando lector USB o entrada manual.'; });
    form.addEventListener('submit', (event) => { event.preventDefault(); return register(input.value); });
    window.addEventListener('pagehide', () => { stopCamera(); input.value = ''; seen.clear(); });
    window.addEventListener('pageshow', () => { input.value = ''; });
    document.addEventListener('visibilitychange', () => { if (document.hidden) stopCamera(); });
}
