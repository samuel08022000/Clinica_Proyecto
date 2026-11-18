import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/qr-resend.js', import.meta.url), 'utf8');
function setup() {
    let now = 1_000_000;
    const button = { disabled: false };
    const status = { textContent: '' };
    const events = {};
    const form = { dataset: { retryUntil: 1030 }, matches: () => true,
        querySelector: selector => selector === '[data-qr-resend-button]' ? button : status };
    let tick;
    vm.runInNewContext(source, { Date: { now: () => now },
        document: { querySelectorAll: () => [form], addEventListener: (type, listener) => { events[type] = listener; } },
        window: { addEventListener() {}, setInterval: callback => { tick = callback; } },
    });
    const submit = () => {
        let prevented = false;
        events.submit({ target: form, preventDefault() { prevented = true; } });
        return prevented;
    };
    return { button, status, form, tick, submit, advance(seconds) { now += seconds * 1000; } };
}

test('el botón permanece deshabilitado y muestra los segundos hasta vencer el límite', () => {
    const { button, status, advance, tick, submit } = setup();
    assert.equal(button.disabled, true);
    assert.match(status.textContent, /30 segundos/);
    assert.equal(submit(), true);
    advance(31); tick();
    assert.equal(button.disabled, false);
    assert.match(status.textContent, /Puedes solicitar/);
});

test('durante el envío se bloquea un segundo submit aunque se pulse Enter', () => {
    const { button, status, advance, tick, submit } = setup();
    advance(31); tick();
    assert.equal(submit(), false);
    assert.equal(button.disabled, true);
    assert.match(status.textContent, /Enviando tu pase QR/);
    assert.equal(submit(), true);
});
