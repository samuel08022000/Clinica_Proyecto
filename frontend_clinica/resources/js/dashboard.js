const dashboard = document.querySelector('[data-dashboard]');

if (dashboard) {
    const form = dashboard.querySelector('[data-dashboard-filter]');
    const period = form.querySelector('[data-dashboard-period]');
    const dates = form.querySelector('[data-dashboard-dates]');
    const syncDates = () => {
        const custom = period.value === 'personalizado';
        dates.hidden = !custom;
        dates.querySelectorAll('input').forEach((input) => {
            input.disabled = !custom;
            input.required = custom;
        });
    };

    period.addEventListener('change', syncDates);
    syncDates();
    form.addEventListener('submit', () => {
        const button = form.querySelector('[data-dashboard-submit]');
        button.disabled = true;
        button.textContent = 'Actualizando…';
        form.setAttribute('aria-busy', 'true');
    });
    window.addEventListener('pageshow', () => {
        const button = form.querySelector('[data-dashboard-submit]');
        button.disabled = false;
        button.textContent = 'Actualizar';
        form.removeAttribute('aria-busy');
    });

    dashboard.querySelectorAll('[data-dashboard-trend]').forEach((chart) => {
        const tooltip = chart.querySelector('[data-chart-tooltip]');
        const svg = chart.querySelector('svg');
        const points = [...chart.querySelectorAll('[data-chart-point]')];
        let selected = -1;
        const showPoint = (index) => {
            selected = Math.max(0, Math.min(index, points.length - 1));
            points.forEach((point, i) => point.classList.toggle('is-active', i === selected));
            const point = points[selected];
            tooltip.textContent = `${point.dataset.label}: ${Number(point.dataset.value).toLocaleString('es-BO')} pacientes`;
            tooltip.hidden = false;
        };
        const clearPoint = () => {
            tooltip.hidden = true;
            points.forEach((point) => point.classList.remove('is-active'));
        };
        const resize = () => {
            const width = Math.max(240, chart.clientWidth);
            const x = (index) => points.length > 1 ? 42 + index / (points.length - 1) * (width - 70) : width / 2;
            svg.setAttribute('viewBox', `0 0 ${width} 180`);
            chart.querySelectorAll('.ad-gridline').forEach((line) => line.setAttribute('x2', width - 28));
            points.forEach((point, index) => point.setAttribute('cx', x(index)));
            chart.querySelector('.ad-trend-line')?.setAttribute('points', points.map((point) => `${point.getAttribute('cx')},${point.getAttribute('cy')}`).join(' '));
            const baseStep = Math.max(1, Math.ceil((points.length - 1) / 5));
            const capacity = Math.max(2, Math.floor((width - 70) / 64));
            const step = baseStep * Math.max(1, Math.ceil((points.length - 1) / (capacity * baseStep)));
            chart.querySelectorAll('[data-chart-tick]').forEach((tick) => {
                const index = Number(tick.dataset.index);
                tick.setAttribute('x', x(index));
                tick.style.display = index % step === 0 ? '' : 'none';
            });
        };
        resize();
        if ('ResizeObserver' in window) {
            new ResizeObserver(resize).observe(chart);
        } else {
            window.addEventListener('resize', resize);
        }
        chart.addEventListener('pointerover', (event) => {
            const point = event.target.closest('[data-chart-point]');
            if (!point) {
                clearPoint();
                return;
            }
            showPoint(points.indexOf(point));
        });
        chart.addEventListener('pointerleave', clearPoint);
        svg.addEventListener('keydown', (event) => {
            if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                event.preventDefault();
                showPoint(event.key === 'Home' ? 0 : event.key === 'End' ? points.length - 1 : selected + (event.key === 'ArrowLeft' ? -1 : 1));
            }
        });
        svg.addEventListener('blur', clearPoint);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                clearPoint();
            }
        });
    });
}
