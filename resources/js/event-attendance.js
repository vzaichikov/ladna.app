const attendanceBaseClasses = ['min-w-0', 'rounded-lg', 'border', 'px-2', 'py-1.5', 'shadow-xs'];
const attendancePassedClasses = ['border-emerald-200', 'bg-emerald-50', 'text-emerald-950'];
const attendanceWaitingClasses = ['border-rose-200', 'bg-rose-50', 'text-rose-950'];

export function initEventAttendance() {
    const root = document.querySelector('[data-event-attendance]');

    if (!root || root.dataset.attendanceReady === 'true') {
        return;
    }

    root.dataset.attendanceReady = 'true';
    const total = root.querySelector('[data-attendance-total]');
    const passed = root.querySelector('[data-attendance-passed]');
    const tickets = root.querySelector('[data-attendance-tickets]');
    const interval = Math.max(3000, Number(root.dataset.pollInterval) || 5000);
    let polling = false;

    const createTicket = (id) => {
        const ticket = document.createElement('div');
        const customer = document.createElement('div');
        const code = document.createElement('div');

        ticket.classList.add(...attendanceBaseClasses);
        ticket.dataset.attendanceTicket = String(id);
        customer.className = 'truncate text-xs font-semibold';
        customer.dataset.attendanceCustomer = '';
        code.className = 'truncate font-mono text-[10px] leading-tight opacity-75';
        code.dataset.attendanceCode = '';
        ticket.append(customer, code);

        return ticket;
    };

    const setTicketState = (ticket, isPassed) => {
        ticket.dataset.passed = isPassed ? 'true' : 'false';
        ticket.classList.remove(...attendancePassedClasses, ...attendanceWaitingClasses);
        ticket.classList.add(...(isPassed ? attendancePassedClasses : attendanceWaitingClasses));
    };

    const update = (payload) => {
        if (!tickets || !Array.isArray(payload.tickets)) {
            return;
        }

        total && (total.textContent = String(payload.total ?? 0));
        passed && (passed.textContent = String(payload.passed ?? 0));
        const existingTickets = new Map(
            [...tickets.querySelectorAll('[data-attendance-ticket]')]
                .map((ticket) => [ticket.dataset.attendanceTicket, ticket]),
        );
        const fragment = document.createDocumentFragment();

        payload.tickets.forEach((ticketData) => {
            const id = String(ticketData.id);
            const ticket = existingTickets.get(id) ?? createTicket(id);

            ticket.querySelector('[data-attendance-customer]').textContent = String(ticketData.customer_name ?? '');
            ticket.querySelector('[data-attendance-code]').textContent = String(ticketData.code ?? '');
            setTicketState(ticket, ticketData.passed === true);
            fragment.append(ticket);
        });

        tickets.replaceChildren(fragment);
    };

    const schedule = () => window.setTimeout(poll, interval);
    const poll = async () => {
        if (!root.isConnected) {
            return;
        }

        if (document.hidden || polling || !root.dataset.attendanceUrl) {
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

    schedule();
}
