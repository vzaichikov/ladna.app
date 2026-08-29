function normalizeCollection(payload, keys) {
    for (const key of keys) {
        if (Array.isArray(payload?.[key])) {
            return payload[key];
        }
    }

    return Array.isArray(payload) ? payload : [];
}

async function jsonRequest(url, { method = 'GET', csrfToken = '', body = null, signal = null } = {}) {
    const response = await fetch(url, {
        method,
        signal,
        cache: 'no-store',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const contentType = response.headers.get('content-type') || '';
    const payload = contentType.includes('application/json') ? await response.json() : {};

    if (!response.ok) {
        const validationMessage = payload?.errors
            ? Object.values(payload.errors).flat().find(Boolean)
            : null;
        const error = new Error(validationMessage || payload?.message || `HTTP ${response.status}`);

        error.payload = payload;
        throw error;
    }

    return payload;
}

function idempotencyKey() {
    if (globalThis.crypto?.randomUUID) {
        return globalThis.crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
        const random = Math.floor(Math.random() * 16);
        const value = character === 'x' ? random : (random & 0x3) | 0x8;

        return value.toString(16);
    });
}

function setBusy(root, busy) {
    root.dataset.entranceBusy = busy ? 'true' : 'false';
    root.dispatchEvent(new CustomEvent('entrance:busy', { bubbles: true, detail: { busy } }));
}

function contactLabel(guest) {
    return guest.contact || [guest.email, guest.phone].filter(Boolean).join(' · ');
}

export function initEntranceOperations() {
    document.querySelectorAll('[data-entrance-tools]').forEach((root) => {
        if (root.dataset.entranceToolsReady === 'true') {
            return;
        }

        root.dataset.entranceToolsReady = 'true';
        const scannerRoot = root.closest('[data-event-scanner]') || document.querySelector('[data-event-scanner]');
        const interactionRoot = scannerRoot || root;
        const csrfToken = root.dataset.csrfToken || scannerRoot?.dataset.csrfToken || '';
        const searchToggle = root.querySelector('[data-entrance-search-toggle]');
        const searchPanel = root.querySelector('[data-entrance-search-panel]');
        const searchInput = root.querySelector('[data-entrance-search-input]');
        const searchStatus = root.querySelector('[data-entrance-search-status]');
        const searchResults = root.querySelector('[data-entrance-search-results]');
        const saleModal = root.querySelector('[data-entrance-sale-modal]');
        const salePanel = saleModal?.querySelector('[data-entrance-sale-panel]');
        const saleForm = saleModal?.querySelector('[data-entrance-sale-form]');
        const saleTitle = saleModal?.querySelector('[data-entrance-sale-title]');
        const saleSubmitLabel = saleModal?.querySelector('[data-entrance-sale-submit-label]');
        const saleProvider = saleModal?.querySelector('[data-entrance-sale-provider]');
        const saleProviderField = saleProvider?.closest('[data-entrance-sale-provider-field]');
        const saleError = saleModal?.querySelector('[data-entrance-sale-error]');
        const saleResult = saleModal?.querySelector('[data-entrance-sale-result]');
        const saleResultMessage = saleModal?.querySelector('[data-entrance-sale-result-message]');
        const salePayment = saleModal?.querySelector('[data-entrance-sale-payment]');
        const salePaymentQr = saleModal?.querySelector('[data-entrance-sale-payment-qr]');
        const salePaymentLink = saleModal?.querySelector('[data-entrance-sale-payment-link]');
        const saleAdmit = saleModal?.querySelector('[data-entrance-sale-admit]');
        const undoModal = root.querySelector('[data-entrance-undo-modal]');
        const undoForm = undoModal?.querySelector('[data-entrance-undo-form]');
        const undoTicket = undoModal?.querySelector('[data-entrance-undo-ticket]');
        const undoError = undoModal?.querySelector('[data-entrance-undo-error]');
        let searchAbort = null;
        let searchTimer = null;
        let activeSaleMode = null;
        let activeSaleUrl = null;
        let activeUndoUrl = null;
        let modalOpener = null;
        let paymentPollTimer = null;
        let paymentPollStartedAt = 0;

        const setSearchOpen = (open) => {
            searchPanel?.classList.toggle('hidden', !open);
            searchToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
            root.dataset.entranceSearchOpen = open ? 'true' : 'false';
            setBusy(root, open);

            if (open) {
                requestAnimationFrame(() => searchInput?.focus());
            }
        };

        const closeModal = (modal) => {
            if (!modal) {
                return;
            }

            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.classList.remove('overflow-hidden');
            setBusy(root, searchPanel ? !searchPanel.classList.contains('hidden') : false);
            modalOpener?.focus();
            modalOpener = null;
        };

        const openModal = (modal, opener, focusTarget = null) => {
            if (!modal) {
                return;
            }

            modalOpener = opener instanceof HTMLElement ? opener : document.activeElement;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.classList.add('overflow-hidden');
            setBusy(root, true);
            requestAnimationFrame(() => focusTarget?.focus());
        };

        const dispatchScan = (ticket, opener, source = 'guest_search') => {
            const code = String(ticket?.code ?? ticket?.ticket_code ?? '').trim();

            if (!code || !scannerRoot) {
                return;
            }

            if (saleModal && !saleModal.classList.contains('hidden')) {
                closeModal(saleModal);
            }

            setSearchOpen(false);
            scannerRoot.dispatchEvent(new CustomEvent('entrance:scan', {
                detail: { code, source, opener },
            }));
        };

        const makeTicketButton = (ticket) => {
            const button = document.createElement('button');
            const body = document.createElement('span');
            const name = document.createElement('strong');
            const code = document.createElement('span');
            const action = document.createElement('span');
            const passed = ticket.passed === true || ticket.is_checked_in === true;
            const blocked = ticket.can_admit === false || ticket.can_check_in === false || ticket.admissible === false || ticket.status === 'voided' || ticket.status === 'refunded';

            button.type = 'button';
            button.className = 'flex w-full items-center justify-between gap-3 rounded-xl border border-stone-200 bg-white px-4 py-3 text-left transition hover:border-brand-200 hover:bg-brand-50 disabled:pointer-events-none disabled:opacity-55 crm-focus';
            body.className = 'min-w-0';
            name.className = 'block truncate text-sm font-semibold text-slate-950';
            name.textContent = [ticket.kind_label, ticket.type || ticket.ticket_type]
                .filter((value, index, values) => value && values.indexOf(value) === index)
                .join(' · ') || root.dataset.ticketFallback;
            code.className = 'mt-1 block truncate font-mono text-xs text-slate-500';
            code.textContent = ticket.code || ticket.ticket_code || '';
            action.className = `shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${passed ? 'bg-emerald-100 text-emerald-800' : blocked ? 'bg-stone-100 text-stone-600' : 'bg-brand-100 text-brand-800'}`;
            action.textContent = passed ? root.dataset.alreadyPassedLabel : blocked ? (ticket.status_label || root.dataset.unavailableLabel) : root.dataset.admitLabel;
            body.append(name, code);
            button.append(body, action);
            button.disabled = passed || blocked;
            button.addEventListener('click', () => dispatchScan(ticket, button));

            return button;
        };

        const loadGuestTickets = async (guest, container, button) => {
            let tickets = Array.isArray(guest.tickets) ? guest.tickets : [];

            if (tickets.length === 0 && (guest.tickets_url || guest.url)) {
                button.disabled = true;

                try {
                    const payload = await jsonRequest(guest.tickets_url || guest.url);

                    tickets = normalizeCollection(payload, ['tickets', 'data']);
                } catch (error) {
                    container.textContent = error.message || root.dataset.requestError;
                    container.className = 'mt-3 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-800';
                    return;
                } finally {
                    button.disabled = false;
                }
            }

            container.replaceChildren();
            container.className = 'mt-3 grid gap-2';

            if (tickets.length === 0) {
                const empty = document.createElement('p');

                empty.className = 'rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-600';
                empty.textContent = root.dataset.noTicketsLabel;
                container.append(empty);
                return;
            }

            tickets.forEach((ticket) => container.append(makeTicketButton(ticket)));
        };

        const renderSearchResults = (payload) => {
            if (!searchResults) {
                return;
            }

            const guests = normalizeCollection(payload, ['guests', 'results', 'orders', 'data']);
            const fragment = document.createDocumentFragment();

            searchResults.replaceChildren();

            if (guests.length === 0) {
                const empty = document.createElement('p');

                empty.className = 'rounded-xl border border-dashed border-stone-300 px-4 py-5 text-center text-sm text-slate-500';
                empty.textContent = root.dataset.noGuestsLabel;
                searchResults.append(empty);
                return;
            }

            guests.forEach((result, index) => {
                const guest = result.person || result.guest || result;
                const article = document.createElement('article');
                const button = document.createElement('button');
                const body = document.createElement('span');
                const name = document.createElement('strong');
                const contact = document.createElement('span');
                const count = document.createElement('span');
                const tickets = document.createElement('div');
                const guestTickets = Array.isArray(result.credentials)
                    ? result.credentials
                    : (Array.isArray(result.tickets) ? result.tickets : (Array.isArray(guest.tickets) ? guest.tickets : []));

                article.className = 'rounded-xl border border-stone-200 bg-white p-2';
                button.type = 'button';
                button.className = 'flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2 text-left transition hover:bg-slate-50 crm-focus';
                button.setAttribute('aria-expanded', 'false');
                body.className = 'min-w-0';
                name.className = 'block truncate font-semibold text-slate-950';
                name.textContent = guest.name || guest.customer_name || guest.buyer_name || root.dataset.guestFallback;
                contact.className = 'mt-0.5 block truncate text-xs text-slate-500';
                contact.textContent = [contactLabel(guest), result.reference].filter(Boolean).join(' · ') || '';
                count.className = 'shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700';
                count.textContent = guest.ticket_count_label || String(guest.ticket_count ?? guestTickets.length);
                tickets.id = `entrance-guest-tickets-${result.order_id ?? guest.id ?? index}`;
                tickets.hidden = true;
                body.append(name, contact);
                button.append(body, count);
                button.setAttribute('aria-controls', tickets.id);
                button.addEventListener('click', async () => {
                    const open = tickets.hidden;

                    tickets.hidden = !open;
                    button.setAttribute('aria-expanded', open ? 'true' : 'false');

                    if (open && tickets.childElementCount === 0) {
                        await loadGuestTickets({ ...result, tickets: guestTickets }, tickets, button);
                    }
                });
                article.append(button, tickets);
                fragment.append(article);
            });

            searchResults.append(fragment);
        };

        const runSearch = async () => {
            const query = searchInput?.value.trim() || '';

            searchAbort?.abort();

            if (query.length < 2 || !root.dataset.searchUrl) {
                searchResults?.replaceChildren();
                if (searchStatus) {
                    searchStatus.textContent = query ? root.dataset.searchMinimumLabel : root.dataset.searchHint;
                }
                return;
            }

            searchAbort = new AbortController();
            if (searchStatus) {
                searchStatus.textContent = root.dataset.searchingLabel;
            }

            try {
                const url = new URL(root.dataset.searchUrl, window.location.origin);

                url.searchParams.set('q', query);
                const payload = await jsonRequest(url, { signal: searchAbort.signal });

                renderSearchResults(payload);
                if (searchStatus) {
                    searchStatus.textContent = '';
                }
            } catch (error) {
                if (error.name !== 'AbortError' && searchStatus) {
                    searchStatus.textContent = error.message || root.dataset.requestError;
                }
            }
        };

        const resetSale = () => {
            window.clearTimeout(paymentPollTimer);
            paymentPollTimer = null;
            saleForm?.reset();
            saleForm?.classList.remove('hidden');
            saleError?.classList.add('hidden');
            saleResult?.classList.add('hidden');
            salePayment?.classList.add('hidden');
            saleAdmit?.classList.add('hidden');
            saleAdmit?.removeAttribute('data-ticket-code');
        };

        const setSaleMode = (mode, button) => {
            activeSaleMode = mode;
            activeSaleUrl = mode === 'cash' ? root.dataset.cashSaleUrl : root.dataset.cardSaleUrl;
            resetSale();

            if (saleTitle) {
                saleTitle.textContent = mode === 'cash' ? root.dataset.cashTitle : root.dataset.cardTitle;
            }
            if (saleSubmitLabel) {
                saleSubmitLabel.textContent = mode === 'cash' ? root.dataset.cashSubmitLabel : root.dataset.cardSubmitLabel;
            }
            saleProviderField?.classList.toggle('hidden', mode !== 'card');
            if (saleProvider) {
                saleProvider.required = mode === 'card';
            }

            openModal(saleModal, button, saleForm?.querySelector('[name="guest_name"]'));
        };

        const markPaymentPaid = (payload) => {
            const ticket = payload.ticket || payload.tickets?.[0];

            if (saleResultMessage) {
                saleResultMessage.textContent = payload.message || root.dataset.paymentConfirmedLabel;
            }
            if (ticket?.code && saleAdmit) {
                saleAdmit.dataset.ticketCode = ticket.code;
                saleAdmit.classList.remove('hidden');
            }
            salePayment?.classList.add('hidden');
        };

        const pollPayment = async (statusUrl) => {
            if (!statusUrl || Date.now() - paymentPollStartedAt > 300000 || !saleModal || saleModal.classList.contains('hidden')) {
                return;
            }

            try {
                const payload = await jsonRequest(statusUrl);

                if (payload.paid === true || ['paid', 'completed', 'issued'].includes(payload.state || payload.status)) {
                    markPaymentPaid(payload);
                    root.dispatchEvent(new CustomEvent('entrance:changed', { bubbles: true, detail: { payload } }));
                    return;
                }
            } catch {
            }

            paymentPollTimer = window.setTimeout(() => pollPayment(statusUrl), 2500);
        };

        searchToggle?.addEventListener('click', () => setSearchOpen(searchPanel?.classList.contains('hidden') ?? true));
        root.querySelector('[data-entrance-search-close]')?.addEventListener('click', () => setSearchOpen(false));
        searchInput?.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(runSearch, 250);
        });
        root.querySelectorAll('[data-entrance-sale-open]').forEach((button) => {
            button.addEventListener('click', () => setSaleMode(button.dataset.entranceSaleOpen, button));
        });
        root.querySelectorAll('[data-entrance-modal-dismiss]').forEach((button) => {
            button.addEventListener('click', () => closeModal(button.closest('[data-entrance-sale-modal], [data-entrance-undo-modal]')));
        });
        [saleModal, undoModal].forEach((modal) => {
            modal?.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModal(modal);
                }
            });
            modal?.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeModal(modal);
                }
            });
        });
        saleForm?.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (!activeSaleUrl) {
                return;
            }

            const submit = saleForm.querySelector('[type="submit"]');
            const data = Object.fromEntries(new FormData(saleForm));

            data.mode = activeSaleMode;
            data.idempotency_key = idempotencyKey();
            submit && (submit.disabled = true);
            saleError?.classList.add('hidden');

            try {
                const payload = await jsonRequest(activeSaleUrl, { method: 'POST', csrfToken, body: data });
                const ticket = payload.ticket || payload.tickets?.[0];

                saleForm.classList.add('hidden');
                saleResult?.classList.remove('hidden');
                if (saleResultMessage) {
                    saleResultMessage.textContent = payload.message || (activeSaleMode === 'cash' ? root.dataset.cashSuccessLabel : root.dataset.cardReadyLabel);
                }

                if (ticket?.code && activeSaleMode === 'cash' && saleAdmit) {
                    saleAdmit.dataset.ticketCode = ticket.code;
                    saleAdmit.classList.remove('hidden');
                }

                const paymentUrl = payload.payment?.url || payload.payment_url || payload.checkout_url;
                const paymentQr = payload.payment?.qr_data_uri || payload.payment_qr || payload.qr_code || payload.qr_data_uri;

                if (paymentUrl || paymentQr) {
                    salePayment?.classList.remove('hidden');
                    if (salePaymentQr) {
                        salePaymentQr.src = paymentQr || '';
                        salePaymentQr.classList.toggle('hidden', !paymentQr);
                    }
                    if (salePaymentLink) {
                        salePaymentLink.href = paymentUrl || '#';
                        salePaymentLink.classList.toggle('hidden', !paymentUrl);
                    }
                }

                const statusUrl = payload.payment?.status_url || payload.status_url;

                if (ticket?.code && !paymentUrl && !statusUrl && saleAdmit) {
                    saleAdmit.dataset.ticketCode = ticket.code;
                    saleAdmit.classList.remove('hidden');
                }

                if (statusUrl) {
                    paymentPollStartedAt = Date.now();
                    pollPayment(statusUrl);
                }

                root.dispatchEvent(new CustomEvent('entrance:changed', { bubbles: true, detail: { payload } }));
            } catch (error) {
                if (saleError) {
                    saleError.textContent = error.message || root.dataset.requestError;
                    saleError.classList.remove('hidden');
                }
            } finally {
                submit && (submit.disabled = false);
            }
        });
        saleAdmit?.addEventListener('click', () => dispatchScan({ code: saleAdmit.dataset.ticketCode }, saleAdmit, 'entrance_sale'));

        interactionRoot.addEventListener('click', (event) => {
            const button = event.target.closest('[data-entrance-undo]');

            if (!button || !interactionRoot.contains(button)) {
                return;
            }

            activeUndoUrl = button.dataset.entranceUndo;
            undoForm?.reset();
            undoError?.classList.add('hidden');
            if (undoTicket) {
                undoTicket.textContent = [button.dataset.customer, button.dataset.code].filter(Boolean).join(' · ');
            }
            openModal(undoModal, button, undoForm?.querySelector('[name="reason"]'));
        });
        undoForm?.addEventListener('submit', async (event) => {
            event.preventDefault();

            const submit = undoForm.querySelector('[type="submit"]');
            const reason = new FormData(undoForm).get('reason');

            submit && (submit.disabled = true);
            undoError?.classList.add('hidden');

            try {
                const payload = await jsonRequest(activeUndoUrl, { method: 'POST', csrfToken, body: { reason } });

                closeModal(undoModal);
                root.dispatchEvent(new CustomEvent('entrance:changed', { bubbles: true, detail: { payload } }));
            } catch (error) {
                if (undoError) {
                    undoError.textContent = error.message || root.dataset.requestError;
                    undoError.classList.remove('hidden');
                }
            } finally {
                submit && (submit.disabled = false);
            }
        });
    });
}
