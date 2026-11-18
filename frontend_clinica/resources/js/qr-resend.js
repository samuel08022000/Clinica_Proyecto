const updateResendButtons = () => {
    document.querySelectorAll('[data-qr-resend-form]').forEach((form) => {
        if (form.dataset.sending === 'true') return;
        const seconds = Math.max(0, Math.ceil(Number(form.dataset.retryUntil) - Date.now() / 1000));
        const button = form.querySelector('[data-qr-resend-button]');
        const status = form.querySelector('[data-qr-resend-status]');
        if (!button || !status) return;
        button.disabled = seconds > 0;
        status.textContent = seconds > 0 ? `Podrás reintentar en ${seconds} segundos.` : 'Puedes solicitar el correo QR.';
    });
};
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!form.matches('[data-qr-resend-form]')) return;
    if (form.dataset.sending === 'true' || Number(form.dataset.retryUntil) * 1000 > Date.now()) {
        event.preventDefault();
        updateResendButtons();
        return;
    }
    form.dataset.sending = 'true';
    form.querySelector('[data-qr-resend-button]').disabled = true;
    form.querySelector('[data-qr-resend-status]').textContent = 'Enviando tu pase QR. Espera el resultado.';
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-qr-resend-form]').forEach((form) => { delete form.dataset.sending; });
    updateResendButtons();
});
window.setInterval(updateResendButtons, 1000);
updateResendButtons();
