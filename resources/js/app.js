import { createIcons, icons } from 'lucide';

let pendingDeleteForm = null;

const cyrillicMap = {
    а: 'a',
    б: 'b',
    в: 'v',
    г: 'h',
    ґ: 'g',
    д: 'd',
    е: 'e',
    є: 'ye',
    ё: 'yo',
    ж: 'zh',
    з: 'z',
    и: 'y',
    і: 'i',
    ї: 'yi',
    й: 'y',
    к: 'k',
    л: 'l',
    м: 'm',
    н: 'n',
    о: 'o',
    п: 'p',
    р: 'r',
    с: 's',
    т: 't',
    у: 'u',
    ф: 'f',
    х: 'kh',
    ц: 'ts',
    ч: 'ch',
    ш: 'sh',
    щ: 'shch',
    ъ: '',
    ы: 'y',
    ь: '',
    э: 'e',
    ю: 'yu',
    я: 'ya',
};

function closeDeleteConfirmation(modal) {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    pendingDeleteForm = null;
}

function updateAnyTimeAddon(container) {
    const toggle = container?.querySelector('[data-any-time-toggle]');
    const fields = container?.querySelector('[data-any-time-addon-fields]');
    const priceInput = container?.querySelector('[data-any-time-addon-price]');

    if (!toggle || !fields) {
        return;
    }

    fields.classList.toggle('hidden', !toggle.checked);

    if (priceInput) {
        priceInput.required = toggle.checked;
    }
}

function updateAnyTimeCurrencies(form) {
    const currency = form?.querySelector('[data-class-pass-currency]');

    if (!currency) {
        return;
    }

    form.querySelectorAll('[data-any-time-currency]').forEach((label) => {
        label.textContent = currency.value;
    });
}

function slugify(value) {
    return value
        .trim()
        .toLowerCase()
        .split('')
        .map((char) => cyrillicMap[char] ?? char)
        .join('')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function initSlugAutofill() {
    document.querySelectorAll('form').forEach((form) => {
        const nameInput = form.querySelector('input[name="name"]');
        const slugInput = form.querySelector('input[name="slug"]');

        if (!nameInput || !slugInput) {
            return;
        }

        let slugTouched = false;

        const updateSlug = () => {
            if (slugTouched) {
                return;
            }

            slugInput.value = slugify(nameInput.value);
        };

        slugInput.addEventListener('input', () => {
            slugTouched = true;
        });

        slugInput.addEventListener('change', () => {
            slugTouched = true;
        });

        nameInput.addEventListener('input', updateSlug);

        if (!slugInput.value) {
            updateSlug();
        }
    });
}

function initColorPickers() {
    document.querySelectorAll('[data-color-picker]').forEach((picker) => {
        const container = picker.closest('label') ?? picker.parentElement;
        const input = container?.querySelector('[data-color-value]');

        if (!input) {
            return;
        }

        const validHex = (value) => /^#[0-9A-Fa-f]{6}$/.test(value);

        picker.addEventListener('input', () => {
            input.value = picker.value.toUpperCase();
        });

        input.addEventListener('input', () => {
            if (validHex(input.value)) {
                picker.value = input.value;
            }
        });

        if (validHex(input.value)) {
            picker.value = input.value;
        }
    });
}

function initCustomerAutocomplete() {
    document.querySelectorAll('[data-customer-autocomplete]').forEach((container) => {
        const input = container.querySelector('[data-customer-autocomplete-input]');
        const hiddenInput = container.querySelector('[data-customer-autocomplete-id]');
        const results = container.querySelector('[data-customer-autocomplete-results]');
        const searchUrl = container.dataset.searchUrl;
        const noResults = container.dataset.noResults ?? 'No results';
        let selectedLabel = '';
        let abortController = null;
        let debounceTimer = null;

        if (!input || !hiddenInput || !results || !searchUrl) {
            return;
        }

        const hideResults = () => {
            results.classList.add('hidden');
            results.innerHTML = '';
        };

        const renderResults = (customers) => {
            results.innerHTML = '';

            if (customers.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'px-3 py-2 text-sm text-slate-500';
                empty.textContent = noResults;
                results.append(empty);
                results.classList.remove('hidden');
                return;
            }

            customers.forEach((customer) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-brand-50 hover:text-slate-950';
                button.textContent = customer.label;
                button.addEventListener('click', () => {
                    selectedLabel = customer.label;
                    input.value = customer.label;
                    hiddenInput.value = customer.id;
                    hideResults();
                });
                results.append(button);
            });

            results.classList.remove('hidden');
        };

        const search = () => {
            const term = input.value.trim();

            if (input.value !== selectedLabel) {
                hiddenInput.value = '';
            }

            abortController?.abort();
            abortController = new AbortController();

            const url = new URL(searchUrl, window.location.origin);
            url.searchParams.set('q', term);

            fetch(url, {
                headers: { Accept: 'application/json' },
                signal: abortController.signal,
            })
                .then((response) => (response.ok ? response.json() : []))
                .then(renderResults)
                .catch((error) => {
                    if (error.name !== 'AbortError') {
                        hideResults();
                    }
                });
        };

        input.addEventListener('input', () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(search, 180);
        });

        input.addEventListener('focus', search);

        document.addEventListener('click', (event) => {
            if (!container.contains(event.target)) {
                hideResults();
            }
        });
    });
}

