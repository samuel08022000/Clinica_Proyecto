// Native dialogs preserve focus, trap keyboard navigation and support Escape.
const adminMenu = document.querySelector('#admin-mobile-menu');
const menuButton = document.querySelector('[data-open-admin-menu]');
if (adminMenu && menuButton) {
    menuButton.addEventListener('click', () => {
        adminMenu.showModal();
        menuButton.setAttribute('aria-expanded', 'true');
    });
    adminMenu.addEventListener('close', () => menuButton.setAttribute('aria-expanded', 'false'));
    adminMenu.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => adminMenu.close()));
    window.matchMedia('(min-width: 1101px)').addEventListener('change', (event) => {
        if (event.matches && adminMenu.open) adminMenu.close();
    });
}
document.querySelectorAll('[data-close-dialog]').forEach((button) => {
    button.addEventListener('click', () => button.closest('dialog')?.close());
});

const confirmation = document.querySelector('[data-admin-confirm]');
let pendingForm = null;
let pendingButton = null;
let approvedForm = null;
confirmation?.querySelector('[data-confirm-accept]')?.addEventListener('click', () => {
    const form = pendingForm;
    const button = pendingButton;
    approvedForm = form;
    confirmation.close();
    if (form?.isConnected) form.requestSubmit(button || undefined);
});
confirmation?.addEventListener('close', () => {
    pendingButton?.focus();
    pendingForm = null;
    pendingButton = null;
});

document.querySelectorAll('[data-admin-form]').forEach((form) => {
    if (form.matches('[data-qr-form]')) return;
    form.addEventListener('submit', (event) => {
        if (form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }
        if (!form.checkValidity()) return;
        if (form.dataset.confirm && approvedForm !== form && confirmation?.showModal) {
            event.preventDefault();
            pendingForm = form;
            pendingButton = event.submitter;
            confirmation.querySelector('[data-confirm-message]').textContent = form.dataset.confirm;
            confirmation.showModal();
            return;
        }
        approvedForm = null;
        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
        const sending = form.querySelector('[data-admin-sending]');
        if (sending) sending.hidden = false;
    });
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-admin-form]').forEach((form) => {
        delete form.dataset.submitting;
        form.removeAttribute('aria-busy');
        form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = false; });
        const sending = form.querySelector('[data-admin-sending]');
        if (sending) sending.hidden = true;
    });
});

// Open a closed confirmation section when it contains an error from the server.
document.querySelectorAll('.a-state [aria-invalid="true"]').forEach((field) => {
    field.closest('details').open = true;
});

document.querySelectorAll('[data-error-field]').forEach((link) => {
    link.addEventListener('click', (event) => {
        const name = link.dataset.errorField;
        const field = document.getElementById(name) || document.querySelector(`[name="${CSS.escape(name)}"]`);
        if (!field) return;
        event.preventDefault();
        const details = field.closest('details');
        if (details) details.open = true;
        if (field.tagName === 'FIELDSET') {
            field.querySelector('input')?.focus();
        } else {
            field.focus();
        }
    });
});

