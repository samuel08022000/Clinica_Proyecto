import './administracion';
import './secretaria';

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

const errorSummary = document.querySelector('[data-error-summary]');
if (errorSummary) {
    errorSummary.focus();
}

const menuToggle = document.querySelector('[data-menu-toggle]');
const menu = document.querySelector('[data-menu]');

const closeMenu = () => {
    if (!menuToggle || !menu) {
        return;
    }

    menuToggle.setAttribute('aria-expanded', 'false');
    menu.classList.remove('is-open');
};

if (menuToggle && menu) {
    menuToggle.addEventListener('click', () => {
        const isOpen = menuToggle.getAttribute('aria-expanded') === 'true';
        menuToggle.setAttribute('aria-expanded', String(!isOpen));
        menu.classList.toggle('is-open', !isOpen);
    });

    menu.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeMenu));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenu();
            menuToggle.focus();
        }
    });
}

const revealItems = document.querySelectorAll('.reveal');
if (revealItems.length > 0) {
    if (reducedMotion.matches || !('IntersectionObserver' in window)) {
        revealItems.forEach((item) => item.classList.add('is-visible'));
    } else {
        const revealObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

        const revealVisibleItems = () => {
            revealItems.forEach((item) => {
                const bounds = item.getBoundingClientRect();

                if (bounds.top <= window.innerHeight && bounds.bottom >= 0) {
                    item.classList.add('is-visible');
                    revealObserver.unobserve(item);
                    return;
                }

                revealObserver.observe(item);
            });
        };

        revealVisibleItems();
        window.addEventListener('load', revealVisibleItems, { once: true });
        window.addEventListener('hashchange', revealVisibleItems);
    }
}

const bookingDays = document.querySelector('[data-booking-days]');
if (bookingDays) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/La_Paz',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date());
    const value = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    const baseDate = new Date(Date.UTC(Number(value.year), Number(value.month) - 1, Number(value.day)));
    const dayFormatter = new Intl.DateTimeFormat('es-BO', { weekday: 'short', timeZone: 'UTC' });
    const dateFormatter = new Intl.DateTimeFormat('es-BO', { day: '2-digit', month: 'short', timeZone: 'UTC' });

    bookingDays.querySelectorAll('li').forEach((item, index) => {
        const date = new Date(baseDate);
        date.setUTCDate(baseDate.getUTCDate() + index);
        const label = item.querySelector('span');
        const dateValue = item.querySelector('strong');

        if (label && dateValue) {
            label.textContent = index === 0 ? 'Hoy' : dayFormatter.format(date).replace('.', '');
            dateValue.textContent = dateFormatter.format(date).replace('.', '');
        }
    });
}

const stageImage = document.querySelector('[data-stage-image]');
const heroTitle = document.querySelector('#portal-title');

if (!reducedMotion.matches && (stageImage || heroTitle)) {
    let animationFrame = null;

    const updateScrollMotion = () => {
        const progress = Math.min(window.scrollY / Math.max(window.innerHeight, 1), 1);

        if (stageImage) {
            stageImage.style.transform = `scale(${1.01 + progress * 0.045})`;
        }

        if (heroTitle) {
            heroTitle.style.transform = `scale(${1 - progress * 0.035})`;
            heroTitle.style.transformOrigin = 'left center';
        }

        animationFrame = null;
    };

    window.addEventListener('scroll', () => {
        if (animationFrame === null) {
            animationFrame = window.requestAnimationFrame(updateScrollMotion);
        }
    }, { passive: true });
}

document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
    toggle.addEventListener('click', () => {
        const inputId = toggle.getAttribute('aria-controls');
        const input = inputId ? document.getElementById(inputId) : null;
        const label = toggle.querySelector('span');

        if (!input) {
            return;
        }

        const shouldShow = input.type === 'password';
        input.type = shouldShow ? 'text' : 'password';
        toggle.setAttribute('aria-pressed', String(shouldShow));

        if (label) {
            label.textContent = shouldShow ? 'Ocultar' : 'Mostrar';
        }

        input.focus();
    });
});

document.querySelectorAll('[data-demo-user]').forEach((demoButton) => {
    demoButton.addEventListener('click', () => {
        const email = document.getElementById('email');

        if (email) {
            email.value = demoButton.dataset.email ?? '';
            email.dispatchEvent(new Event('change', { bubbles: true }));
        }

        document.querySelectorAll('[data-demo-user]').forEach((button) => button.removeAttribute('aria-pressed'));
        demoButton.setAttribute('aria-pressed', 'true');
        email?.focus();
    });
});

document.querySelectorAll('[data-submit-form]').forEach((form) => {
    form.addEventListener('submit', () => {
        const button = form.querySelector('[data-submit-button]');
        const label = form.querySelector('[data-button-label]');

        if (!button || button.disabled || !form.checkValidity()) {
            return;
        }

        button.disabled = true;
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');

        if (label) {
            label.textContent = button.dataset.loadingLabel ?? 'Procesando…';
        }
    });
});

