const liveContent = document.querySelector('[data-clinical-live-content]');
if (liveContent) {
    let busy = false;
    const canRefresh = () => !document.hidden
        && !liveContent.contains(document.activeElement)
        && !document.querySelector('form[data-clinical-dirty]');

    const refresh = async () => {
        if (busy || !canRefresh()) return;
        busy = true;
        try {
            const response = await fetch(window.location.href, {
                credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html', 'X-Clinical-Refresh': '1' },
            });
            if (!response.ok || response.redirected) return;
            const next = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('[data-clinical-live-content]');
            if (next && canRefresh() && next.innerHTML !== liveContent.innerHTML) {
                liveContent.innerHTML = next.innerHTML;
                const status = document.querySelector('[data-clinical-live-status]');
                if (status) {
                    status.hidden = false;
                    status.textContent = liveContent.dataset.clinicalLiveMessage ?? 'El panel se actualizó con los últimos cambios de tus citas.';
                }
            }
        } catch { /* Mantener la última información si se pierde la conexión. */ }
        finally { busy = false; }
    };
    document.addEventListener('input', (event) => {
        const form = event.target.closest('form');
        if (form) form.dataset.clinicalDirty = 'true';
    });
    document.addEventListener('change', (event) => {
        const form = event.target.closest('form');
        if (form) form.dataset.clinicalDirty = 'true';
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    window.setInterval(refresh, 8000);
    if ('BroadcastChannel' in window) {
        const channel = new window.BroadcastChannel('clinical-updates');
        channel.addEventListener('message', refresh);
    }
}