// Simulador reactivo de Teoría de Colas (M/M/1 y M/M/c — Erlang C, sin dependencias externas).
const queueSimulator = document.querySelector('[data-queue-simulator]');
if (queueSimulator) {
    const EPSILON = 1e-12;
    const MAX_SERVERS = 50;
    const lambdaInput = queueSimulator.querySelector('[data-sim-input="lambda"]');
    const durationInput = queueSimulator.querySelector('[data-sim-input="duracion"]');
    const serversInput = queueSimulator.querySelector('[data-sim-input="servidores"]');
    const badgeWrap = queueSimulator.querySelector('[data-sim-badge-wrap]');
    const badgeEl = queueSimulator.querySelector('[data-sim-badge]');
    const noticeEl = queueSimulator.querySelector('[data-sim-notice]');
    const recomendacionEl = queueSimulator.querySelector('[data-sim-recomendacion]');
    const presetNoteEl = queueSimulator.querySelector('[data-sim-preset-note]');
    const sensitivityBody = queueSimulator.querySelector('[data-sim-sensitivity] tbody');

    const minServers = (lambda, mu) => {
        if (lambda <= EPSILON || mu <= EPSILON) return 0;
        return Math.floor((lambda / mu) + EPSILON) + 1;
    };

    const computeMMc = (rawLambda, rawMu, rawServers) => {
        const lambda = Math.max(0, rawLambda);
        const mu = Math.max(0, rawMu);
        const servidores = Math.max(0, Math.floor(rawServers));
        const modelo = servidores === 1 ? 'M/M/1' : 'M/M/c';

        if (lambda <= EPSILON || mu <= EPSILON) {
            return {
                modelo, lambda, mu, servidores, capacidad_total: servidores * mu,
                rho_porcentaje: (mu > EPSILON && servidores > 0) ? 0 : null,
                estado: 'sin_flujo', etiqueta: 'Sin flujo suficiente',
                p0_porcentaje: null, p_espera_porcentaje: null,
                lq: null, l: null, wq_minutos: null, w_minutos: null,
                servidores_minimos: 0,
                recomendacion: 'No se registró flujo suficiente en el periodo evaluado para estimar tiempos de espera en estado estable.',
            };
        }

        const intensidad = lambda / mu;
        const cMin = minServers(lambda, mu);
        if (servidores < 1) {
            return {
                modelo, lambda, mu, servidores: 0, capacidad_total: 0,
                rho_porcentaje: null, estado: 'saturado', etiqueta: 'Saturado (Inestable)',
                p0_porcentaje: null, p_espera_porcentaje: null,
                lq: null, l: null, wq_minutos: null, w_minutos: null,
                servidores_minimos: cMin,
                recomendacion: `Con una llegada de ${lambda.toFixed(2)} pac/h y servicio de ${mu.toFixed(2)} pac/h por médico, se requieren al menos ${cMin === 1 ? '1 médico activo' : `${cMin} médicos activos`} para estabilizar la cola (ρ < 100%).`,
            };
        }

        const rho = lambda / (servidores * mu);
        if (rho >= 1 - EPSILON) {
            return {
                modelo, lambda, mu, servidores, capacidad_total: servidores * mu,
                rho_porcentaje: rho * 100, estado: 'saturado', etiqueta: 'Saturado (Inestable)',
                p0_porcentaje: null, p_espera_porcentaje: null,
                lq: null, l: null, wq_minutos: null, w_minutos: null,
                servidores_minimos: cMin,
                recomendacion: `Con una llegada de ${lambda.toFixed(2)} pac/h y servicio de ${mu.toFixed(2)} pac/h por médico, se requieren al menos ${cMin === 1 ? '1 médico activo' : `${cMin} médicos activos`} para estabilizar la cola (ρ < 100%).`,
            };
        }

        let suma = 1;
        let termino = 1;
        for (let n = 1; n < servidores; n += 1) {
            termino = (termino * intensidad) / n;
            suma += termino;
        }
        const terminoC = (termino * intensidad) / servidores;
        const colaErlang = terminoC / (1 - rho);
        const denominador = suma + colaErlang;
        const p0 = 1 / denominador;
        const pEspera = colaErlang * p0;
        const lq = pEspera * (rho / (1 - rho));
        const wqHoras = lq / lambda;
        const wHoras = wqHoras + (1 / mu);
        const l = lq + intensidad;

        let estado = 'optimo';
        let etiqueta = 'Óptimo / Holgado';
        let recomendacion = `Capacidad holgada con ${(rho * 100).toFixed(1)}% de utilización y espera teórica estimada de ${(wqHoras * 60).toFixed(1)} min.`;
        if (rho > 0.85) {
            estado = 'alerta_congestion';
            etiqueta = 'Alerta: Alta Congestión';
            recomendacion = `La utilización alcanza ${(rho * 100).toFixed(1)}% (> 85%). Evalúa habilitar ${servidores + 1} médicos activos o revisar la duración de consulta para reducir la espera en cola.`;
        } else if (rho >= 0.70) {
            estado = 'estable';
            etiqueta = 'Estable (Carga Moderada)';
            recomendacion = `Operación estable con ${(rho * 100).toFixed(1)}% de utilización. Monitorea picos horarios para evitar superar el umbral de congestión del 85%.`;
        }

        return {
            modelo, lambda, mu, servidores, capacidad_total: servidores * mu,
            rho_porcentaje: rho * 100, estado, etiqueta,
            p0_porcentaje: p0 * 100, p_espera_porcentaje: pEspera * 100,
            lq, l, wq_minutos: wqHoras * 60, w_minutos: wHoras * 60,
            servidores_minimos: cMin, recomendacion,
        };
    };

    const fmtCola = (val, estado, dec = 2, suffix = '') => {
        if (val !== null && Number.isFinite(val)) return `${val.toFixed(dec)}${suffix}`;
        return estado === 'saturado' ? 'Saturado (∞)' : 'N/A';
    };
    const fmtProb = (val, estado) => {
        if (val !== null && Number.isFinite(val)) return `${val.toFixed(1)}%`;
        return estado === 'saturado' ? '—' : 'N/A';
    };
    const fmtRho = (val, estado) => {
        if (val !== null && Number.isFinite(val)) return `${val.toFixed(1)}%`;
        return estado === 'saturado' ? 'Sin capacidad' : 'N/A';
    };

    const setOut = (key, text) => {
        const el = queueSimulator.querySelector(`[data-sim-out="${key}"]`);
        if (el) el.textContent = text;
    };

    const renderSimulator = () => {
        if (!lambdaInput || !durationInput || !serversInput) return;
        const lambda = Number.parseFloat(lambdaInput.value);
        const duracion = Number.parseInt(durationInput.value, 10);
        const servidores = Number.parseInt(serversInput.value, 10);
        if (!Number.isFinite(lambda) || lambda < 0 || lambda > 500) return;
        if (!Number.isFinite(duracion) || duracion < 5 || duracion > 180) return;
        if (!Number.isFinite(servidores) || servidores < 1 || servidores > MAX_SERVERS) return;

        const mu = 60 / duracion;
        const actual = computeMMc(lambda, mu, servidores);

        if (badgeWrap) {
            badgeWrap.className = `queue-badge-wrap queue-badge-wrap--${actual.estado}`;
        }
        if (badgeEl) badgeEl.textContent = actual.etiqueta;
        if (noticeEl) {
            const type = actual.estado === 'saturado'
                ? 'error'
                : (actual.estado === 'alerta_congestion' ? 'warning' : (actual.estado === 'optimo' ? 'success' : 'info'));
            noticeEl.className = `a-notice a-notice--${type}`;
        }
        if (recomendacionEl) recomendacionEl.textContent = actual.recomendacion;

        setOut('modelo', actual.modelo);
        setOut('mu', `${actual.mu.toFixed(2)} pac/h`);
        setOut('capacidad', `${actual.capacidad_total.toFixed(2)} pac/h`);
        setOut('rho', fmtRho(actual.rho_porcentaje, actual.estado));
        setOut('p0', fmtProb(actual.p0_porcentaje, actual.estado));
        setOut('p_espera', fmtProb(actual.p_espera_porcentaje, actual.estado));
        setOut('lq', fmtCola(actual.lq, actual.estado, 2));
        setOut('l', fmtCola(actual.l, actual.estado, 2));
        setOut('wq', fmtCola(actual.wq_minutos, actual.estado, 1, ' min'));
        setOut('w', fmtCola(actual.w_minutos, actual.estado, 1, ' min'));
        setOut('c_min', actual.servidores_minimos > 0 ? String(actual.servidores_minimos) : 'N/A');

        if (!sensitivityBody) return;
        const scenarios = [
            { label: `Escenario elegido (c = ${servidores})`, isCurrent: true, metrics: actual },
        ];
        const candidates = actual.estado === 'saturado' && actual.servidores_minimos > servidores
            ? [
                [actual.servidores_minimos, `Mínimo estable (c = ${actual.servidores_minimos})`],
                [actual.servidores_minimos + 1, `Estable +1 médico (c = ${actual.servidores_minimos + 1})`],
                [actual.servidores_minimos + 2, `Estable +2 médicos (c = ${actual.servidores_minimos + 2})`],
            ]
            : [
                [servidores + 1, `+1 médico (c = ${servidores + 1})`],
                [servidores + 2, `+2 médicos (c = ${servidores + 2})`],
            ];
        const seen = new Set([servidores]);
        candidates.forEach(([cVal, label]) => {
            if (cVal >= 1 && cVal <= MAX_SERVERS && !seen.has(cVal)) {
                seen.add(cVal);
                scenarios.push({ label, isCurrent: false, metrics: computeMMc(lambda, mu, cVal) });
            }
        });

        sensitivityBody.textContent = '';
        scenarios.forEach(({ label, isCurrent, metrics }) => {
            const tr = document.createElement('tr');
            if (isCurrent) tr.className = 'queue-row-highlight';

            const tdLabel = document.createElement('td');
            const strong = document.createElement('strong');
            strong.textContent = label;
            tdLabel.appendChild(strong);
            tr.appendChild(tdLabel);

            const cells = [
                [metrics.modelo, false],
                [String(metrics.servidores), true],
                [`${metrics.capacidad_total.toFixed(2)} pac/h`, true],
                [fmtRho(metrics.rho_porcentaje, metrics.estado), true],
                [fmtProb(metrics.p_espera_porcentaje, metrics.estado), true],
                [fmtCola(metrics.lq, metrics.estado, 2), true],
                [fmtCola(metrics.wq_minutos, metrics.estado, 1, ' min'), true],
                [fmtCola(metrics.w_minutos, metrics.estado, 1, ' min'), true],
            ];
            cells.forEach(([value, numeric]) => {
                const td = document.createElement('td');
                if (numeric) td.className = 'a-numeric';
                td.textContent = value;
                tr.appendChild(td);
            });

            const tdDiag = document.createElement('td');
            const wrap = document.createElement('span');
            wrap.className = `queue-badge-wrap queue-badge-wrap--${metrics.estado}`;
            const badge = document.createElement('span');
            badge.className = 'a-badge';
            badge.textContent = metrics.etiqueta;
            wrap.appendChild(badge);
            tdDiag.appendChild(wrap);
            tr.appendChild(tdDiag);

            sensitivityBody.appendChild(tr);
        });
    };

    [lambdaInput, durationInput, serversInput].forEach((input) => {
        input?.addEventListener('input', renderSimulator);
        input?.addEventListener('change', renderSimulator);
    });

    queueSimulator.querySelectorAll('[data-sim-duration-chip]').forEach((chip) => {
        chip.addEventListener('click', () => {
            if (!durationInput) return;
            durationInput.value = chip.dataset.simDurationChip ?? '30';
            renderSimulator();
        });
    });

    document.querySelectorAll('[data-sim-preset]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            if (!lambdaInput || !durationInput || !serversInput) return;
            event.preventDefault();
            lambdaInput.value = trigger.dataset.simLambda ?? '0';
            durationInput.value = trigger.dataset.simDuracion ?? '30';
            serversInput.value = trigger.dataset.simServidores ?? '1';
            if (presetNoteEl) {
                presetNoteEl.textContent = `Escenario precargado desde la especialidad: ${trigger.dataset.simLabel ?? ''}.`;
                presetNoteEl.hidden = false;
            }
            renderSimulator();
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            queueSimulator.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
            lambdaInput.focus({ preventScroll: true });
        });
    });
}
