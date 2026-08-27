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
    let selectedEditionId = null;

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

    const confirmAction = (message) => new Promise((resolve) => {
        if (telegram?.showConfirm) {
            telegram.showConfirm(message, resolve);
            return;
        }

        resolve(window.confirm(message));
    });

    const actionButton = (text, action, targetId, secondary = false, confirmation = null) => {
        const button = element('button', secondary
            ? 'inline-flex min-h-10 w-full items-center justify-center rounded-xl border border-white/15 bg-white/[0.06] px-3 py-2 text-sm font-semibold text-slate-100'
            : 'festival-telegram-accent-button inline-flex min-h-10 w-full items-center justify-center rounded-xl px-3 py-2 text-sm font-semibold text-white', text);
        button.type = 'button';
        button.addEventListener('click', async () => {
            clearError();
            try {
                if (confirmation && ! await confirmAction(confirmation)) return;
                const result = await request(root.dataset.actionUrl, 'POST', { action, target_id: targetId });
                if (result.url) openUrl(result.url);
            } catch {
                showError();
            }
        });
        return button;
    };

    const linkButton = (text, url) => {
        const button = element('button', 'inline-flex min-h-10 w-full items-center justify-center rounded-xl border border-white/15 bg-white/[0.06] px-3 py-2 text-sm font-semibold text-slate-100', text);
        button.type = 'button';
        button.addEventListener('click', () => openUrl(url));
        return button;
    };

    const emptyCard = () => element('div', 'rounded-2xl border border-white/10 bg-white/[0.05] p-5 text-sm text-slate-300', labels.no_items);

    const formattedEditionDate = (edition) => edition.starts_at
        ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(edition.starts_at))
        : '';

    const editionThumbnail = (edition, sizeClasses) => {
        if (!edition.thumbnail_url) {
            return element('div', `festival-telegram-accent-button ${sizeClasses} flex shrink-0 items-center justify-center rounded-2xl text-2xl font-semibold text-white`, edition.title.slice(0, 1));
        }

        const image = element('img', `${sizeClasses} shrink-0 rounded-2xl object-cover`);
        image.src = edition.thumbnail_url;
        image.alt = edition.thumbnail_alt || edition.title;
        image.loading = 'lazy';

        return image;
    };

    const editionActions = (edition) => {
        const actions = element('div', 'mt-5 grid gap-2');
        const applicationCount = (state.registrant?.entries || []).filter((entry) => entry.edition_id === edition.id).length;
        if (state.authorized && state.registrant && edition.registration_open) {
            actions.append(actionButton(
                labels.new_application,
                'create_entry',
                edition.id,
                false,
                applicationCount > 0 ? labels.additional_application_confirmation : null,
            ));
        }
        if (state.authorized && state.registrant) {
            actions.append(actionButton(labels.my_applications_count.replace('__count__', String(applicationCount)), 'entries', null, true));
        }
        actions.append(linkButton(labels.open_ladna, edition.public_url));
        if (state.authorized) actions.append(actionButton(labels.tickets, 'ticket_checkout', edition.id, true));

        return actions;
    };

    const appendEditionSections = (container, edition) => {
        [['timeline', edition.timeline], ['schedule', edition.schedule], ['results', edition.results], ['documents', edition.documents]].forEach(([name, items]) => {
            if (!items?.length) return;
            const details = element('details', 'rounded-2xl border border-white/10 bg-white/[0.05] p-4');
            details.append(element('summary', 'cursor-pointer text-base font-semibold text-slate-100', `${labels[name]} · ${items.length}`));
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
            container.append(details);
        });
    };

    const renderEditionDetail = (edition, container) => {
        const back = element('button', 'mb-4 inline-flex min-h-10 items-center gap-2 rounded-xl border border-white/10 bg-white/[0.06] px-4 py-2 text-sm font-semibold text-slate-100', `← ${labels.back}`);
        back.type = 'button';
        back.dataset.festivalTelegramEditionBack = '';
        back.addEventListener('click', () => {
            selectedEditionId = null;
            renderEditions();
            renderAuthorization();
            root.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        container.append(back);

        const card = element('article', 'rounded-2xl border border-white/10 bg-white/[0.06] p-5 shadow-xl shadow-black/10');
        const header = element('div', 'flex items-start gap-4');
        header.append(editionThumbnail(edition, 'h-24 w-24'));
        const heading = element('div', 'min-w-0 flex-1');
        heading.append(element('div', 'festival-telegram-accent-text text-xs font-semibold uppercase tracking-wider', labels[edition.period] || edition.period));
        heading.append(element('h2', 'mt-1 text-xl font-semibold leading-tight', edition.title));
        if (edition.starts_at) heading.append(element('p', 'mt-2 text-sm text-slate-300', formattedEditionDate(edition)));
        header.append(heading);
        card.append(header);
        if (edition.venue_name || edition.venue_address) card.append(element('p', 'mt-4 text-sm font-medium text-slate-200', [edition.venue_name, edition.venue_address].filter(Boolean).join(' · ')));
        if (edition.summary) card.append(element('p', 'mt-3 text-sm leading-6 text-slate-300', edition.summary));
        card.append(editionActions(edition));
        container.append(card);

        const sections = element('div', 'mt-4 space-y-3');
        appendEditionSections(sections, edition);
        container.append(sections);
    };

    const renderEditions = () => {
        const list = root.querySelector('[data-festival-telegram-editions]');
        const detail = root.querySelector('[data-festival-telegram-edition-detail]');
        list.replaceChildren();
        detail.replaceChildren();

        let selectedEdition = (state.editions || []).find((edition) => edition.id === selectedEditionId);
        if (selectedEditionId !== null && !selectedEdition) {
            selectedEditionId = null;
            selectedEdition = null;
        }
        list.classList.toggle('hidden', Boolean(selectedEdition));
        detail.classList.toggle('hidden', !selectedEdition);

        if (selectedEdition) {
            renderEditionDetail(selectedEdition, detail);
            return;
        }

        (state.editions || []).forEach((edition) => {
            const button = element('button', 'grid w-full grid-cols-[4.5rem_1fr] items-center gap-4 rounded-2xl border border-white/10 bg-white/[0.06] p-3 text-left shadow-lg shadow-black/10 transition active:scale-[0.99]');
            button.type = 'button';
            button.dataset.festivalTelegramEdition = String(edition.id);
            button.append(editionThumbnail(edition, 'h-18 w-18'));
            const copy = element('span', 'min-w-0');
            copy.append(element('span', 'festival-telegram-accent-text block text-xs font-semibold uppercase tracking-wider', labels[edition.period] || edition.period));
            copy.append(element('span', 'mt-1 block text-lg font-semibold leading-tight text-white', edition.title));
            if (edition.starts_at) copy.append(element('span', 'mt-2 block text-sm text-slate-300', formattedEditionDate(edition)));
            if (edition.venue_name) copy.append(element('span', 'mt-1 block truncate text-xs text-slate-400', edition.venue_name));
            button.append(copy);
            button.addEventListener('click', () => {
                selectedEditionId = edition.id;
                renderEditions();
                renderAuthorization();
                root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
            list.append(button);
        });
        if (!list.children.length) list.append(emptyCard());
    };

    const renderMine = () => {
        const container = root.querySelector('[data-festival-telegram-mine]');
        container.replaceChildren();
        if (!state.registrant) return container.append(emptyCard());
        const profile = element('article', 'rounded-2xl border border-white/10 bg-white/[0.06] p-5');
        profile.append(element('h2', 'text-xl font-semibold', state.registrant.name));
        if (!state.registrant.profile_complete) profile.append(element('p', 'mt-2 text-sm font-semibold text-amber-200', labels.profile_incomplete));
        const actions = element('div', 'mt-4 grid gap-2');
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
        const links = element('div', 'mt-4 grid gap-2');
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
        const navigation = root.querySelector('[data-festival-telegram-nav]');
        const seriesHero = root.querySelector('[data-festival-telegram-series-hero]');
        card.classList.toggle('hidden', state.authorized);
        navigation.classList.toggle('hidden', !state.authorized || selectedEditionId !== null);
        seriesHero.classList.toggle('hidden', selectedEditionId !== null);
        button.hidden = state.authorized;

        if (state.authorized) {
            window.clearInterval(contactPoll);
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
            selectedEditionId = null;
            root.querySelectorAll('[data-festival-telegram-tab]').forEach((candidate) => { candidate.dataset.active = String(candidate === tab); });
            root.querySelectorAll('[data-festival-telegram-panel]').forEach((panel) => panel.classList.toggle('hidden', panel.dataset.festivalTelegramPanel !== tab.dataset.festivalTelegramTab));
            renderEditions();
            renderAuthorization();
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
