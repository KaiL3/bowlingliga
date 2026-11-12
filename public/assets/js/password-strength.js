// Strength display of a new password (R23.16): asks the server for the bits
// while typing, so the display shows the value the server enforces. The bar
// fills up to 128 bits, red below the minimum of the policy, green from it on.
(() => {
    'use strict';

    const endpoint = '/admin/password-strength';

    document.querySelectorAll('[data-strength-for]').forEach((meter) => {
        // The script tag follows every meter; each meter is set up once.
        if (meter.dataset.ready === '1') {
            return;
        }
        meter.dataset.ready = '1';

        const input = document.getElementById(meter.dataset.strengthFor);
        if (!input || !input.form) {
            return;
        }

        const token = input.form.querySelector('input[name="csrf_token"]');
        const user = meter.dataset.userField ? document.getElementById(meter.dataset.userField) : null;
        const fill = meter.querySelector('.strength__fill');
        const text = meter.querySelector('.strength__text');
        const min = meter.dataset.min !== undefined ? Number(meter.dataset.min) : null;
        let timer = null;
        let request = 0;

        const show = (bits) => {
            meter.hidden = false;
            fill.style.width = Math.min(100, (bits / 128) * 100) + '%';
            meter.classList.toggle('strength--weak', min !== null && bits < min);
            meter.classList.toggle('strength--good', min === null || bits >= min);
            text.textContent = meter.dataset.text.replace('{bits}', String(bits));
        };

        const measure = () => {
            const password = input.value;
            const current = ++request;
            if (password === '') {
                meter.hidden = true;
                return;
            }

            const body = new URLSearchParams({
                password,
                username: user ? user.value : '',
                csrf_token: token ? token.value : '',
            });
            fetch(endpoint, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : null))
                .then((data) => {
                    // Only the answer to the latest input counts.
                    if (data && current === request && typeof data.bits === 'number') {
                        show(data.bits);
                    }
                })
                .catch(() => {});
        };

        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(measure, 250);
        });
    });
})();
