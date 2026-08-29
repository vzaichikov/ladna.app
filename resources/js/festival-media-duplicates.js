const focusableSelector = 'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

const formatTemplate = (template, values) => Object.entries(values).reduce(
    (formatted, [key, value]) => formatted.replaceAll(`:${key}`, String(value)),
    template,
);

const setState = (modal, state) => {
    modal.querySelectorAll('[data-festival-media-duplicates-state]').forEach((element) => {
        element.classList.toggle('hidden', element.dataset.festivalMediaDuplicatesState !== state);
    });
};

const createTextElement = (tag, className, text) => {
    const element = document.createElement(tag);
    element.className = className;
    element.textContent = text;

    return element;
};

const renderGroups = (modal, groups) => {
    const container = modal.querySelector('[data-festival-media-duplicates-results]');
    container.replaceChildren();

    groups.forEach((group, groupIndex) => {
        const section = document.createElement('section');
        section.className = 'rounded-2xl border border-amber-200 bg-amber-50/60 p-4 sm:p-5';
        section.append(createTextElement(
            'h3',
            'font-semibold text-amber-950',
            formatTemplate(modal.dataset.groupTemplate, { number: groupIndex + 1 }),
        ));
        section.append(createTextElement('p', 'mt-1 text-sm leading-6 text-amber-900', group.reason));

        const applications = document.createElement('div');
        applications.className = 'mt-4 grid gap-3 lg:grid-cols-2';

        group.applications.forEach((application) => {
            const article = document.createElement('article');
            article.className = 'min-w-0 rounded-xl border border-stone-200 bg-white p-4 shadow-xs';
            const link = document.createElement('a');
            link.className = 'break-words font-semibold text-brand-700 hover:text-brand-800';
            link.href = application.url;
            link.textContent = `${application.code} · ${application.name}`;
            article.append(link);

            const fields = document.createElement('dl');
            fields.className = 'mt-3 space-y-3';

            application.fields.forEach((field) => {
                const item = document.createElement('div');
                item.className = 'min-w-0 rounded-lg bg-slate-50 p-3';
                const label = field.subject ? `${field.label} · ${field.subject}` : field.label;
                item.append(createTextElement('dt', 'text-xs font-semibold text-slate-600', label));
                item.append(createTextElement('dd', 'mt-1 whitespace-pre-wrap break-words text-sm text-slate-900', field.value));
                fields.append(item);
            });

            article.append(fields);
            applications.append(article);
        });

        section.append(applications);
        container.append(section);
    });
};

const checkedSummary = (modal, payload) => formatTemplate(modal.dataset.summaryTemplate, {
    applications: payload.checked_applications,
    fields: payload.checked_fields,
});

const normalizePayload = (payload) => {
    if (!payload || !Number.isInteger(payload.checked_applications) || !Number.isInteger(payload.checked_fields) || !Array.isArray(payload.duplicate_groups)) {
        return null;
    }

    const validGroups = payload.duplicate_groups.every((group) => group
        && typeof group.reason === 'string'
        && Array.isArray(group.applications)
        && group.applications.length >= 2
        && group.applications.every((application) => application
            && typeof application.code === 'string'
            && typeof application.name === 'string'
            && typeof application.url === 'string'
            && Array.isArray(application.fields)
            && application.fields.every((field) => field
                && typeof field.label === 'string'
                && (field.subject === null || typeof field.subject === 'string')
                && typeof field.value === 'string')));

    return validGroups ? payload : null;
};

export const initFestivalMediaDuplicates = () => {
    const modal = document.querySelector('[data-festival-media-duplicates-modal]');
    const opener = document.querySelector('[data-festival-media-duplicates-open]');

    if (!modal || !opener) {
        return;
    }

    const panel = modal.querySelector('[data-festival-media-duplicates-panel]');
    const announcement = modal.querySelector('[data-festival-media-duplicates-announcement]');
    let activeOpener = null;
    let previousBodyOverflow = '';
    let running = false;

    const close = () => {
        if (modal.classList.contains('hidden')) {
            return;
        }

        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = previousBodyOverflow;
        opener.setAttribute('aria-expanded', 'false');
        activeOpener?.focus();
        activeOpener = null;
    };

    const showError = (message) => {
        modal.querySelector('[data-festival-media-duplicates-error]').textContent = message;
        announcement.textContent = message;
        setState(modal, 'error');
    };

    const runCheck = async () => {
        if (running) {
            return;
        }

        running = true;
        opener.disabled = true;
        panel.setAttribute('aria-busy', 'true');
        announcement.textContent = modal.dataset.loadingMessage;
        setState(modal, 'loading');

        try {
            const csrfToken = modal.dataset.csrfToken;

            if (!csrfToken) {
                throw new Error(modal.dataset.errorMessage);
            }

            const response = await fetch(modal.dataset.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json().catch(() => null);

            if (!response.ok) {
                throw new Error(typeof payload?.message === 'string' ? payload.message : modal.dataset.errorMessage);
            }

            const normalizedPayload = normalizePayload(payload);

            if (!normalizedPayload) {
                throw new Error(modal.dataset.errorMessage);
            }

            const summary = checkedSummary(modal, normalizedPayload);

            if (normalizedPayload.checked_applications < 2) {
                modal.querySelector('[data-festival-media-duplicates-insufficient-summary]').textContent = summary;
                announcement.textContent = modal.dataset.insufficientMessage;
                setState(modal, 'insufficient');
            } else if (normalizedPayload.duplicate_groups.length === 0) {
                modal.querySelector('[data-festival-media-duplicates-empty-summary]').textContent = summary;
                announcement.textContent = summary;
                setState(modal, 'empty');
            } else {
                modal.querySelector('[data-festival-media-duplicates-summary]').textContent = summary;
                renderGroups(modal, normalizedPayload.duplicate_groups);
                announcement.textContent = summary;
                setState(modal, 'results');
            }
        } catch (error) {
            showError(error instanceof Error && error.message ? error.message : modal.dataset.errorMessage);
        } finally {
            running = false;
            opener.disabled = false;
            panel.removeAttribute('aria-busy');
        }
    };

    const open = () => {
        activeOpener = document.activeElement;
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        opener.setAttribute('aria-expanded', 'true');
        panel.focus();
        runCheck();
    };

    opener.setAttribute('aria-haspopup', 'dialog');
    opener.setAttribute('aria-expanded', 'false');
    opener.addEventListener('click', open);
    modal.querySelectorAll('[data-festival-media-duplicates-dismiss]').forEach((button) => button.addEventListener('click', close));
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            close();
        }
    });
    modal.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = [...modal.querySelectorAll(focusableSelector)]
            .filter((element) => !element.closest('.hidden'));
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
};
