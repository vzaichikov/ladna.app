const attendanceBaseClasses = ['min-w-0', 'rounded-xl', 'border', 'p-2.5', 'shadow-xs'];
const attendancePassedClasses = ['border-emerald-200', 'bg-emerald-50', 'text-emerald-950'];
const attendanceWaitingClasses = ['border-rose-200', 'bg-rose-50', 'text-rose-950'];

function createActionButton(isPassed, labels) {
    const button = document.createElement('button');

    button.type = 'button';
    button.className = isPassed
        ? 'flex min-h-9 w-full items-center justify-center gap-1.5 rounded-lg border border-emerald-300 bg-white/80 px-2 text-xs font-semibold text-emerald-800 transition hover:bg-white crm-focus'
        : 'flex min-h-9 w-full items-center justify-center gap-1.5 rounded-lg bg-rose-700 px-2 text-xs font-semibold text-white transition hover:bg-rose-800 crm-focus';
    button.textContent = isPassed ? labels.undo : labels.admit;

    return button;
}

export function initEventAttendance() {
    const root = document.querySelector('[data-event-attendance]');

    if (!root || root.dataset.attendanceReady === 'true') {
        return;
    }

    root.dataset.attendanceReady = 'true';
    const monitor = root.querySelector('[data-entrance-monitor]') || root;
    const total = root.querySelector('[data-attendance-total]');
    const passed = root.querySelector('[data-attendance-passed]');
    const waiting = root.querySelector('[data-attendance-waiting]');
    const cash = root.querySelector('[data-attendance-cash]');
    const updated = root.querySelector('[data-attendance-updated]');
    const tickets = root.querySelector('[data-attendance-tickets]');
    const interval = Math.max(3000, Number(root.dataset.pollInterval) || 5000);
    const labels = {
        admit: monitor.dataset.admitLabel || 'Admit',
        undo: monitor.dataset.undoLabel || 'Undo',
    };
    let polling = false;
    let pollTimer = null;

    const createTicket = (id) => {
        const ticket = document.createElement('article');
        const body = document.createElement('div');
        const customer = document.createElement('div');
        const type = document.createElement('div');
        const code = document.createElement('div');
        const action = document.createElement('div');

        ticket.classList.add(...attendanceBaseClasses);
        ticket.dataset.attendanceTicket = String(id);
        customer.className = 'truncate text-sm font-semibold';
        customer.dataset.attendanceCustomer = '';
        type.className = 'mt-0.5 truncate text-[11px] opacity-75';
        type.dataset.attendanceType = '';
        code.className = 'truncate font-mono text-[10px] leading-tight opacity-70';
        code.dataset.attendanceCode = '';
        action.className = 'mt-2';
        action.dataset.attendanceAction = '';
        body.append(customer, type, code);
        ticket.append(body, action);

        return ticket;
    };

    const setTicketState = (ticket, ticketData) => {
        const isPassed = ticketData.passed === true || ticketData.is_checked_in === true;
        const action = ticket.querySelector('[data-attendance-action]');
        const button = createActionButton(isPassed, labels);
        const customerName = String(ticketData.customer_name ?? ticketData.customer ?? '');
        const code = String(ticketData.code ?? '');

        ticket.dataset.passed = isPassed ? 'true' : 'false';
        ticket.classList.remove(...attendancePassedClasses, ...attendanceWaitingClasses);
        ticket.classList.add(...(isPassed ? attendancePassedClasses : attendanceWaitingClasses));

        if (isPassed) {
            button.dataset.entranceUndo = ticketData.undo_url || '';
            button.dataset.customer = customerName;
            button.dataset.code = code;
            button.disabled = !ticketData.undo_url;
        } else {
            button.dataset.doorCheckin = '';
            button.dataset.ticketCode = code;
            button.dataset.scanSource = 'monitor';
        }

        action?.replaceChildren(button);
    };

    const cashLabel = (payload) => {
        if (payload.cash_label) {
            return String(payload.cash_label);
        }

        if (payload.cash?.formatted) {
            return String(payload.cash.formatted);
        }

        const balances = payload.cash_balances || payload.cash || [];

        return Array.isArray(balances)
            ? balances.map((balance) => balance.label || balance.amount_label).filter(Boolean).join(' · ')
            : '';
    };

    const update = (payload) => {
        if (!tickets || !Array.isArray(payload.tickets)) {
            return;
        }

        total && (total.textContent = String(payload.total ?? 0));
        passed && (passed.textContent = String(payload.passed ?? 0));
        waiting && (waiting.textContent = String(payload.waiting ?? payload.unpassed ?? Math.max(0, Number(payload.total ?? 0) - Number(payload.passed ?? 0))));
        cash && (cash.textContent = cashLabel(payload) || '—');
        updated && (updated.textContent = payload.updated_at_label || root.dataset.liveLabel || updated.textContent);
        const existingTickets = new Map(
            [...tickets.querySelectorAll('[data-attendance-ticket]')]
                .map((ticket) => [ticket.dataset.attendanceTicket, ticket]),
        );
        const fragment = document.createDocumentFragment();

        payload.tickets.forEach((ticketData) => {
            const id = String(ticketData.id);
            const ticket = existingTickets.get(id) ?? createTicket(id);

            ticket.querySelector('[data-attendance-customer]').textContent = String(ticketData.customer_name ?? ticketData.customer ?? '');
            ticket.querySelector('[data-attendance-type]').textContent = String(ticketData.type ?? ticketData.ticket_type ?? '');
            ticket.querySelector('[data-attendance-code]').textContent = String(ticketData.code ?? '');
            setTicketState(ticket, ticketData);
            fragment.append(ticket);
        });

        tickets.replaceChildren(fragment);
    };

    const schedule = (delay = interval) => {
        window.clearTimeout(pollTimer);
        pollTimer = window.setTimeout(poll, delay);
    };
    const poll = async () => {
        if (!root.isConnected) {
            return;
        }

        if (document.hidden || polling || root.dataset.entranceBusy === 'true' || !root.dataset.attendanceUrl) {
            schedule();
            return;
        }

        polling = true;

        try {
            const response = await fetch(root.dataset.attendanceUrl, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (response.ok) {
                update(await response.json());
            }
        } catch {
        } finally {
            polling = false;
            schedule();
        }
    };

    root.addEventListener('entrance:busy', (event) => {
        root.dataset.entranceBusy = event.detail?.busy ? 'true' : 'false';
    });
    root.addEventListener('entrance:changed', () => schedule(150));
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            schedule(100);
        }
    });
    schedule();
}
