const finalPurchaseStatuses = new Set([
    'payment_paid',
    'payment_failed',
    'payment_cancelled',
    'payment_expired',
]);

function isTerminalPurchase(purchase) {
    return purchase.terminal === true || purchase.paid === true || finalPurchaseStatuses.has(purchase.status);
}

async function requestCartJson(url, { method = 'GET', body, csrfToken, signal } = {}) {
    const controller = new AbortController();
    const abort = () => controller.abort();
    const timeout = window.setTimeout(abort, 20000);
    signal?.addEventListener('abort', abort, { once: true });

    try {
        if (signal?.aborted) {
            controller.abort();
        }

        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(body ? { 'Content-Type': 'application/json' } : {}),
                ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            },
            ...(body ? { body: JSON.stringify(body) } : {}),
        });
        const payload = await response.json();

        if (!response.ok) {
            const error = new Error(Object.values(payload.errors ?? {}).flat().join(' ') || payload.message || '');
            error.status = response.status;
            error.payload = payload;
            throw error;
        }

        return payload;
    } finally {
        window.clearTimeout(timeout);
        signal?.removeEventListener('abort', abort);
    }
}

function safePaymentUrl(value) {
    if (typeof value !== 'string' || !value.trim()) {
        return null;
    }

    try {
        const url = new URL(value, window.location.origin);

        return url.origin === window.location.origin && ['http:', 'https:'].includes(url.protocol) ? url.href : null;
    } catch {
        return null;
    }
}