const multistepForm = document.querySelector('[data-multistep-form]');
if (multistepForm) {
    const panels = [...multistepForm.querySelectorAll('[data-step-panel]')];
    const labels = [...document.querySelectorAll('[data-step-label]')];
    const progressBar = document.querySelector('[data-progress-bar]');
    let activeStep = 0;

    const updateStep = (nextStep) => {
        activeStep = Math.max(0, Math.min(nextStep, panels.length - 1));

        panels.forEach((panel, index) => {
            panel.hidden = index !== activeStep;
        });

        labels.forEach((label, index) => {
            if (index === activeStep) {
                label.setAttribute('aria-current', 'step');
            } else {
                label.removeAttribute('aria-current');
            }
        });

        if (progressBar) {
            progressBar.style.width = `${((activeStep + 1) / panels.length) * 100}%`;
        }

        panels[activeStep]?.querySelector('legend')?.scrollIntoView({ behavior: reducedMotion.matches ? 'auto' : 'smooth', block: 'center' });
        panels[activeStep]?.querySelector('input:not([disabled])')?.focus({ preventScroll: true });
    };

    multistepForm.querySelectorAll('[data-step-next]').forEach((button) => {
        button.addEventListener('click', () => {
            const fields = [...panels[activeStep].querySelectorAll('input:not([disabled])')];
            const invalidField = fields.find((field) => !field.checkValidity());

            if (invalidField) {
                invalidField.reportValidity();
                invalidField.focus();
                return;
            }

            updateStep(activeStep + 1);
        });
    });

    multistepForm.querySelectorAll('[data-step-back]').forEach((button) => {
        button.addEventListener('click', () => updateStep(activeStep - 1));
    });
}

document.querySelectorAll('[data-otp-form]').forEach((form) => {
    const digits = [...form.querySelectorAll('[data-otp-digit]')];
    const combined = form.querySelector('[data-otp-combined]');
    const submitButton = form.querySelector('[data-submit-button]');

    if (digits.length !== 6 || !combined || !submitButton) {
        return;
    }

    const updateOtp = () => {
        const code = digits.map((input) => input.value).join('');
        const complete = /^[0-9]{6}$/.test(code);
        combined.value = complete ? code : '';
        submitButton.disabled = !complete || form.dataset.otpExpired === 'true';
    };

    const distribute = (value, startIndex = 0) => {
        const numeric = value.replace(/\D/g, '').slice(0, digits.length - startIndex);
        numeric.split('').forEach((number, offset) => {
            digits[startIndex + offset].value = number;
        });
        updateOtp();
        const nextIndex = Math.min(startIndex + numeric.length, digits.length - 1);
        digits[nextIndex]?.focus();
        digits[nextIndex]?.select();
    };

    digits.forEach((digit, index) => {
        digit.addEventListener('input', () => {
            const numeric = digit.value.replace(/\D/g, '');
            if (numeric.length > 1) {
                digit.value = '';
                distribute(numeric, index);
                return;
            }
            digit.value = numeric;
            updateOtp();
            if (numeric && index < digits.length - 1) {
                digits[index + 1].focus();
                digits[index + 1].select();
            }
        });

        digit.addEventListener('keydown', (event) => {
            if (event.key === 'Backspace' && !digit.value && index > 0) {
                event.preventDefault();
                digits[index - 1].value = '';
                digits[index - 1].focus();
                updateOtp();
            } else if (event.key === 'ArrowLeft' && index > 0) {
                event.preventDefault();
                digits[index - 1].focus();
                digits[index - 1].select();
            } else if (event.key === 'ArrowRight' && index < digits.length - 1) {
                event.preventDefault();
                digits[index + 1].focus();
                digits[index + 1].select();
            }
        });

        digit.addEventListener('paste', (event) => {
            event.preventDefault();
            distribute(event.clipboardData?.getData('text') ?? '', index);
        });
    });

    form.addEventListener('submit', updateOtp);
    updateOtp();
});

document.querySelectorAll('[data-otp-expiry]').forEach((status) => {
    const expiresAt = Date.parse(status.dataset.expiresAt ?? '');
    const form = status.closest('form');
    const submitButton = form?.querySelector('[data-submit-button]');

    if (Number.isNaN(expiresAt)) {
        return;
    }

    let timer = null;
    const updateExpiry = () => {
        const remaining = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
        if (remaining === 0) {
            status.textContent = 'El código venció. Solicita uno nuevo.';
            if (form) {
                form.dataset.otpExpired = 'true';
            }
            if (submitButton) {
                submitButton.disabled = true;
            }
            if (timer !== null) {
                window.clearInterval(timer);
            }
            return;
        }
        const minutes = Math.floor(remaining / 60);
        const seconds = String(remaining % 60).padStart(2, '0');
        status.textContent = `El código vence en ${minutes}:${seconds}.`;
    };

    updateExpiry();
    if (expiresAt > Date.now()) {
        timer = window.setInterval(updateExpiry, 1000);
    }
});

document.querySelectorAll('[data-resend-form]').forEach((form) => {
    const button = form.querySelector('[data-resend-button]');
    const status = form.querySelector('[data-resend-status]');
    const availableAt = Date.parse(form.dataset.resendAt ?? '');

    if (!button || !status || Number.isNaN(availableAt)) {
        return;
    }

    let timer = null;
    const updateResend = () => {
        const remaining = Math.max(0, Math.ceil((availableAt - Date.now()) / 1000));
        button.disabled = remaining > 0;
        status.textContent = remaining > 0
            ? `Podrás solicitar otro código en ${remaining} segundos.`
            : 'Ya puedes solicitar un nuevo código.';

        if (remaining === 0 && timer !== null) {
            window.clearInterval(timer);
        }
    };

    updateResend();
    if (availableAt > Date.now()) {
        timer = window.setInterval(updateResend, 1000);
    }
});
