import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/clinical-live.js', import.meta.url), 'utf8');
function setup() {
    const live = { innerHTML: 'Confirmada' };
    const status = { hidden: true, textContent: '' };
    const events = {};
    let dirty = false;
    let refresh;
    let requests = 0;
    const context = {
        document: { hidden: false, querySelector(selector) {
            if (selector === '[data-clinical-live-content]') return live;
            if (selector === '[data-clinical-live-status]') return status;
            return dirty ? {} : null;
        }, addEventListener(type, listener) { events[type] = listener; } },
        window: { location: { href: 'https://clinic.test/paciente/citas' }, setInterval(callback, ms) { refresh = callback; assert.equal(ms, 8000); } },
        DOMParser: class { parseFromString() { return { querySelector: () => ({ innerHTML: 'En Espera · Registramos tu llegada' }) }; } },
        fetch: async (url, options) => { requests++; assert.equal(options.credentials, 'same-origin'); return { ok: true, text: async () => '<main>En Espera</main>' }; },
    };
    vm.runInNewContext(source, context);
    return { live, status, events, refresh, setDirty(value) { dirty = value; }, requests: () => requests };
}

test('el panel abierto actualiza el estado y la notificación al consultar el servidor', async () => {
    const { refresh, live, status, requests } = setup();
    await refresh();
    assert.match(live.innerHTML, /En Espera.*llegada/);
    assert.equal(status.hidden, false);
    assert.equal(requests(), 1);
});

test('la actualización no reemplaza formularios con cambios del usuario', async () => {
    const { refresh, setDirty, live, requests } = setup();
    setDirty(true);
    await refresh();
    assert.equal(live.innerHTML, 'Confirmada');
    assert.equal(requests(), 0);
});
