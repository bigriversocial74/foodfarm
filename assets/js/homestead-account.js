(() => {
    'use strict';

    const form = document.querySelector('[data-password-form]');
    if (!form) return;

    const current = form.querySelector('[data-password-field="current"]');
    const next = form.querySelector('[data-password-field="new"]');
    const confirm = form.querySelector('[data-password-field="confirm"]');
    const strength = form.querySelector('.account-strength');
    const bar = form.querySelector('[data-strength-bar]');
    const label = form.querySelector('[data-strength-label]');
    const checks = {
        length: form.querySelector('[data-password-check="length"]'),
        different: form.querySelector('[data-password-check="different"]'),
        match: form.querySelector('[data-password-check="match"]'),
    };

    const setValid = (element, valid) => element?.classList.toggle('valid', valid);

    const update = () => {
        const value = next?.value || '';
        const currentValue = current?.value || '';
        const confirmValue = confirm?.value || '';
        const lengthValid = value.length >= 12;
        const differentValid = value !== '' && currentValue !== '' && value !== currentValue;
        const matchValid = value !== '' && confirmValue !== '' && value === confirmValue;

        setValid(checks.length, lengthValid);
        setValid(checks.different, differentValid);
        setValid(checks.match, matchValid);

        let score = 0;
        if (value.length >= 12) score += 1;
        if (value.length >= 16) score += 1;
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score += 1;
        if (/\d/.test(value)) score += 1;
        if (/[^A-Za-z0-9]/.test(value)) score += 1;

        const percent = value === '' ? 0 : Math.max(18, score * 20);
        if (bar) bar.style.width = `${percent}%`;
        if (strength) strength.dataset.level = score >= 4 ? 'strong' : score >= 2 ? 'medium' : 'weak';
        if (label) {
            label.textContent = value === ''
                ? 'Use at least 12 characters. A longer, unique passphrase is easier to remember and harder to guess.'
                : score >= 4
                    ? 'Strong password structure. Confirm it exactly before submitting.'
                    : score >= 2
                        ? 'Good start. More length or character variety will strengthen this password.'
                        : 'This password meets too few strength signals. Add length and variety.';
        }
    };

    [current, next, confirm].forEach((field) => field?.addEventListener('input', update));

    form.querySelectorAll('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const field = form.querySelector(`[data-password-field="${button.dataset.passwordToggle}"]`);
            if (!field) return;
            const showing = field.type === 'text';
            field.type = showing ? 'password' : 'text';
            button.textContent = showing ? 'Show' : 'Hide';
            button.setAttribute('aria-label', `${showing ? 'Show' : 'Hide'} ${button.dataset.passwordToggle} password`);
        });
    });

    update();
})();