function createIdempotencyKey() {
    if (typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }

    const bytes = window.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function initCustomerCart(root = document) {
    const modal = root.querySelector('[data-customer-cart]');

    if (!modal || modal.dataset.customerCartReady === 'true') {
        return;
    }

    modal.dataset.customerCartReady = 'true';
    const find = (selector) => modal.querySelector(selector);
    const form = find('[data-customer-cart-form]');
    const editor = find('[data-customer-cart-editor]');
    const result = find('[data-customer-cart-result]');
    const planSelect = find('[data-customer-cart-plan]');
    const addButton = find('[data-customer-cart-add]');
    const lines = find('[data-customer-cart-lines]');
    const lineTemplate = root.querySelector('[data-customer-cart-line-template]');
    const issuedTemplate = root.querySelector('[data-customer-cart-issued-template]');
    const promoInput = find('[data-customer-cart-promo]');
    const locationSelect = find('[data-customer-cart-location]');
    const receipt = find('[data-customer-cart-receipt]');
    const providerSelect = find('[data-customer-cart-provider]');
    const checkoutButton = find('[data-customer-cart-checkout]');
    const quoteButton = find('[data-customer-cart-quote]');
    const feedback = find('[data-customer-cart-feedback]');
    const errorBlock = find('[data-customer-cart-error]');
    const csrfToken = form.querySelector('[name="_token"]').value;
    const setActionHidden = (selector, hidden) => {
        const action = find(selector);
        action.hidden = hidden;
        action.classList.toggle('hidden', hidden);
    };
    let opener = null;
    let quote = null;
    let revision = 0;
    let quoteController = null;
    let quoteTimer = null;
    let quoteBusy = false;
    let checkoutBusy = false;
    let uncertainCheckout = null;
    let purchase = null;
    let statusUrl = null;
    let pollTimer = null;
    let pollDeadline = 0;
    let statusBusy = false;
    let statusController = null;
    let statusRevision = 0;
    let purchaseSelectionPending = false;

    const rows = () => [...lines.querySelectorAll('[data-customer-cart-line]')];
    const currentMethod = () => find('[data-customer-cart-method]:checked')?.value ?? 'cash';
    const hasValidDraft = () => rows().length > 0
        && locationSelect.value !== ''
        && rows().every((row) => row.querySelector('[data-customer-cart-line-quantity]').checkValidity());
    const draft = () => ({
        items: rows().map((row) => ({
            class_pass_plan_id: Number(row.dataset.planId),
            quantity: Number(row.querySelector('[data-customer-cart-line-quantity]').value),
        })),
        location_id: Number(locationSelect.value),
        promo_code: promoInput.value.trim() || null,
    });
    const showError = (message = '') => {
        errorBlock.textContent = message;
        errorBlock.classList.toggle('hidden', !message);
    };
    const syncControls = () => {
        const locked = checkoutBusy || statusBusy || purchaseSelectionPending || uncertainCheckout !== null;
        editor.querySelectorAll('input, select, button').forEach((control) => {
            control.disabled = locked;
        });
        planSelect.disabled = locked || planSelect.options.length <= 1;
        addButton.disabled = locked || !planSelect.value;
        quoteButton.disabled = locked || !hasValidDraft() || quoteBusy;
        modal.querySelectorAll('[data-customer-cart-method][value="online"]').forEach((control) => {
            control.disabled = locked || providerSelect.options.length === 0;
        });

        const requiresPayment = quote?.requires_payment !== false;
        const online = currentMethod() === 'online';
        find('[data-customer-cart-payment-fields]').classList.toggle('hidden', !requiresPayment);
        find('[data-customer-cart-free-help]').classList.toggle('hidden', requiresPayment);
        find('[data-customer-cart-provider-fields]').classList.toggle('hidden', !online);
        find('[data-customer-cart-receipt-fields]').classList.toggle('hidden', online);
        find('[data-customer-cart-cash-receipt]').classList.toggle('hidden', currentMethod() !== 'cash');
        find('[data-customer-cart-transfer-receipt]').classList.toggle('hidden', currentMethod() !== 'card_transfer');
        const paymentReady = !requiresPayment || (online ? Boolean(providerSelect.value) : receipt.checked);
        checkoutButton.disabled = checkoutBusy || statusBusy || purchaseSelectionPending || quoteBusy || !quote || !paymentReady || purchase !== null;
        setActionHidden('[data-customer-cart-checkout]', purchase !== null || purchaseSelectionPending);
        root.querySelectorAll('[data-customer-cart-resume]').forEach((button) => {
            button.disabled = checkoutBusy || uncertainCheckout !== null;
        });
        form.setAttribute('aria-busy', checkoutBusy || statusBusy || quoteBusy ? 'true' : 'false');
        find('[data-customer-cart-empty]').classList.toggle('hidden', rows().length > 0);

        if (uncertainCheckout) {
            feedback.textContent = modal.dataset.checkoutUncertain;
        } else if (purchaseSelectionPending) {
            feedback.textContent = statusBusy ? modal.dataset.statusLoading : '';
        } else if (quote && !quoteBusy && !purchase) {
            feedback.textContent = requiresPayment && !online && !receipt.checked ? modal.dataset.receiptRequired : '';
        }
    };
    const clearQuote = () => {
        quote = null;
        find('[data-customer-cart-subtotal]').textContent = '—';
        find('[data-customer-cart-discount]').textContent = '—';
        find('[data-customer-cart-total]').textContent = '—';
        rows().forEach((row) => {
            row.querySelector('[data-customer-cart-line-total]').textContent = '—';
            row.querySelector('[data-customer-cart-line-free]').classList.add('hidden');
        });
    };
    const applyQuote = (payload) => {
        quote = payload;
        find('[data-customer-cart-subtotal]').textContent = payload.subtotal;
        find('[data-customer-cart-discount]').textContent = `−${payload.discount}`;
        find('[data-customer-cart-total]').textContent = payload.total;
        rows().forEach((row) => {
            const line = payload.lines.find((item) => String(item.class_pass_plan_id) === row.dataset.planId);

            if (!line) {
                return;
            }

            row.querySelector('[data-customer-cart-line-total]').textContent = line.total;
            const freeLabel = row.querySelector('[data-customer-cart-line-free]');
            freeLabel.textContent = modal.dataset.freeLabel.replace('__count__', String(line.free_quantity ?? 0));
            freeLabel.classList.toggle('hidden', !(line.free_quantity > 0));
        });
    };
    const requestQuote = async () => {
        window.clearTimeout(quoteTimer);
        quoteController?.abort();

        if (!hasValidDraft() || checkoutBusy || statusBusy || purchaseSelectionPending || uncertainCheckout || purchase) {
            quoteBusy = false;
            syncControls();
            return;
        }

        const requestedRevision = revision;
        quoteController = new AbortController();
        const requestedController = quoteController;
        quoteBusy = true;
        clearQuote();
        receipt.checked = false;
        feedback.textContent = modal.dataset.quoteLoading;
        showError();
        syncControls();

        try {
            const payload = await requestCartJson(modal.dataset.quoteUrl, {
                method: 'POST',
                body: draft(),
                csrfToken,
                signal: requestedController.signal,
            });

            if (requestedRevision === revision && quoteController === requestedController) {
                applyQuote(payload);
            }
        } catch (error) {
            if (requestedRevision === revision && quoteController === requestedController && !requestedController.signal.aborted) {
                showError((error.status ? error.message : '') || modal.dataset.requestFailed);
            }
        } finally {
            if (requestedRevision === revision && quoteController === requestedController) {
                quoteBusy = false;

                if (!quote) {
                    feedback.textContent = modal.dataset.quoteStale;
                }

                syncControls();
            }
        }
    };
    const draftChanged = () => {
        revision += 1;
        quoteController?.abort();
        window.clearTimeout(quoteTimer);
        quoteBusy = false;
        receipt.checked = false;
        clearQuote();
        showError();
        feedback.textContent = rows().length ? modal.dataset.quoteStale : modal.dataset.quoteEmpty;
        syncControls();
        quoteTimer = window.setTimeout(requestQuote, 350);
    };
    const stopPolling = () => {
        window.clearTimeout(pollTimer);
        pollTimer = null;
    };
    const open = (button) => {
        opener = button;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
        window.requestAnimationFrame(() => {
            const candidates = purchase || purchaseSelectionPending
                ? [...result.querySelectorAll('button:not([disabled]), a[href]')]
                : [planSelect];
            const target = candidates.find((element) => !element.disabled && element.getClientRects().length > 0)
                ?? find('[data-customer-cart-close]');
            target.focus();
        });
    };
    const close = () => {
        stopPolling();
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
        opener?.focus();
    };
    const renderPurchase = (payload, fallbackStatusUrl = null) => {
        purchase = payload;
        purchaseSelectionPending = false;
        uncertainCheckout = null;
        editor.classList.add('hidden');
        result.classList.remove('hidden');
        feedback.textContent = '';
        showError();
        const paid = payload.paid === true || payload.status === 'payment_paid';
        const terminal = isTerminalPurchase(payload);
        const message = find('[data-customer-cart-result-message]');
        message.textContent = payload.message || (paid ? modal.dataset.paidMessage : modal.dataset.pendingMessage);
        message.className = `rounded-xl border p-4 text-sm ${paid
            ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
            : terminal ? 'border-rose-200 bg-rose-50 text-rose-900' : 'border-amber-200 bg-amber-50 text-amber-900'}`;
        const url = safePaymentUrl(payload.payment?.url);
        const qrBlock = find('[data-customer-cart-qr-block]');
        qrBlock.classList.toggle('hidden', terminal || !url);
        const qr = find('[data-customer-cart-qr]');
        const qrDataUri = payload.payment?.qr_data_uri;
        const hasQr = typeof qrDataUri === 'string' && /^data:image\/(png|svg\+xml|webp);/.test(qrDataUri);
        qr.classList.toggle('hidden', !hasQr);

        if (hasQr && !terminal) {
            qr.src = qrDataUri;
        } else {
            qr.removeAttribute('src');
        }

        const paymentLink = find('[data-customer-cart-payment-link]');

        if (url) {
            paymentLink.href = url;
        } else {
            paymentLink.removeAttribute('href');
        }
        setActionHidden('[data-customer-cart-payment-link]', terminal || !url);

        const issuedItems = find('[data-customer-cart-issued-items]');
        issuedItems.replaceChildren();
        const items = paid ? (payload.items ?? []).filter((item) => item.code) : [];
        items.forEach((item) => {
            const fragment = issuedTemplate.content.cloneNode(true);
            fragment.querySelector('[data-customer-cart-issued-name]').textContent = item.plan_name;
            fragment.querySelector('[data-customer-cart-issued-code]').textContent = item.code;
            issuedItems.append(fragment);
        });
        find('[data-customer-cart-issued]').classList.toggle('hidden', items.length === 0);
        setActionHidden('[data-customer-cart-view-passes]', !paid);
        setActionHidden('[data-customer-cart-new]', !terminal);
        statusUrl = safePaymentUrl(payload.payment?.status_url || payload.status_url || fallbackStatusUrl || statusUrl);
        setActionHidden('[data-customer-cart-refresh]', !statusUrl || terminal);
        find('[data-customer-cart-poll-timeout]').classList.add('hidden');

        if (terminal) {
            stopPolling();
            root.querySelector(`[data-customer-cart-pending-purchase="${Number(payload.purchase_id)}"]`)?.remove();
        }

        syncControls();
    };
    const refreshPurchase = async (automatic = false) => {
        if (!statusUrl || statusBusy || modal.classList.contains('hidden')) {
            return false;
        }

        statusBusy = true;
        const requestedStatusUrl = statusUrl;
        const requestedRevision = statusRevision;
        const requestedController = new AbortController();
        statusController = requestedController;
        const isCurrentRequest = () => requestedRevision === statusRevision
            && requestedStatusUrl === statusUrl
            && requestedController === statusController;
        find('[data-customer-cart-refresh]').disabled = true;
        feedback.textContent = modal.dataset.statusLoading;
        if (purchaseSelectionPending) {
            const message = find('[data-customer-cart-result-message]');
            message.textContent = modal.dataset.statusLoading;
            message.className = 'rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900';
            showError();
        }
        syncControls();

        try {
            const payload = await requestCartJson(requestedStatusUrl, { signal: requestedController.signal });

            if (isCurrentRequest()) {
                renderPurchase(payload, requestedStatusUrl);
                return true;
            }
        } catch (error) {
            if (isCurrentRequest() && !requestedController.signal.aborted && !automatic) {
                showError((error.status ? error.message : '') || modal.dataset.requestFailed);
                if (purchaseSelectionPending) {
                    const message = find('[data-customer-cart-result-message]');
                    message.textContent = modal.dataset.requestFailed;
                    message.className = 'rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900';
                }
            }
        } finally {
            if (isCurrentRequest()) {
                statusBusy = false;
                find('[data-customer-cart-refresh]').disabled = false;
                if (!purchase && !quote && !purchaseSelectionPending) {
                    feedback.textContent = rows().length ? modal.dataset.quoteStale : modal.dataset.quoteEmpty;
                }
                syncControls();
            }
        }

        return false;
    };
    const poll = async () => {
        if (!purchase || isTerminalPurchase(purchase) || !statusUrl || modal.classList.contains('hidden')) {
            return;
        }

        if (Date.now() >= pollDeadline) {
            find('[data-customer-cart-poll-timeout]').classList.remove('hidden');
            return;
        }

        const requestedRevision = statusRevision;
        if (!document.hidden) {
            await refreshPurchase(true);
        }

        if (requestedRevision === statusRevision && purchase && !isTerminalPurchase(purchase) && !modal.classList.contains('hidden')) {
            pollTimer = window.setTimeout(poll, 2000);
        }
    };
    const startPolling = () => {
        stopPolling();
        pollDeadline = Date.now() + 60000;
        pollTimer = window.setTimeout(poll, 2000);
    };

    root.querySelectorAll('[data-customer-cart-open]').forEach((button) => {
        button.addEventListener('click', () => {
            open(button);

            if (purchase && !isTerminalPurchase(purchase)) {
                startPolling();
            }
        });
    });
    root.querySelectorAll('[data-customer-cart-resume]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (checkoutBusy || uncertainCheckout) {
                return;
            }

            stopPolling();
            revision += 1;
            quoteController?.abort();
            window.clearTimeout(quoteTimer);
            quoteBusy = false;
            statusRevision += 1;
            statusController?.abort();
            statusBusy = false;
            statusUrl = safePaymentUrl(button.dataset.statusUrl);
            purchase = null;
            purchaseSelectionPending = true;
            editor.classList.add('hidden');
            result.classList.remove('hidden');
            find('[data-customer-cart-qr-block]').classList.add('hidden');
            find('[data-customer-cart-qr]').removeAttribute('src');
            find('[data-customer-cart-payment-link]').removeAttribute('href');
            setActionHidden('[data-customer-cart-payment-link]', true);
            find('[data-customer-cart-issued]').classList.add('hidden');
            find('[data-customer-cart-issued-items]').replaceChildren();
            setActionHidden('[data-customer-cart-view-passes]', true);
            setActionHidden('[data-customer-cart-new]', true);
            find('[data-customer-cart-poll-timeout]').classList.add('hidden');
            setActionHidden('[data-customer-cart-refresh]', !statusUrl);
            const message = find('[data-customer-cart-result-message]');
            message.textContent = modal.dataset.statusLoading;
            message.className = 'rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900';
            showError();
            syncControls();
            open(button);
            const selectedRevision = statusRevision;
            if (await refreshPurchase() && selectedRevision === statusRevision) {
                startPolling();
            }
        });
    });
    modal.querySelectorAll('[data-customer-cart-close]').forEach((button) => button.addEventListener('click', close));
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            close();
        }
    });
    modal.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = [...modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), a[href]')]
            .filter((element) => element.getClientRects().length > 0);
        const first = focusable[0];
        const last = focusable.at(-1);

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first?.focus();
        }
    });
    planSelect.addEventListener('change', syncControls);
    addButton.addEventListener('click', () => {
        const option = planSelect.selectedOptions[0];

        if (!option?.value || checkoutBusy || uncertainCheckout) {
            return;
        }

        const existing = rows().find((row) => row.dataset.planId === option.value);

        if (existing) {
            const input = existing.querySelector('[data-customer-cart-line-quantity]');
            input.value = option.dataset.isTrial === 'true' ? '1' : String(Number(input.value || 0) + 1);
            input.focus();
        } else {
            const fragment = lineTemplate.content.cloneNode(true);
            const row = fragment.querySelector('[data-customer-cart-line]');
            row.dataset.planId = option.value;
            row.querySelector('[data-customer-cart-line-name]').textContent = option.dataset.name;
            row.querySelector('[data-customer-cart-line-price]').textContent = option.dataset.price;
            row.querySelector('[data-customer-cart-line-trial]').classList.toggle('hidden', option.dataset.isTrial !== 'true');

            if (option.dataset.isTrial === 'true') {
                row.querySelector('[data-customer-cart-line-quantity]').max = '1';
            }

            lines.append(fragment);
        }

        planSelect.value = '';
        draftChanged();
    });
    lines.addEventListener('click', (event) => {
        const button = event.target.closest('[data-customer-cart-line-remove]');

        if (button && !checkoutBusy && !uncertainCheckout) {
            button.closest('[data-customer-cart-line]').remove();
            draftChanged();
        }
    });
    lines.addEventListener('input', draftChanged);
    promoInput.addEventListener('input', draftChanged);
    locationSelect.addEventListener('change', draftChanged);
    quoteButton.addEventListener('click', requestQuote);
    receipt.addEventListener('change', syncControls);
    providerSelect.addEventListener('change', syncControls);
    modal.querySelectorAll('[data-customer-cart-method]').forEach((radio) => {
        radio.addEventListener('change', () => {
            receipt.checked = false;
            syncControls();
        });
    });
    find('[data-customer-cart-refresh]').addEventListener('click', async () => {
        if (await refreshPurchase()) {
            startPolling();
        }
    });
    find('[data-customer-cart-new]').addEventListener('click', () => {
        if (purchaseSelectionPending || statusBusy || checkoutBusy) {
            return;
        }

        stopPolling();
        statusRevision += 1;
        statusController?.abort();
        purchase = null;
        purchaseSelectionPending = false;
        statusUrl = null;
        lines.replaceChildren();
        promoInput.value = '';
        uncertainCheckout = null;
        result.classList.add('hidden');
        editor.classList.remove('hidden');
        draftChanged();
        planSelect.focus();
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (checkoutButton.disabled || !quote || checkoutBusy) {
            return;
        }

        const method = quote.requires_payment === false ? 'cash' : currentMethod();
        const payload = uncertainCheckout ?? {
            ...draft(),
            quote_hash: quote.quote_hash,
            payment_method: method,
            ...(method === 'online' ? { provider: providerSelect.value } : {}),
            payment_received: quote.requires_payment === false || receipt.checked,
            idempotency_key: createIdempotencyKey(),
        };
        checkoutBusy = true;
        showError();
        syncControls();

        try {
            renderPurchase(await requestCartJson(modal.dataset.checkoutUrl, {
                method: 'POST',
                body: payload,
                csrfToken,
            }));
            startPolling();
        } catch (error) {
            if (!error.status || error.status >= 500) {
                uncertainCheckout = payload;
                showError(modal.dataset.checkoutUncertain);
            } else {
                uncertainCheckout = null;
                showError(error.message || modal.dataset.requestFailed);

                if (error.status === 409 || error.payload?.errors?.quote_hash) {
                    clearQuote();
                    feedback.textContent = modal.dataset.quoteStale;
                }
            }
        } finally {
            checkoutBusy = false;
            syncControls();
        }
    });
    syncControls();
}

