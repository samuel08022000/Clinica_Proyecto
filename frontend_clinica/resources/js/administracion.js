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
