import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/qr-checkin.js', import.meta.url), 'utf8')
    .replace("import QrScanner from 'qr-scanner';", '').replace('export function', 'function');

function setup({ secure = true, failure = null, rejection = null } = {}) {
    const nodes = new Map();
    function node(selector) {
        if (!nodes.has(selector)) nodes.set(selector, {
            value: '', textContent: '', disabled: false, hidden: false, events: {}, dataset: {},
            addEventListener(type, listener) { this.events[type] = listener; }, focus() {},
            querySelector: node,
        });
        return nodes.get(selector);
    }
    const form = node('[data-qr-form]');
    form.action = '/secretaria/checkin';
    let scanner;
    let requests = 0;
    class Scanner {
        constructor(video, callback) { this.callback = callback; this.stopped = 0; scanner = this; }
        async start() { if (failure) throw failure; }
        stop() { this.stopped++; }
    }
    const context = vm.createContext({ QrScanner: Scanner, URL,
        document: { querySelector: node, addEventListener() {}, hidden: false },
        navigator: { mediaDevices: { getUserMedia() {} } },
        window: { location: { origin: 'https://clinic.test' }, isSecureContext: secure, addEventListener() {} },
        fetch: async (url, options) => {
            requests++;
            assert.equal(url, '/secretaria/checkin');
            assert.equal(options.method, 'POST');
            return { ok: !rejection, status: rejection ? 409 : 200, json: async () => rejection ? { message: rejection } : ({ codCit: 'CIT1', codPac: 'PAC1', nombrePaciente: 'Paciente de prueba', horaLlegadaCheckin: '2026-11-17 10:05:00-04', estado: 'En Espera' }) };
        },
    });
    vm.runInContext(source, context);
    const submit = () => form.events.submit({ preventDefault() {} });
    form.requestSubmit = submit;
    return { node, scanner, context, submit, requests: () => requests };
}

test('normaliza tokens, URL codificada y rechaza enlaces con segmentos adicionales', () => {
    const { context } = setup();
    assert.equal(vm.runInContext("normalizeQr(' token-firmado ')", context), 'token-firmado');
    assert.equal(vm.runInContext("normalizeQr('https://clinic.test/pase-qr/abc%3D?x=1')", context), 'abc=');
    assert.throws(() => vm.runInContext("normalizeQr('/pase-qr/token/otra-ruta')", context));
    assert.throws(() => vm.runInContext("normalizeQr('')", context));
});

test('cámara denegada, inexistente y contexto inseguro conservan alternativa manual', async () => {
    for (const options of [{ failure: { name: 'NotAllowedError' } }, { failure: { name: 'NotFoundError' } }, { secure: false }]) {
        const { node } = setup(options);
        await node('[data-qr-start]').events.click();
        assert.match(node('[data-qr-status]').textContent, /lector USB|entrada manual/);
        assert.equal(node('[data-qr-start]').disabled, false);
    }
});

test('detener cámara libera el escáner y permite volver a iniciarlo', async () => {
    const { node, scanner } = setup();
    await node('[data-qr-start]').events.click();
    assert.equal(node('[data-qr-placeholder]').hidden, true);
    assert.equal(node('[data-qr-indicator]').textContent, 'Escaneando');
    node('[data-qr-stop]').events.click();
    assert.equal(node('[data-qr-placeholder]').hidden, false);
    assert.ok(scanner.stopped > 0);
    assert.equal(node('[data-qr-start]').disabled, false);
});

test('el QR registra llegada automáticamente una sola vez y mantiene la cámara escaneando', async () => {
    const { node, scanner, requests } = setup();
    await node('[data-qr-start]').events.click();
    scanner.callback({ data: 'token-firmado' });
    scanner.callback({ data: 'token-firmado' });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(requests(), 1);
    assert.equal(node('[data-qr-result]').hidden, false);
    assert.match(node('[data-qr-result-title]').textContent, /En Espera/);
    assert.match(node('[data-qr-summary]').textContent, /Paciente de prueba.*CIT1.*10:05/);
    assert.equal(node('[data-qr-placeholder]').hidden, true);
    assert.equal(node('[data-qr-indicator]').textContent, 'Escaneando');
    scanner.callback({ data: 'token-firmado' });
    assert.equal(requests(), 1);
});

test('USB y entrada manual registran llegada sin una segunda confirmación', async () => {
    const { node, submit, requests } = setup();
    node('[data-qr-input]').value = 'token-usb';
    await submit();
    assert.equal(requests(), 1);
    node('[data-qr-input]').value = 'token-manual';
    await submit();
    assert.equal(requests(), 2);
    assert.match(node('[data-qr-result-title]').textContent, /En Espera/);
    assert.equal(node('[data-qr-input]').value, '');
});

test('un rechazo muestra el motivo concreto y evita repetir automáticamente el QR rechazado', async () => {
    const { node, scanner, requests } = setup({ rejection: 'Esta cita corresponde al 2026-11-24. Solo se registra llegada el día de la cita.' });
    await node('[data-qr-start]').events.click();
    await scanner.callback({ data: 'token-futuro' });
    assert.equal(node('[data-qr-result]').dataset.outcome, 'error');
    assert.match(node('[data-qr-summary]').textContent, /2026-11-24/);
    await scanner.callback({ data: 'token-futuro' });
    assert.equal(requests(), 1);
    assert.equal(node('[data-qr-indicator]').textContent, 'Escaneando');
});