export function initCustomerCartPayment(root = document) {
    root.querySelectorAll('[data-customer-cart-payment-form]').forEach((form) => {
        const agreement = form.querySelector('[name="studio_rules_accepted"]');
        const action = form.querySelector('[data-customer-cart-payment-action]');
        const help = form.querySelector('[data-customer-cart-agreement-help]');
        const syncAgreement = () => {
            action.disabled = !agreement?.checked;
            help?.classList.toggle('hidden', Boolean(agreement?.checked));
        };
        agreement?.addEventListener('change', syncAgreement);
        syncAgreement();
    });
    root.querySelectorAll('[data-customer-cart-payment-poll]').forEach((container) => {
        if (container.dataset.cartPaymentPollReady === 'true') {
            return;
        }

        container.dataset.cartPaymentPollReady = 'true';
        const deadline = Date.now() + 60000;
        const poll = async () => {
            if (!container.isConnected || Date.now() >= deadline) {
                container.querySelector('[data-customer-cart-payment-timeout]')?.classList.remove('hidden');
                return;
            }

            if (!document.hidden) {
                try {
                    const purchase = await requestCartJson(container.dataset.statusUrl);

                    if (isTerminalPurchase(purchase)) {
                        window.location.reload();
                        return;
                    }
                } catch {}
            }

            window.setTimeout(poll, 2000);
        };
        window.setTimeout(poll, 2000);
    });
}
