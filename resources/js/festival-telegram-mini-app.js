const readJson = (root, selector) => {
    try {
        return JSON.parse(root.querySelector(selector)?.textContent || '{}');
    } catch {
        return {};
    }
};

const element = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    node.className = className;
    node.textContent = text;
    return node;
};

export function initFestivalTelegramMiniApp() {
    const root = document.querySelector('[data-festival-telegram-mini-app]');
    if (!root) return;

    const telegram = window.Telegram?.WebApp;
    const labels = readJson(root, '[data-festival-telegram-labels]');
    let state = readJson(root, '[data-festival-telegram-initial]');
    let initData = telegram?.initData || '';
    let contactPoll = null;
    let timelinePoll = null;
    let automaticActionOpened = false;

    telegram?.ready();
    telegram?.expand();
    telegram?.setHeaderColor?.('#020617');
    telegram?.setBackgroundColor?.('#020617');

    const errorBox = root.querySelector('[data-festival-telegram-error]');
    const showError = (message) => {
        errorBox.textContent = message || labels.generic_error;
        errorBox.classList.remove('hidden');
    };
    const clearError = () => errorBox.classList.add('hidden');

    const request = async (url, method = 'POST', extra = {}) => {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': root.dataset.csrfToken,
            },
            body: JSON.stringify({ init_data: initData, ...extra }),
        });
        if (!response.ok) throw new Error('festival_telegram_request_failed');
        return response.json();
    };

    const openUrl = (url) => {
        if (telegram?.openLink) telegram.openLink(url);
        else window.location.assign(url);
    };

    const actionButton = (text, action, targetId, secondary = false) => {
        const button = element('button', secondary
            ? 'inline-flex min-h-10 items-center justify-center rounded-xl border border-white/15 bg-white/[0.06] px-3 py-2 text-sm font-semibold text-slate-100'
            : 'festival-telegram-accent-button inline-flex min-h-10 items-center justify-center rounded-xl px-3 py-2 text-sm font-semibold text-white', text);
        button.type = 'button';
        button.addEventListener('click', async () => {
            clearError();
            try {
                const result = await request(root.dataset.actionUrl, 'POST', { action, target_id: targetId });
                if (result.url) openUrl(result.url);
            } catch {
                showError();
            }
        });
        return button;
    };

    const linkButton = (text, url) => {
        const button = element('button', 'inline-flex min-h-10 items-center justify-center rounded-xl border border-white/15 bg-white/[0.06] px-3 py-2 text-sm font-semibold text-slate-100', text);
        button.type = 'button';
        button.addEventListener('click', () => openUrl(url));
        return button;
    };

    const emptyCard = () => element('div', 'rounded-2xl border border-white/10 bg-white/[0.05] p-5 text-sm text-slate-300', labels.no_items);

    const renderEditions = () => {
        const container = root.querySelector('[data-festival-telegram-editions]');
        container.replaceChildren();
        (state.editions || []).forEach((edition) => {
            const card = element('article', 'rounded-2xl border border-white/10 bg-white/[0.06] p-5 shadow-xl shadow-black/10');
            const top = element('div', 'flex items-start justify-between gap-3');
            const heading = element('div');
            heading.append(element('div', 'festival-telegram-accent-text text-xs font-semibold uppercase tracking-wider', labels[edition.period] || edition.period));
            heading.append(element('h2', 'mt-1 text-xl font-semibold', edition.title));
            if (edition.starts_at) heading.append(element('p', 'mt-2 text-sm text-slate-300', new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(edition.starts_at))));
            top.append(heading);
            card.append(top);
            if (edition.summary) card.append(element('p', 'mt-3 text-sm leading-6 text-slate-300', edition.summary));
            if (edition.venue_name || edition.venue_address) card.append(element('p', 'mt-3 text-sm font-medium text-slate-200', [edition.venue_name, edition.venue_address].filter(Boolean).join(' · ')));

            const actions = element('div', 'mt-4 flex flex-wrap gap-2');
            actions.append(linkButton(labels.open_ladna, edition.public_url));
            if (state.authorized && state.registrant && edition.registration_open) actions.append(actionButton(labels.register, 'create_entry', edition.id));
            if (state.authorized) actions.append(actionButton(labels.tickets, 'ticket_checkout', edition.id, true));
            card.append(actions);

            [['timeline', edition.timeline], ['schedule', edition.schedule], ['results', edition.results], ['documents', edition.documents]].forEach(([name, items]) => {
                if (!items?.length) return;
                const details = element('details', 'mt-4 rounded-xl border border-white/10 bg-black/10 p-3');
                details.append(element('summary', 'cursor-pointer text-sm font-semibold text-slate-100', `${labels[name]} · ${items.length}`));
                const list = element('div', 'mt-3 space-y-2');
                if (name === 'timeline') {
                    items.forEach((scene) => {
                        list.append(element('div', 'rounded-lg bg-fuchsia-500/10 px-3 py-2 text-sm', `${scene.scene_name}: ${scene.items?.find((item) => item.status === 'active')?.label || scene.next_label || '—'}`));
                    });
                } else if (name === 'documents') {
                    items.forEach((item) => list.append(linkButton(item.title, item.url)));
                } else {
                    items.slice(0, 12).forEach((item) => list.append(element('div', 'rounded-lg bg-white/[0.04] px-3 py-2 text-sm text-slate-200', item.name || [item.rank, item.entry_name, item.category].filter(Boolean).join(' · '))));
                }
                details.append(list);
                card.append(details);
            });
            container.append(card);
        });
        if (!container.children.length) container.append(emptyCard());
    };

    const renderMine = () => {
        const container = root.querySelector('[data-festival-telegram-mine]');
        container.replaceChildren();
        if (!state.registrant) return container.append(emptyCard());
        const profile = element('article', 'rounded-2xl border border-white/10 bg-white/[0.06] p-5');
        profile.append(element('h2', 'text-xl font-semibold', state.registrant.name));
        if (!state.registrant.profile_complete) profile.append(element('p', 'mt-2 text-sm font-semibold text-amber-200', labels.profile_incomplete));
        const actions = element('div', 'mt-4 flex flex-wrap gap-2');
        actions.append(actionButton(labels.open_profile, 'profile'));
        actions.append(actionButton(labels.applications, 'entries', null, true));
        profile.append(actions);
        container.append(profile);
        (state.registrant.entries || []).forEach((entry) => {
            const card = element('article', 'rounded-2xl border border-white/10 bg-white/[0.05] p-4');
            card.append(element('h3', 'font-semibold', entry.name || entry.code));
            card.append(element('p', 'mt-1 text-sm text-slate-300', [entry.edition, entry.category, entry.status].filter(Boolean).join(' · ')));
            card.append(actionButton(labels.open_ladna, 'entry', entry.id, true));
            container.append(card);
        });
    };

    const renderTickets = () => {
        const container = root.querySelector('[data-festival-telegram-tickets]');
        container.replaceChildren();
        const orders = state.guest?.orders || [];
        orders.forEach((order) => {
            const card = element('article', 'rounded-2xl border border-white/10 bg-white/[0.06] p-5');
            card.append(element('h3', 'font-semibold', order.edition));
            card.append(element('p', 'mt-2 text-sm text-slate-300', `${order.order_id} · ${order.status} · ${order.tickets_count}`));
            card.append(actionButton(labels.open_ladna, 'ticket_order', order.id));
            container.append(card);
        });
        if (!orders.length) container.append(emptyCard());
    };

    const renderStatistics = () => {
        const container = root.querySelector('[data-festival-telegram-statistics]');
        container.replaceChildren();
        const stats = state.registrant?.statistics;
        if (!stats) return container.append(emptyCard());
        const grid = element('div', 'grid grid-cols-3 gap-3');
        [['applications', labels.applications], ['accepted', labels.accepted], ['participants', labels.participants]].forEach(([key, title]) => {
            const card = element('div', 'rounded-2xl border border-white/10 bg-white/[0.06] p-4 text-center');
            card.append(element('div', 'text-2xl font-semibold', String(stats[key] || 0)));
            card.append(element('div', 'mt-1 text-xs text-slate-300', title));
            grid.append(card);
        });
        container.append(grid);
    };

    const renderPreferences = () => {
        const container = root.querySelector('[data-festival-telegram-preferences]');
        container.replaceChildren();
        const preferences = state.registrant?.preferences || {};
        Object.entries(preferences).forEach(([name, enabled]) => {
            const row = element('div', 'flex items-center justify-between rounded-2xl border border-white/10 bg-white/[0.06] px-4 py-3');
            row.append(element('span', 'text-sm font-semibold', name.replaceAll('_', ' ')));
            row.append(element('span', enabled ? 'text-sm font-semibold text-emerald-300' : 'text-sm font-semibold text-slate-400', enabled ? labels.enabled : labels.disabled));
            container.append(row);
        });
        if (!Object.keys(preferences).length) container.append(emptyCard());
    };

    const renderContacts = () => {
        const container = root.querySelector('[data-festival-telegram-contacts]');
        container.replaceChildren();
        const card = element('article', 'rounded-2xl border border-white/10 bg-white/[0.06] p-5');
        card.append(element('h2', 'text-xl font-semibold', state.series.organizer || state.series.name));
        [state.series.phone, state.series.email].filter(Boolean).forEach((value) => card.append(element('p', 'mt-2 text-sm text-slate-200', value)));
        const links = element('div', 'mt-4 flex flex-wrap gap-2');
        if (state.series.telegram_url) links.append(linkButton('Telegram', state.series.telegram_url));
        if (state.series.instagram_url) links.append(linkButton('Instagram', state.series.instagram_url));
        card.append(links);
        if (state.authorized) {
            const unlink = element('button', 'mt-6 text-sm font-semibold text-rose-300 underline underline-offset-4', labels.unlink);
            unlink.type = 'button';
            unlink.addEventListener('click', async () => {
                if (!window.confirm(labels.unlink_confirm)) return;
                try {
                    await request(root.dataset.unlinkUrl, 'DELETE');
                    window.location.reload();
                } catch { showError(); }
            });
            card.append(unlink);
        }
        container.append(card);
    };

    const renderAuthorization = () => {
        const card = root.querySelector('[data-festival-telegram-authorization]');
        const button = root.querySelector('[data-festival-telegram-contact]');
        if (state.authorized) {
            card.classList.add('border-emerald-400/30', 'bg-emerald-500/10');
            root.querySelector('[data-festival-telegram-auth-title]').textContent = labels.authorization_ready;
            root.querySelector('[data-festival-telegram-auth-copy]').textContent = state.identity?.phone || '';
            button.hidden = true;
            root.querySelector('[data-festival-telegram-nav]').classList.remove('hidden');
        }
    };

    const render = () => {
        renderAuthorization();
        renderEditions();
        renderMine();
        renderTickets();
        renderStatistics();
        renderPreferences();
        renderContacts();
    };

    const bootstrap = async (quiet = false) => {
        if (!initData) {
            if (!quiet) showError(labels.outside_telegram);
            return;
        }
        try {
            const next = await request(root.dataset.bootstrapUrl);
            state = next;
            clearError();
            render();
            if (state.authorized) {
                window.clearInterval(contactPoll);
                telegram?.requestWriteAccess?.(() => {});
                const params = new URLSearchParams(window.location.search);
                const action = params.get('action');
                const targetId = Number(params.get('target_id')) || null;
                if (action && !automaticActionOpened) {
                    automaticActionOpened = true;
                    const result = await request(root.dataset.actionUrl, 'POST', { action, target_id: targetId });
                    if (result.url) openUrl(result.url);
                }
            }
        } catch {
            if (!quiet) showError();
        }
    };

    root.querySelector('[data-festival-telegram-contact]').addEventListener('click', () => {
        clearError();
        if (!telegram?.requestContact) return showError(labels.outside_telegram);
        telegram.requestContact((accepted) => {
            if (!accepted) return;
            root.querySelector('[data-festival-telegram-auth-copy]').textContent = labels.authorization_waiting;
            contactPoll = window.setInterval(() => bootstrap(true), 1500);
        });
    });

    root.querySelectorAll('[data-festival-telegram-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
            root.querySelectorAll('[data-festival-telegram-tab]').forEach((candidate) => { candidate.dataset.active = String(candidate === tab); });
            root.querySelectorAll('[data-festival-telegram-panel]').forEach((panel) => panel.classList.toggle('hidden', panel.dataset.festivalTelegramPanel !== tab.dataset.festivalTelegramTab));
        });
    });

    render();
    bootstrap();
    timelinePoll = window.setInterval(() => {
        if (!document.hidden && state.authorized) bootstrap(true);
    }, 10000);
    window.addEventListener('pagehide', () => {
        window.clearInterval(contactPoll);
        window.clearInterval(timelinePoll);
    }, { once: true });
}
