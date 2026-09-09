export function initFestivalPaymentRecovery() {
    let pollingUntil = Date.now() + 60000;
    let polling = false;
    let checking = false;
    let paymentStateVersion = 0;

    const replacePayment = (html) => {
        if (!html) return;
        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const replacement = template.content.querySelector('[data-festival-payment-fragment]');
        const current = document.querySelector('[data-festival-payment-fragment]');
        if (replacement && current) current.replaceWith(replacement);
    };

    const expireResumeLinks = () => {
        document.querySelectorAll('[data-festival-payment-expiry]').forEach((label) => {
            if (Date.parse(label.dataset.festivalPaymentExpiry) <= Date.now()) {
                label.textContent = label.dataset.expiredMessage;
                label.closest('[data-festival-charge-card]')?.querySelector('[data-festival-payment-resume]')?.remove();
            }
        });
    };

    const poll = async () => {
        expireResumeLinks();
        if (polling || checking || document.hidden || Date.now() > pollingUntil) return;
        polling = true;
        const requestStateVersion = paymentStateVersion;
        try {
            for (const card of document.querySelectorAll('[data-festival-payment-status-url]')) {
                const response = await fetch(card.dataset.festivalPaymentStatusUrl, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: AbortSignal.timeout(10000),
                });
                if (!response.ok) continue;
                const result = await response.json();
                if (requestStateVersion !== paymentStateVersion) break;
                if (result.state !== card.dataset.paymentState) {
                    replacePayment(result.payment_html);
                    break;
                }
            }
        } catch {
            // The manual check remains available when polling cannot reach Ladna.
        } finally {
            polling = false;
        }
    };

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-festival-payment-check]');
        if (!form) return;
        event.preventDefault();
        const cardId = form.closest('[data-festival-charge-card]').id;
        const button = form.querySelector('button[type="submit"]');
        if (button.disabled || checking) return;
        checking = true;
        paymentStateVersion += 1;
        button.disabled = true;
        let message = form.dataset.errorMessage;
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new FormData(form),
                credentials: 'same-origin',
                signal: AbortSignal.timeout(40000),
            });
            const result = await response.json();
            if (response.ok) {
                replacePayment(result.payment_html);
                message = result.message;
                pollingUntil = Date.now() + 60000;
            } else {
                message = Object.values(result.errors || {}).flat()[0] || message;
            }
        } catch {
            // Preserve the current payment and show a retryable connection error.
        } finally {
            checking = false;
            button.disabled = false;
            const feedback = document.getElementById(cardId)?.querySelector('[data-festival-payment-feedback]');
            if (feedback && message) {
                feedback.textContent = message;
                feedback.classList.remove('hidden');
            }
        }
    });

    if (document.querySelector('[data-festival-payment-fragment]')) {
        window.setInterval(poll, 5000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) poll();
        });
        expireResumeLinks();
    }
}
