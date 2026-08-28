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
    let countdownTimer = null;
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

    const richText = (html) => {
        const content = element('div', 'festival-telegram-rich-text prose prose-sm max-w-none text-slate-200');
        content.innerHTML = html;

        return content;
    };

    const disclosure = (title, count = null) => {
        const details = element('details', 'rounded-2xl border border-white/10 bg-white/[0.05] p-4');
        const suffix = count === null ? '' : ` · ${count}`;
        details.append(element('summary', 'cursor-pointer text-base font-semibold text-slate-100', `${title}${suffix}`));

        return details;
    };

    const formattedDateTime = (value) => value
        ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : '';

    const formattedTime = (value) => value
        ? new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(new Date(value))
        : '';

    const withItem = (template, item) => (template || '').replace('__item__', item || '—');

    const formattedEditionDate = (edition) => formattedDateTime(edition.starts_at);

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
        actions.append(linkButton(labels.open_page, edition.public_url));
        if (state.authorized) actions.append(actionButton(labels.tickets, 'ticket_checkout', edition.id, true));

        return actions;
    };

    const appendRichTextDisclosure = (container, title, html) => {
        if (!html) return;
        const details = disclosure(title);
        const content = richText(html);
        content.classList.add('mt-3');
        details.append(content);
        container.append(details);
    };

    const appendCategories = (container, edition) => {
        const groups = edition.category_groups || [];
        const count = groups.reduce((total, group) => total + (group.categories || []).length, 0);
        if (!count) return;

        const details = disclosure(labels.categories, count);
        const body = element('div', 'mt-4 space-y-5');
        groups.forEach((group) => {
            const section = element('section');
            section.append(element('h3', 'festival-telegram-accent-text text-sm font-semibold uppercase tracking-wider', group.name));
            const list = element('div', 'mt-3 space-y-3');
            (group.categories || []).forEach((category) => {
                const card = element('article', 'rounded-xl border border-white/10 bg-slate-950/35 p-4');
                card.append(element('h4', 'font-semibold text-white', category.name));
                card.append(element('p', 'mt-1 text-xs font-medium text-slate-400', category.format));
                if (category.limits?.length) {
                    const chips = element('div', 'mt-3 flex flex-wrap gap-2');
                    category.limits.forEach((limit) => chips.append(element('span', 'rounded-full bg-white/[0.07] px-2.5 py-1 text-xs text-slate-200', limit)));
                    card.append(chips);
                }
                if (category.registration_closes_at) {
                    card.append(element('p', 'mt-3 text-xs text-slate-400', `${labels.registration_closes_at}: ${formattedDateTime(category.registration_closes_at)}`));
                }
                if (category.requirements_html) {
                    card.append(element('h5', 'mt-3 border-t border-white/10 pt-3 text-xs font-semibold uppercase tracking-wider text-slate-400', labels.category_requirements));
                    const requirements = richText(category.requirements_html);
                    requirements.classList.add('mt-2');
                    card.append(requirements);
                }
                list.append(card);
            });
            section.append(list);
            body.append(section);
        });
        details.append(body);
        container.append(details);
    };

    const appendRubrics = (container, edition) => {
        const rubrics = edition.rubrics || [];
        if (!rubrics.length) return;

        const details = disclosure(labels.criteria, rubrics.length);
        const body = element('div', 'mt-4 space-y-3');
        rubrics.forEach((rubric) => {
            const rubricDetails = element('details', 'rounded-xl border border-white/10 bg-slate-950/35 p-3');
            const rubricTitle = rubric.category && !rubric.name.toLocaleLowerCase().includes(rubric.category.toLocaleLowerCase())
                ? `${rubric.name} · ${rubric.category}`
                : rubric.name;
            rubricDetails.append(element('summary', 'cursor-pointer font-semibold text-white', rubricTitle));
            const sections = element('div', 'mt-4 space-y-5');
            (rubric.sections || []).forEach((section) => {
                const sectionBlock = element('section');
                const heading = element('div', 'flex flex-wrap items-center justify-between gap-2');
                heading.append(element('h4', 'font-semibold text-slate-100', section.name));
                const contribution = section.contribution === 'deduction' ? labels.rubric_deduction : labels.rubric_award;
                const weight = Number(section.weight) !== 1 ? ` · ${labels.weight} ×${section.weight}` : '';
                heading.append(element('span', section.contribution === 'deduction'
                    ? 'rounded-full bg-rose-500/15 px-2.5 py-1 text-xs font-semibold text-rose-200'
                    : 'rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-semibold text-emerald-200', `${contribution}${weight}`));
                sectionBlock.append(heading);

                const tableWrap = element('div', 'mt-2 overflow-x-auto rounded-lg border border-white/10');
                const table = element('table', 'min-w-full table-fixed text-left text-sm');
                const head = element('thead', 'bg-white/[0.06] text-xs uppercase tracking-wide text-slate-400');
                const headRow = element('tr');
                headRow.append(element('th', 'w-auto px-3 py-2 font-semibold', labels.criteria));
                headRow.append(element('th', 'w-20 px-3 py-2 text-right font-semibold', labels.score));
                head.append(headRow);
                table.append(head);
                const tableBody = element('tbody', 'divide-y divide-white/10 text-slate-200');
                (section.criteria || []).forEach((criterion) => {
                    const row = element('tr');
                    row.append(element('td', 'break-words px-3 py-2.5 align-top', criterion.name));
                    const criterionWeight = Number(criterion.weight) !== 1 ? ` ×${criterion.weight}` : '';
                    row.append(element('td', 'px-3 py-2.5 text-right align-top font-mono tabular-nums', `${criterion.max_score}${criterionWeight}`));
                    tableBody.append(row);
                });
                table.append(tableBody);
                tableWrap.append(table);
                sectionBlock.append(tableWrap);
                sections.append(sectionBlock);
            });
            rubricDetails.append(sections);
            body.append(rubricDetails);
        });
        details.append(body);
        container.append(details);
    };

    const countProgramItems = (items) => (items || []).reduce((total, item) => total + 1 + countProgramItems(item.children), 0);

    const appendProgramItems = (container, items, depth = 0) => {
        (items || []).forEach((item) => {
            const isHeader = item.type === 'free_header' || item.type === 'category_header';
            const card = element('div', isHeader
                ? 'rounded-lg bg-fuchsia-500/10 px-3 py-2.5'
                : 'grid grid-cols-[4.5rem_minmax(0,1fr)] gap-3 rounded-lg bg-white/[0.04] px-3 py-2.5');
            if (depth > 0) card.classList.add('ml-3');
            if (isHeader) {
                card.append(element('h4', 'font-semibold text-fuchsia-100', item.name));
            } else {
                card.append(element('time', 'font-mono text-sm font-semibold tabular-nums text-slate-300', formattedTime(item.starts_at)));
                const copy = element('div', 'min-w-0');
                copy.append(element('p', 'font-semibold text-slate-100', item.name));
                copy.append(element('p', 'mt-0.5 text-xs text-slate-400', [item.category, item.type_label, item.ends_at ? formattedTime(item.ends_at) : null].filter(Boolean).join(' · ')));
                card.append(copy);
            }
            container.append(card);
            appendProgramItems(container, item.children, depth + 1);
        });
    };

    const appendProgram = (container, edition) => {
        const program = edition.program || [];
        const count = program.reduce((total, stage) => total + countProgramItems(stage.items), 0);
        if (!count) return;

        const details = disclosure(labels.program, count);
        const body = element('div', 'mt-4 space-y-5');
        program.forEach((stage) => {
            const scene = element('section');
            scene.append(element('h3', 'festival-telegram-accent-text text-sm font-semibold uppercase tracking-wider', stage.stage));
            const items = element('div', 'mt-2 space-y-2');
            appendProgramItems(items, stage.items);
            scene.append(items);
            body.append(scene);
        });
        details.append(body);
        container.append(details);
    };

    const liveTimeline = (edition) => {
        const scenes = edition.timeline || [];
        if (!scenes.length) return null;

        const live = element('section', 'mt-5 rounded-2xl border border-fuchsia-400/30 bg-fuchsia-500/10 p-4 shadow-lg shadow-fuchsia-950/20');
        const eyebrow = element('div', 'flex items-center gap-2 text-xs font-bold uppercase tracking-[0.18em] text-fuchsia-200');
        eyebrow.append(element('span', 'h-2.5 w-2.5 animate-pulse rounded-full bg-rose-400'));
        eyebrow.append(element('span', '', labels.timeline_live));
        live.append(eyebrow);
        live.append(element('h3', 'mt-2 text-xl font-semibold text-white', labels.happening_now));

        const sceneList = element('div', 'mt-4 space-y-4');
        scenes.forEach((scene) => {
            const sceneCard = element('article', 'rounded-xl border border-white/10 bg-slate-950/45 p-4');
            sceneCard.append(element('h4', 'text-sm font-semibold uppercase tracking-wider text-slate-300', scene.scene_name));
            const active = (scene.items || []).find((item) => item.status === 'active');
            const next = (scene.items || []).find((item) => item.status === 'future');
            if (active) {
                const current = element('div', 'mt-3 rounded-xl border border-amber-300/30 bg-amber-400/10 p-4');
                const currentHeader = element('div', 'flex flex-wrap items-center justify-between gap-2');
                currentHeader.append(element('span', 'text-xs font-bold uppercase tracking-wider text-amber-200', labels.timeline_active));
                if (scene.paused) currentHeader.append(element('span', 'rounded-full bg-rose-500/20 px-2 py-1 text-xs font-semibold text-rose-200', labels.timeline_paused));
                current.append(currentHeader);
                current.append(element('p', 'mt-2 text-lg font-semibold leading-tight text-white', active.label));
                current.append(element('p', 'mt-1 text-xs text-slate-300', [active.type_label, active.duration_label].filter(Boolean).join(' · ')));
                if (scene.next_transition_iso && !scene.paused && !scene.completed) {
                    const countdown = element('div', 'mt-3 font-mono text-2xl font-semibold tabular-nums text-amber-100', '--:--');
                    countdown.dataset.festivalTelegramCountdown = scene.next_transition_iso;
                    current.append(countdown);
                }
                sceneCard.append(current);
            } else {
                const stateLabel = scene.completed
                    ? labels.timeline_completed
                    : withItem(labels.timeline_waiting, scene.next_label || next?.label);
                sceneCard.append(element('p', 'mt-3 text-sm text-slate-300', stateLabel));
            }
            if (next && !scene.completed) {
                sceneCard.append(element('p', 'mt-3 text-sm text-slate-300', withItem(labels.timeline_next, next.label)));
            }
            sceneList.append(sceneCard);
        });
        live.append(sceneList);

        return live;
    };

    const updateCountdowns = () => {
        root.querySelectorAll('[data-festival-telegram-countdown]').forEach((node) => {
            const remaining = Math.max(0, Math.floor((new Date(node.dataset.festivalTelegramCountdown).getTime() - Date.now()) / 1000));
            const hours = Math.floor(remaining / 3600);
            const minutes = Math.floor((remaining % 3600) / 60);
            const seconds = remaining % 60;
            node.textContent = [hours, minutes, seconds]
                .map((part) => String(part).padStart(2, '0'))
                .join(':');
        });
    };

    const appendEditionSections = (container, edition) => {
        appendRichTextDisclosure(container, labels.details, edition.description_html);
        (edition.sections || []).forEach((section) => appendRichTextDisclosure(container, section.title, section.body_html));
        appendRichTextDisclosure(container, labels.rules, edition.rules_html);
        appendCategories(container, edition);
        appendRubrics(container, edition);
        appendProgram(container, edition);

        [['results', edition.results], ['documents', edition.documents]].forEach(([name, items]) => {
            if (!items?.length) return;
            const details = disclosure(labels[name], items.length);
            const list = element('div', 'mt-3 space-y-2');
            if (name === 'documents') {
                items.forEach((item) => list.append(linkButton(item.title, item.url)));
            } else {
                items.forEach((item) => list.append(element('div', 'rounded-lg bg-white/[0.04] px-3 py-2 text-sm text-slate-200', [item.rank, item.entry_name, item.category].filter(Boolean).join(' · '))));
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
        const liveHost = element('div');
        liveHost.dataset.festivalTelegramLive = String(edition.id);
        const live = liveTimeline(edition);
        if (live) liveHost.append(live);
        card.append(liveHost);
        card.append(editionActions(edition));
        container.append(card);
        updateCountdowns();

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
            card.append(actionButton(labels.open_page, 'entry', entry.id, true));
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
            card.append(actionButton(labels.open_page, 'ticket_order', order.id));
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

    const refreshTimeline = async () => {
        if (!initData || !(state.editions || []).some((edition) => edition.period === 'live')) return;

        try {
            const result = await request(root.dataset.timelineUrl);
            const updates = new Map((result.editions || []).map((edition) => [edition.id, edition]));
            state.editions = (state.editions || []).map((edition) => updates.has(edition.id)
                ? { ...edition, ...updates.get(edition.id) }
                : edition);

            const liveHost = root.querySelector('[data-festival-telegram-live]');
            if (liveHost) {
                const edition = state.editions.find((candidate) => candidate.id === Number(liveHost.dataset.festivalTelegramLive));
                liveHost.replaceChildren();
                const live = edition ? liveTimeline(edition) : null;
                if (live) liveHost.append(live);
                updateCountdowns();
            }
        } catch {
            // The next public timeline poll may recover without interrupting the open Mini App.
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
        if (!document.hidden) refreshTimeline();
    }, 10000);
    countdownTimer = window.setInterval(updateCountdowns, 1000);
    window.addEventListener('pagehide', () => {
        window.clearInterval(contactPoll);
        window.clearInterval(timelinePoll);
        window.clearInterval(countdownTimer);
    }, { once: true });
}
