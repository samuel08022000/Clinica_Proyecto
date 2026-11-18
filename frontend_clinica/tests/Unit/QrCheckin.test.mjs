import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/qr-checkin.js', import.meta.url), 'utf8')
    .replace("import QrScanner from 'qr-scanner';", '').replace('export function', 'function');

function setup({ secure = true, failure = null } = {}) {
    const nodes = new Map();
    function node(selector) {
        if (!nodes.has(selector)) nodes.set(selector, {
            value: '', textContent: '', disabled: false, hidden: false, events: {},
            addEventListener(type, listener) { this.events[type] = listener; }, focus() {},
            querySelector: node,
        });
        return nodes.get(selector);
    }
    const form = node('[data-qr-form]');
    form.dataset = { previewUrl: '/secretaria/checkin/validar' };
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
        fetch: async () => { requests++; return { ok: true, json: async () => ({ codCit: 'CIT1', codPac: 'PAC1', nombrePaciente: 'Paciente de prueba', fecha: '2026-11-17', horaInicio: '10:00', estado: 'Confirmada' }) }; },
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
    assert.equal(node('[data-qr-video]').hidden, false);
    node('[data-qr-stop]').events.click();
    assert.equal(node('[data-qr-video]').hidden, true);
    assert.ok(scanner.stopped > 0);
    assert.equal(node('[data-qr-start]').disabled, false);
});

test('escaneo repetido valida una sola vez y requiere confirmación humana', async () => {
    const { node, scanner, requests } = setup();
    scanner.callback({ data: 'token-firmado' });
    scanner.callback({ data: 'token-firmado' });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(requests(), 1);
    assert.equal(node('[data-qr-appointment]').value, 'CIT1');
    assert.equal(node('[data-qr-preview]').hidden, false);
    assert.match(node('[data-qr-submit]').textContent, /Confirmar llegada/);
});

test('USB y entrada manual validan y cambiar de QR obliga a validar nuevamente', async () => {
    const { node, submit, requests } = setup();
    node('[data-qr-input]').value = 'token-usb';
    await submit();
    assert.equal(requests(), 1);
    node('[data-qr-input]').value = 'token-manual';
    node('[data-qr-input]').events.input();
    assert.equal(node('[data-qr-appointment]').value, '');
    await submit();
    assert.equal(requests(), 2);
});