function initClassRecordMock() {
    const modal = document.querySelector('[data-class-record-mock-modal]');
    const openButton = document.querySelector('[data-class-record-mock-open]');
    const closeButton = document.querySelector('[data-class-record-mock-close]');

    if (!modal || !openButton || !closeButton) {
        return;
    }

    const closeModal = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    openButton.addEventListener('click', () => {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        closeButton.focus();
    });

    closeButton.addEventListener('click', closeModal);

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    createIcons({ icons });
    initSlugAutofill();
    initColorPickers();
    initCustomerAutocomplete();
    initClassRecordMock();

    const sidebar = document.querySelector('[data-sidebar]');
    const sidebarBackdrop = document.querySelector('[data-sidebar-backdrop]');
    const openSidebarButton = document.querySelector('[data-sidebar-open]');
    const closeSidebarButton = document.querySelector('[data-sidebar-close]');

    const closeSidebar = () => {
        if (!sidebar || !sidebarBackdrop) {
            return;
        }

        sidebar.classList.add('-translate-x-full');
        sidebarBackdrop.classList.add('hidden');
    };

    const openSidebar = () => {
        if (!sidebar || !sidebarBackdrop) {
            return;
        }

        sidebar.classList.remove('-translate-x-full');
        sidebarBackdrop.classList.remove('hidden');
    };

    openSidebarButton?.addEventListener('click', openSidebar);
    closeSidebarButton?.addEventListener('click', closeSidebar);
    sidebarBackdrop?.addEventListener('click', closeSidebar);

    document.querySelectorAll('[data-any-time-addon]').forEach((container) => {
        updateAnyTimeAddon(container);
        updateAnyTimeCurrencies(container.closest('form'));
    });

    document.addEventListener('click', (event) => {
        const selectAllButton = event.target.closest('[data-select-all-directions]');

        if (!selectAllButton) {
            return;
        }

        const group = selectAllButton.closest('[data-activity-direction-group]');
        group?.querySelectorAll('[data-activity-direction-checkbox]').forEach((checkbox) => {
            checkbox.checked = true;
        });
    });

    document.addEventListener('click', (event) => {
        const selectAllButton = event.target.closest('[data-select-all-trainer-types]');

        if (!selectAllButton) {
            return;
        }

        const group = selectAllButton.closest('[data-trainer-type-group]');
        group?.querySelectorAll('[data-trainer-type-checkbox]').forEach((checkbox) => {
            checkbox.checked = true;
        });
    });

    document.addEventListener('change', (event) => {
        const anyTimeToggle = event.target.closest('[data-any-time-toggle]');

        if (anyTimeToggle) {
            updateAnyTimeAddon(anyTimeToggle.closest('[data-any-time-addon]'));
        }

        const currencySelect = event.target.closest('[data-class-pass-currency]');

        if (currencySelect) {
            updateAnyTimeCurrencies(currencySelect.closest('form'));
        }
    });

    const modal = document.getElementById('delete-confirmation-modal');
    const cancelButton = document.querySelector('[data-confirm-cancel]');
    const acceptButton = document.querySelector('[data-confirm-accept]');

    if (!modal || !cancelButton || !acceptButton) {
        return;
    }

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-confirm-delete]');

        if (!form || form.dataset.confirmed === 'true') {
            return;
        }

        event.preventDefault();
        pendingDeleteForm = form;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        acceptButton.focus();
    });

    cancelButton.addEventListener('click', () => closeDeleteConfirmation(modal));

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeDeleteConfirmation(modal);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSidebar();
        }

        if (event.key === 'Escape') {
            const classRecordMockModal = document.querySelector('[data-class-record-mock-modal]:not(.hidden)');
            classRecordMockModal?.classList.add('hidden');
            classRecordMockModal?.classList.remove('flex');
        }

        if (event.key === 'Escape' && pendingDeleteForm) {
            closeDeleteConfirmation(modal);
        }
    });

    acceptButton.addEventListener('click', () => {
        if (!pendingDeleteForm) {
            return;
        }

        pendingDeleteForm.dataset.confirmed = 'true';
        pendingDeleteForm.requestSubmit();
    });
});
