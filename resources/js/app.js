import { createIcons, icons } from 'lucide';
import SimplePhoneMask from 'simple-phone-mask';
import 'summernote/dist/summernote-lite.css';

let pendingDeleteForm = null;
let pendingConfirmationSubmitter = null;
let publicScheduleAbortController = null;

const confirmationButtonVariants = {
    danger: 'border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100',
    success: 'bg-emerald-600 text-white shadow-sm shadow-emerald-600/20 hover:bg-emerald-700',
    primary: 'bg-brand-600 text-white shadow-sm shadow-brand-600/20 hover:bg-brand-700',
};
const confirmationIconVariants = {
    danger: 'bg-rose-50 text-rose-700',
    success: 'bg-emerald-50 text-emerald-700',
    primary: 'bg-brand-50 text-brand-700',
};
const confirmationButtonVariantClassList = Object.values(confirmationButtonVariants).flatMap((classes) => classes.split(' '));
const confirmationIconVariantClassList = Object.values(confirmationIconVariants).flatMap((classes) => classes.split(' '));

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
    pendingConfirmationSubmitter = null;
}

function applyClassVariant(element, allVariantClasses, variantClasses) {
    if (!element) {
        return;
    }

    element.classList.remove(...allVariantClasses);
    element.classList.add(...variantClasses.split(' '));
}

function applyConfirmationIcon(container, iconName) {
    if (!container) {
        return;
    }

    container.innerHTML = `<i data-lucide="${iconName}" class="h-5 w-5" aria-hidden="true"></i>`;
    createIcons({ icons });
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

function updateClassPassScheduleKind(form) {
    const select = form?.querySelector('[data-class-pass-schedule-kind]');

    if (!select) {
        return;
    }

    const segmentSelect = form.querySelector('[data-class-pass-segment]');
    let segmentDirectionIds = [];

    if (segmentSelect) {
        segmentSelect.querySelectorAll('option').forEach((option) => {
            const optionScheduleKind = option.dataset.scheduleKind || '';
            const isAvailable = !option.value || optionScheduleKind === select.value;

            option.hidden = !isAvailable;
            option.disabled = !isAvailable;
        });

        if (segmentSelect.selectedOptions[0]?.disabled) {
            segmentSelect.value = '';
        }

        segmentDirectionIds = (segmentSelect.selectedOptions[0]?.dataset.directionIds || '')
            .split(',')
            .filter(Boolean);
    }

    form.querySelectorAll('[data-class-type-options]').forEach((group) => {
        const isActive = group.dataset.classTypeOptions === select.value;

        group.classList.toggle('hidden', !isActive);
        group.querySelectorAll('input[name="class_type_ids[]"]').forEach((input) => {
            const option = input.closest('[data-class-type-option]');
            const activityDirectionId = option?.dataset.activityDirectionId || '';
            const isAllowedBySegment = segmentDirectionIds.length === 0 || segmentDirectionIds.includes(activityDirectionId);
            const isDisabled = !isActive || !isAllowedBySegment;

            input.disabled = isDisabled;

            if (isDisabled) {
                input.checked = false;
            }

            option?.classList.toggle('hidden', isActive && !isAllowedBySegment);
        });
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

function initStudioRulesEditors() {
    const editors = document.querySelectorAll('[data-studio-rules-editor]');

    if (editors.length === 0) {
        return;
    }

    import('jquery').then(({ default: $ }) => {
        window.$ = $;
        window.jQuery = $;

        return import('summernote/dist/summernote-lite.js').then(() => $);
    }).then(($) => {
        editors.forEach((editor) => {
            if (editor.dataset.studioRulesEditorReady === 'true') {
                return;
            }

            editor.dataset.studioRulesEditorReady = 'true';

            $(editor).summernote({
                height: 420,
                placeholder: editor.dataset.placeholder || '',
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['insert', ['link']],
                    ['history', ['undo', 'redo']],
                ],
            });
        });
    });
}

function initCustomerAutocomplete(root = document) {
    root.querySelectorAll('[data-customer-autocomplete]').forEach((container) => {
        if (container.dataset.customerAutocompleteReady === 'true') {
            return;
        }

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

        container.dataset.customerAutocompleteReady = 'true';

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
                    const scope = container.closest('form') ?? document;
                    const nameTarget = input.dataset.nameTarget ? scope.querySelector(input.dataset.nameTarget) : null;
                    const phoneTarget = input.dataset.phoneTarget ? scope.querySelector(input.dataset.phoneTarget) : null;

                    selectedLabel = customer.label;
                    input.value = customer.label;
                    hiddenInput.value = customer.id;

                    if (nameTarget && customer.name) {
                        nameTarget.value = customer.name;
                    }

                    if (phoneTarget && customer.phone) {
                        setPhoneMaskValue(phoneTarget, customer.phone);
                    }

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

function setPhoneMaskValue(input, value) {
    if (!input) {
        return;
    }

    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

function initPhoneMasks(root = document) {
    root.querySelectorAll('[data-phone-mask]').forEach((input, index) => {
        if (input.dataset.phoneMaskReady === 'true') {
            return;
        }

        if (
            input.closest('[data-customer-auth-panel].hidden')
            || input.closest('[data-quick-booking-modal].hidden')
            || input.closest('[data-manual-class-modal].hidden')
        ) {
            return;
        }

        if (!input.id) {
            input.id = `phone-mask-${Date.now()}-${index}`;
        }

        const initialValue = input.value;
        const messageSource = input.closest('[data-phone-mask-error]') ?? document.body;
        input.dataset.phoneMaskReady = 'true';

        new SimplePhoneMask(`#${input.id}`, {
            countryCode: input.dataset.countryCode || 'UA',
            showFlag: true,
            allowCountrySelect: true,
            detectIP: false,
            showSearch: true,
            validate: input.dataset.phoneMaskValidate !== 'false',
            preferredCountries: ['UA', 'PL', 'US', 'GB', 'DE', 'FR'],
            errorMessage: input.dataset.phoneMaskError || messageSource?.dataset.phoneMaskError || 'Enter a complete phone number.',
            successMessage: input.dataset.phoneMaskSuccess || messageSource?.dataset.phoneMaskSuccess || 'Phone number looks good.',
        });

        const wrapper = input.closest('.spm-wrapper');
        const searchInput = wrapper?.querySelector('.spm-search-input');
        const noResults = wrapper?.querySelector('.spm-no-results');

        if (searchInput) {
            searchInput.placeholder = input.dataset.phoneMaskSearch || messageSource?.dataset.phoneMaskSearch || 'Search country...';
        }

        if (noResults) {
            noResults.textContent = input.dataset.phoneMaskNoResults || messageSource?.dataset.phoneMaskNoResults || 'No countries found.';
        }

        if (initialValue.trim() !== '') {
            input.value = initialValue;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
}

function initOtpCountdowns() {
    document.querySelectorAll('[data-otp-resend-button]').forEach((button) => {
        if (button.dataset.otpCountdownReady === 'true') {
            return;
        }

        button.dataset.otpCountdownReady = 'true';

        let seconds = Number.parseInt(button.dataset.otpCountdown || '0', 10);
        const label = document.querySelector('[data-otp-countdown-label]');
        const originalText = button.textContent.trim();
        const countdownMessage = button.dataset.otpCountdownMessage || 'You can request a new code in :seconds seconds.';

        const render = () => {
            if (seconds <= 0) {
                button.disabled = false;
                button.textContent = originalText;

                if (label) {
                    label.textContent = '';
                }

                return;
            }

            button.disabled = true;
            button.textContent = `${originalText} (${seconds})`;

            if (label) {
                label.textContent = countdownMessage.replace(':seconds', seconds);
            }

            seconds -= 1;
            window.setTimeout(render, 1000);
        };

        render();
    });
}

function initCustomerAuthTabs(root = document) {
    root.querySelectorAll('[data-customer-auth-tabs]').forEach((container) => {
        if (container.dataset.customerAuthTabsReady === 'true') {
            return;
        }

        const tabs = Array.from(container.querySelectorAll('[data-customer-auth-tab]'));
        const panels = Array.from(container.querySelectorAll('[data-customer-auth-panel]'));

        if (!tabs.length || !panels.length) {
            return;
        }

        container.dataset.customerAuthTabsReady = 'true';

        const activate = (method, focusTab = false) => {
            tabs.forEach((tab) => {
                const selected = tab.dataset.customerAuthTab === method;
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.tabIndex = selected ? 0 : -1;

                if (selected && focusTab) {
                    tab.focus();
                }
            });

            panels.forEach((panel) => {
                const selected = panel.dataset.customerAuthPanel === method;
                panel.classList.toggle('hidden', !selected);

                if (selected) {
                    initPhoneMasks(panel);
                }
            });
        };

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => {
                if (tab.dataset.customerAuthTab) {
                    activate(tab.dataset.customerAuthTab);
                }
            });

            tab.addEventListener('keydown', (event) => {
                const lastIndex = tabs.length - 1;
                let nextIndex = index;

                if (event.key === 'ArrowRight') {
                    nextIndex = index === lastIndex ? 0 : index + 1;
                } else if (event.key === 'ArrowLeft') {
                    nextIndex = index === 0 ? lastIndex : index - 1;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = lastIndex;
                } else {
                    return;
                }

                event.preventDefault();

                const method = tabs[nextIndex]?.dataset.customerAuthTab;

                if (method) {
                    activate(method, true);
                }
            });
        });

        const initialMethod = tabs.some((tab) => tab.dataset.customerAuthTab === container.dataset.activeMethod)
            ? container.dataset.activeMethod
            : tabs[0]?.dataset.customerAuthTab;

        if (initialMethod) {
            activate(initialMethod);
        }
    });
}

function initPlatformSettingsTabs(root = document) {
    root.querySelectorAll('[data-platform-settings-tabs]').forEach((container) => {
        if (container.dataset.platformSettingsTabsReady === 'true') {
            return;
        }

        const tabs = Array.from(container.querySelectorAll('[data-platform-settings-tab]'));
        const panels = Array.from(container.querySelectorAll('[data-platform-settings-panel]'));
        const activeTabInput = container.querySelector('[data-platform-settings-active-tab]');

        if (!tabs.length || !panels.length) {
            return;
        }

        container.dataset.platformSettingsTabsReady = 'true';

        const activate = (tabName, focusTab = false) => {
            tabs.forEach((tab) => {
                const selected = tab.dataset.platformSettingsTab === tabName;
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.tabIndex = selected ? 0 : -1;

                if (selected && focusTab) {
                    tab.focus();
                }
            });

            panels.forEach((panel) => {
                const selected = panel.dataset.platformSettingsPanel === tabName;
                panel.classList.toggle('hidden', !selected);
            });

            if (activeTabInput) {
                activeTabInput.value = tabName;
            }
        };

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => {
                if (tab.dataset.platformSettingsTab) {
                    activate(tab.dataset.platformSettingsTab);
                }
            });

            tab.addEventListener('keydown', (event) => {
                const lastIndex = tabs.length - 1;
                let nextIndex = index;

                if (event.key === 'ArrowRight') {
                    nextIndex = index === lastIndex ? 0 : index + 1;
                } else if (event.key === 'ArrowLeft') {
                    nextIndex = index === 0 ? lastIndex : index - 1;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = lastIndex;
                } else {
                    return;
                }

                event.preventDefault();

                const tabName = tabs[nextIndex]?.dataset.platformSettingsTab;

                if (tabName) {
                    activate(tabName, true);
                }
            });
        });

        const initialTab = tabs.some((tab) => tab.dataset.platformSettingsTab === container.dataset.activeTab)
            ? container.dataset.activeTab
            : tabs[0]?.dataset.platformSettingsTab;

        if (initialTab) {
            activate(initialTab);
        }
    });
}

function initPrintButtons() {
    document.querySelectorAll('[data-print-button]').forEach((button) => {
        if (button.dataset.printReady === 'true') {
            return;
        }

        button.dataset.printReady = 'true';
        button.addEventListener('click', () => window.print());
    });
}

function closeManualClassModal(modal) {
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
}

function closeQuickBookingModal(modal) {
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
}

function fillQuickBookingForm(modal, button) {
    const form = modal?.querySelector('form');

    if (!form) {
        return;
    }

    const leadInput = form.querySelector('[data-quick-booking-lead-id]');
    const customerIdInput = form.querySelector('[data-customer-autocomplete-id]');
    const customerSearchInput = form.querySelector('[data-customer-autocomplete-input]');
    const nameInput = form.querySelector('[data-quick-booking-customer-name]');
    const phoneInput = form.querySelector('[data-quick-booking-customer-phone]');

    if (leadInput) {
        leadInput.value = button?.dataset.quickBookingPrefillLead ?? '';
    }

    if (customerIdInput) {
        customerIdInput.value = '';
    }

    if (customerSearchInput) {
        customerSearchInput.value = '';
    }

    if (nameInput) {
        nameInput.value = button?.dataset.quickBookingPrefillName ?? '';
    }

    if (phoneInput) {
        setPhoneMaskValue(phoneInput, button?.dataset.quickBookingPrefillPhone ?? '');
    }

    updateQuickBookingRooms(form);
    loadManualBookingAvailability(form);
}

function renderGroupClassResults(container, classes) {
    container.innerHTML = '';

    if (!classes.length) {
        const empty = document.createElement('p');
        empty.className = 'px-2 py-3 text-sm text-slate-500';
        empty.textContent = container.dataset.empty || 'No classes found.';
        container.append(empty);
        return;
    }

    classes.forEach((scheduledClass) => {
        const label = document.createElement('label');
        label.className = 'flex cursor-pointer items-start gap-3 rounded-lg border border-stone-200 bg-white p-3 text-sm transition hover:border-brand-100 hover:bg-brand-50';

        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'scheduled_class_id';
        radio.value = scheduledClass.id;
        radio.required = true;
        radio.className = 'mt-1 size-4 border-stone-300 text-brand-600 focus:ring-brand-500';

        const body = document.createElement('span');
        body.className = 'min-w-0';

        const title = document.createElement('span');
        title.className = 'block font-semibold text-slate-950';
        title.textContent = `${scheduledClass.time} · ${scheduledClass.title}`;

        const meta = document.createElement('span');
        meta.className = 'mt-1 block text-slate-500';
        meta.textContent = `${scheduledClass.trainer} · ${scheduledClass.available_spots}/${scheduledClass.capacity}`;

        body.append(title, meta);
        label.append(radio, body);
        container.append(label);
    });
}

function loadGroupClassAvailability(input) {
    const form = input.closest('form');
    const results = form?.querySelector('[data-group-class-results]');
    const availabilityUrl = input.dataset.availabilityUrl;

    if (!results || !availabilityUrl) {
        return;
    }

    if (!input.value) {
        results.innerHTML = `<p class="px-2 py-3 text-sm text-slate-500">${input.dataset.empty || 'No classes found.'}</p>`;
        return;
    }

    results.dataset.empty = input.dataset.empty || '';
    results.innerHTML = `<p class="px-2 py-3 text-sm text-slate-500">${input.dataset.loading || 'Loading...'}</p>`;

    const url = new URL(availabilityUrl, window.location.origin);
    url.searchParams.set('date', input.value);

    fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    })
        .then((response) => (response.ok ? response.json() : { data: [] }))
        .then((payload) => renderGroupClassResults(results, payload.data ?? []))
        .catch(() => renderGroupClassResults(results, []));
}

function setManualBookingResultsMessage(container, message) {
    container.innerHTML = `<p class="px-2 py-3 text-sm text-slate-500">${message}</p>`;
}

function updateManualBookingStartsAt(form) {
    const dateInput = form?.querySelector('[data-manual-booking-date]');
    const timeInput = form?.querySelector('[data-manual-booking-time]');
    const startsAtInput = form?.querySelector('[data-manual-booking-starts-at]');

    if (!dateInput || !timeInput || !startsAtInput) {
        return;
    }

    startsAtInput.value = dateInput.value && timeInput.value ? `${dateInput.value}T${timeInput.value}` : '';
}

function resetManualBookingTime(form) {
    const timeInput = form?.querySelector('[data-manual-booking-time]');

    if (timeInput) {
        timeInput.value = '';
    }

    updateManualBookingStartsAt(form);
}

function renderManualBookingResults(container, slots, form, closed = false) {
    container.innerHTML = '';

    if (!slots.length) {
        const message = closed
            ? (container.dataset.closed || container.dataset.empty || 'No available times.')
            : (container.dataset.empty || 'No available times.');

        setManualBookingResultsMessage(container, message);
        return;
    }

    slots.forEach((slot) => {
        const label = document.createElement('label');
        label.className = 'flex cursor-pointer items-center gap-3 rounded-lg border border-stone-200 bg-white p-3 text-sm transition hover:border-brand-100 hover:bg-brand-50';

        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'manual_booking_slot';
        radio.value = slot.starts_at;
        radio.className = 'size-4 border-stone-300 text-brand-600 focus:ring-brand-500';
        radio.addEventListener('change', () => {
            const timeInput = form?.querySelector('[data-manual-booking-time]');

            if (timeInput) {
                timeInput.value = slot.time;
            }

            updateManualBookingStartsAt(form);
        });

        const body = document.createElement('span');
        body.className = 'min-w-0';

        const title = document.createElement('span');
        title.className = 'block font-semibold text-slate-950';
        title.textContent = slot.label || `${slot.time}-${slot.ends_time}`;

        body.append(title);
        label.append(radio, body);
        container.append(label);
    });
}

function loadManualBookingAvailability(form) {
    const dateInput = form?.querySelector('[data-manual-booking-date]');
    const results = form?.querySelector('[data-manual-booking-results]');
    const availabilityUrl = dateInput?.dataset.availabilityUrl;

    if (!dateInput || !results || !availabilityUrl) {
        return;
    }

    const scheduleKind = form.querySelector('input[name="schedule_kind"]')?.value;
    const locationId = form.querySelector('[data-quick-booking-location]')?.value;
    const roomId = form.querySelector('[data-quick-booking-room]')?.value;
    const classTypeId = form.querySelector('[data-manual-booking-class-type]')?.value;
    const trainerInput = form.querySelector('[data-manual-booking-trainer]');
    const trainerId = trainerInput?.value || '';

    resetManualBookingTime(form);

    if (!dateInput.value || !scheduleKind || !locationId || !roomId || !classTypeId || (trainerInput?.required && !trainerId)) {
        setManualBookingResultsMessage(results, results.dataset.empty || 'No available times.');
        return;
    }

    setManualBookingResultsMessage(results, dateInput.dataset.loading || 'Loading...');

    const url = new URL(availabilityUrl, window.location.origin);
    url.searchParams.set('schedule_kind', scheduleKind);
    url.searchParams.set('date', dateInput.value);
    url.searchParams.set('location_id', locationId);
    url.searchParams.set('room_id', roomId);
    url.searchParams.set('class_type_id', classTypeId);

    if (trainerId) {
        url.searchParams.set('trainer_id', trainerId);
    }

    fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    })
        .then((response) => (response.ok ? response.json() : { data: [] }))
        .then((payload) => renderManualBookingResults(results, payload.data ?? [], form, Boolean(payload.closed)))
        .catch(() => renderManualBookingResults(results, [], form));
}

function updateQuickBookingRooms(form) {
    const locationInput = form?.querySelector('[data-quick-booking-location]');
    const roomSelect = form?.querySelector('[data-quick-booking-room]');

    if (!locationInput || !roomSelect) {
        return;
    }

    const locationId = locationInput.value;
    let selectedOptionAllowed = false;

    Array.from(roomSelect.options).forEach((option) => {
        const isAllowed = !option.dataset.locationId || option.dataset.locationId === locationId;

        option.disabled = !isAllowed;
        option.hidden = !isAllowed;

        if (option.selected && isAllowed) {
            selectedOptionAllowed = true;
        }
    });

    if (!selectedOptionAllowed) {
        const firstAllowedOption = Array.from(roomSelect.options).find((option) => !option.disabled);

        if (firstAllowedOption) {
            roomSelect.value = firstAllowedOption.value;
        }
    }
}

function initManualClassModals() {
    document.querySelectorAll('[data-manual-class-modal]').forEach((modal) => {
        if (modal.dataset.manualClassReady === 'true') {
            return;
        }

        modal.dataset.manualClassReady = 'true';
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeManualClassModal(modal);
            }
        });
    });

    document.querySelectorAll('[data-manual-class-open]').forEach((button) => {
        if (button.dataset.manualClassOpenReady === 'true') {
            return;
        }

        button.dataset.manualClassOpenReady = 'true';
        button.addEventListener('click', () => {
            const modal = document.querySelector(`[data-manual-class-modal="${button.dataset.manualClassOpen}"]`);

            if (!modal) {
                return;
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            updateQuickBookingRooms(modal.querySelector('form'));
            modal.querySelector('[data-manual-class-close]')?.focus();
        });
    });

    document.querySelectorAll('[data-manual-class-modal] [data-quick-booking-location]').forEach((input) => {
        if (input.dataset.manualClassLocationReady === 'true') {
            return;
        }

        input.dataset.manualClassLocationReady = 'true';
        input.addEventListener('change', () => updateQuickBookingRooms(input.closest('form')));
        updateQuickBookingRooms(input.closest('form'));
    });

    document.querySelectorAll('[data-manual-class-close]').forEach((button) => {
        if (button.dataset.manualClassCloseReady === 'true') {
            return;
        }

        button.dataset.manualClassCloseReady = 'true';
        button.addEventListener('click', () => closeManualClassModal(button.closest('[data-manual-class-modal]')));
    });
}

function initQuickBookingModals() {
    document.querySelectorAll('[data-quick-booking-modal]').forEach((modal) => {
        if (modal.dataset.quickBookingReady === 'true') {
            return;
        }

        modal.dataset.quickBookingReady = 'true';
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeQuickBookingModal(modal);
            }
        });
    });

    document.querySelectorAll('[data-quick-booking-open]').forEach((button) => {
        if (button.dataset.quickBookingOpenReady === 'true') {
            return;
        }

        button.dataset.quickBookingOpenReady = 'true';
        button.addEventListener('click', () => {
            const modal = document.querySelector(`[data-quick-booking-modal="${button.dataset.quickBookingOpen}"]`);

            if (!modal) {
                return;
            }

            fillQuickBookingForm(modal, button);
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            initPhoneMasks(modal);
            modal.querySelector('[data-group-class-date]')?.dispatchEvent(new Event('change', { bubbles: true }));
            modal.querySelector('[data-quick-booking-close]')?.focus();
        });
    });

    document.querySelectorAll('[data-quick-booking-close]').forEach((button) => {
        if (button.dataset.quickBookingCloseReady === 'true') {
            return;
        }

        button.dataset.quickBookingCloseReady = 'true';
        button.addEventListener('click', () => closeQuickBookingModal(button.closest('[data-quick-booking-modal]')));
    });

    document.querySelectorAll('[data-group-class-date]').forEach((input) => {
        if (input.dataset.groupDateReady === 'true') {
            return;
        }

        input.dataset.groupDateReady = 'true';
        input.addEventListener('change', () => loadGroupClassAvailability(input));
    });

    document.querySelectorAll('[data-quick-booking-location]').forEach((input) => {
        if (input.dataset.quickBookingLocationReady === 'true') {
            return;
        }

        input.dataset.quickBookingLocationReady = 'true';
        input.addEventListener('change', () => {
            const form = input.closest('form');

            updateQuickBookingRooms(form);
            loadManualBookingAvailability(form);
        });
        updateQuickBookingRooms(input.closest('form'));
    });

    document.querySelectorAll('[data-quick-booking-room], [data-manual-booking-date], [data-manual-booking-class-type], [data-manual-booking-trainer]').forEach((input) => {
        if (input.dataset.manualAvailabilityReady === 'true') {
            return;
        }

        input.dataset.manualAvailabilityReady = 'true';
        input.addEventListener('change', () => loadManualBookingAvailability(input.closest('form')));
    });

    document.querySelectorAll('[data-manual-booking-time]').forEach((input) => {
        if (input.dataset.manualTimeReady === 'true') {
            return;
        }

        input.dataset.manualTimeReady = 'true';
        input.addEventListener('input', () => {
            input.closest('form')?.querySelectorAll('input[name="manual_booking_slot"]').forEach((radio) => {
                radio.checked = false;
            });
            updateManualBookingStartsAt(input.closest('form'));
        });
    });
}

function initCopyButtons() {
    document.querySelectorAll('[data-copy-token]').forEach((button) => {
        if (button.dataset.copyReady === 'true') {
            return;
        }

        button.dataset.copyReady = 'true';
        button.addEventListener('click', () => {
            const input = button.closest('article')?.querySelector('[data-copy-source]');

            if (!input) {
                return;
            }

            input.select();

            if (navigator.clipboard?.writeText) {
                navigator.clipboard.writeText(input.value).catch(() => document.execCommand('copy'));
                return;
            }

            document.execCommand('copy');
        });
    });
}

function asyncStatusElement() {
    return document.querySelector('[data-async-status]');
}

function setAsyncStatus(message, type = 'success') {
    const status = asyncStatusElement();

    if (!status || !message) {
        return;
    }

    status.textContent = message;
    status.className = type === 'error'
        ? 'mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700 shadow-xs'
        : 'mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900 shadow-xs';
    status.classList.remove('hidden');
}

function fallbackAsyncMessage(type = 'error') {
    const status = asyncStatusElement();

    if (!status) {
        return 'Request failed.';
    }

    return type === 'validation'
        ? status.dataset.validationMessage ?? 'Please check the highlighted fields.'
        : status.dataset.errorMessage ?? 'Could not save changes. Please try again.';
}

function setFormDisabled(form, disabled) {
    form.querySelectorAll('button, input, select, textarea').forEach((field) => {
        field.disabled = disabled;
    });
    form.setAttribute('aria-busy', disabled ? 'true' : 'false');
}

function clearAsyncFormErrors(form) {
    form.querySelectorAll('[data-async-error]').forEach((error) => error.remove());
    form.querySelectorAll('[data-async-invalid]').forEach((field) => {
        field.removeAttribute('data-async-invalid');
        field.classList.remove('border-rose-300', 'focus:border-rose-500', 'focus:ring-rose-100');
    });
}

function formControlByName(form, name) {
    return Array.from(form.elements).find((element) => element.name === name);
}

function renderAsyncFormErrors(form, errors) {
    clearAsyncFormErrors(form);

    Object.entries(errors).forEach(([field, messages]) => {
        const control = formControlByName(form, field);
        const container = control?.closest('[data-customer-autocomplete]') ?? control?.closest('label') ?? control?.parentElement ?? form;
        const message = Array.isArray(messages) ? messages[0] : messages;

        if (control) {
            control.dataset.asyncInvalid = 'true';
            control.classList.add('border-rose-300', 'focus:border-rose-500', 'focus:ring-rose-100');
        }

        if (message) {
            const error = document.createElement('span');
            error.dataset.asyncError = 'true';
            error.className = 'crm-help';
            error.textContent = message;
            container.append(error);
        }
    });

    const firstMessage = Object.values(errors)
        .flat()
        .find(Boolean);

    setAsyncStatus(firstMessage ?? fallbackAsyncMessage('validation'), 'error');
}

function replaceScheduledClassCard(cardHtml, fallbackCard) {
    const template = document.createElement('template');
    template.innerHTML = cardHtml.trim();
    const replacement = template.content.querySelector('[data-scheduled-class-card]');

    if (!replacement) {
        return;
    }

    const target = document.getElementById(replacement.id) ?? fallbackCard;
    target?.replaceWith(replacement);
    initCustomerAutocomplete(replacement);
    initPhoneMasks(replacement);
    createIcons({ icons });
}

function setPublicScheduleBusy(fragment, isBusy) {
    fragment.setAttribute('aria-busy', isBusy ? 'true' : 'false');
    fragment.classList.toggle('opacity-60', isBusy);
    fragment.classList.toggle('pointer-events-none', isBusy);
}

function replacePublicScheduleFragment(html) {
    const template = document.createElement('template');
    template.innerHTML = html.trim();
    const replacement = template.content.querySelector('[data-public-schedule-fragment]');
    const current = document.querySelector('[data-public-schedule-fragment]');

    if (!replacement || !current) {
        return null;
    }

    current.replaceWith(replacement);
    createIcons({ icons });

    return replacement;
}

async function loadPublicScheduleUrl(url, pushState = true) {
    const fragment = document.querySelector('[data-public-schedule-fragment]');

    if (!fragment) {
        window.location.href = url;
        return;
    }

    publicScheduleAbortController?.abort();
    const abortController = new AbortController();
    publicScheduleAbortController = abortController;
    setPublicScheduleBusy(fragment, true);

    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: abortController.signal,
        });

        if (!response.ok) {
            throw new Error('Public schedule request failed.');
        }

        const replacement = replacePublicScheduleFragment(await response.text());

        if (!replacement) {
            throw new Error('Public schedule fragment missing.');
        }

        if (pushState) {
            window.history.pushState({ publicSchedule: true }, '', url);
        }
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }

        window.location.href = url;
    } finally {
        if (publicScheduleAbortController !== abortController) {
            return;
        }

        publicScheduleAbortController = null;

        const current = document.querySelector('[data-public-schedule-fragment]');

        if (current) {
            setPublicScheduleBusy(current, false);
        }
    }
}

async function submitAsyncForm(form) {
    const fallbackCard = form.closest('[data-scheduled-class-card]');
    const formData = new FormData(form);

    clearAsyncFormErrors(form);
    setFormDisabled(form, true);

    try {
        const response = await fetch(form.action, {
            method: form.method.toUpperCase(),
            body: formData,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const payload = await response.json().catch(() => ({}));

        if (response.ok) {
            if (form.matches('[data-confirm-delete], [data-confirm-action]')) {
                const modal = document.getElementById('delete-confirmation-modal');

                if (modal) {
                    closeDeleteConfirmation(modal);
                }
            }

            replaceScheduledClassCard(payload.card_html ?? '', fallbackCard);
            setAsyncStatus(payload.message);
            return;
        }

        if (response.status === 422 && payload.errors) {
            renderAsyncFormErrors(form, payload.errors);
            return;
        }

        setAsyncStatus(payload.message ?? fallbackAsyncMessage(), 'error');
    } catch {
        setAsyncStatus(fallbackAsyncMessage(), 'error');
    } finally {
        delete form.dataset.confirmed;

        if (document.body.contains(form)) {
            setFormDisabled(form, false);
        }
    }
}

function initActiveScrollTargets() {
    document.querySelectorAll('[data-active-scroll-target]').forEach((element) => {
        element.scrollIntoView({ block: 'nearest', inline: 'center' });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    createIcons({ icons });
    initSlugAutofill();
    initColorPickers();
    initStudioRulesEditors();
    initCustomerAutocomplete();
    initCustomerAuthTabs();
    initPlatformSettingsTabs();
    initPhoneMasks();
    initOtpCountdowns();
    initPrintButtons();
    initManualClassModals();
    initQuickBookingModals();
    initCopyButtons();
    initActiveScrollTargets();

    if (document.querySelector('[data-public-schedule-fragment]')) {
        window.history.replaceState({ publicSchedule: true }, '', window.location.href);
    }

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

    document.querySelectorAll('form').forEach((form) => {
        updateClassPassScheduleKind(form);
    });

    document.addEventListener('click', (event) => {
        const selectAllButton = event.target.closest('[data-select-all-class-types]');

        if (!selectAllButton) {
            return;
        }

        const group = selectAllButton.closest('[data-class-type-group]');
        group?.querySelectorAll('[data-class-type-checkbox]:not(:disabled)').forEach((checkbox) => {
            checkbox.checked = true;
        });
    });

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-public-schedule-link]');

        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target) {
            return;
        }

        const url = new URL(link.href, window.location.href);

        if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) {
            return;
        }

        event.preventDefault();
        loadPublicScheduleUrl(url.toString());
    });

    window.addEventListener('popstate', () => {
        if (document.querySelector('[data-public-schedule-fragment]')) {
            loadPublicScheduleUrl(window.location.href, false);
        }
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

        const scheduleKindSelect = event.target.closest('[data-class-pass-schedule-kind]');

        if (scheduleKindSelect) {
            updateClassPassScheduleKind(scheduleKindSelect.closest('form'));
        }

        const classPassSegmentSelect = event.target.closest('[data-class-pass-segment]');

        if (classPassSegmentSelect) {
            updateClassPassScheduleKind(classPassSegmentSelect.closest('form'));
        }
    });

    const modal = document.getElementById('delete-confirmation-modal');
    const cancelButton = modal?.querySelector('[data-confirm-cancel]');
    const acceptButton = modal?.querySelector('[data-confirm-accept]');
    const confirmTitle = modal?.querySelector('[data-confirm-title]');
    const confirmBody = modal?.querySelector('[data-confirm-body]');
    const confirmIcon = modal?.querySelector('[data-confirm-icon]');

    const formNeedsConfirmation = (form) => form?.matches('[data-confirm-delete], [data-confirm-action]') && form.dataset.confirmed !== 'true';
    const applyConfirmationCopy = (form, submitter = null) => {
        const source = submitter?.dataset ? submitter : form;

        if (confirmTitle) {
            confirmTitle.textContent = source.dataset.confirmTitle || form.dataset.confirmTitle || confirmTitle.dataset.defaultText || confirmTitle.textContent;
        }

        if (confirmBody) {
            confirmBody.textContent = source.dataset.confirmBody || form.dataset.confirmBody || confirmBody.dataset.defaultText || confirmBody.textContent;
        }

        acceptButton.textContent = source.dataset.confirmAccept || form.dataset.confirmAccept || acceptButton.dataset.defaultText || acceptButton.textContent;

        const variant = source.dataset.confirmVariant || form.dataset.confirmVariant || 'danger';

        applyClassVariant(
            acceptButton,
            confirmationButtonVariantClassList,
            confirmationButtonVariants[variant] ?? confirmationButtonVariants.danger,
        );
        applyClassVariant(
            confirmIcon,
            confirmationIconVariantClassList,
            confirmationIconVariants[variant] ?? confirmationIconVariants.danger,
        );
        applyConfirmationIcon(confirmIcon, source.dataset.confirmIcon || form.dataset.confirmIcon || confirmIcon?.dataset.defaultIcon || 'trash-2');
    };

    if (!modal || !cancelButton || !acceptButton) {
        return;
    }

    document.addEventListener('submit', (event) => {
        const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
        const asyncForm = event.target.closest('form[data-async-form]');
        const isUnconfirmedAsyncConfirmation = formNeedsConfirmation(asyncForm);

        if (asyncForm && !isUnconfirmedAsyncConfirmation) {
            event.preventDefault();
            submitAsyncForm(asyncForm);
            return;
        }

        const form = event.target.closest('form[data-confirm-delete], form[data-confirm-action]');

        if (!form || form.dataset.confirmed === 'true') {
            return;
        }

        event.preventDefault();
        pendingDeleteForm = form;
        pendingConfirmationSubmitter = submitter?.form === form ? submitter : null;
        applyConfirmationCopy(form, pendingConfirmationSubmitter);
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
            closeManualClassModal(document.querySelector('[data-manual-class-modal]:not(.hidden)'));
        }

        if (event.key === 'Escape') {
            closeQuickBookingModal(document.querySelector('[data-quick-booking-modal]:not(.hidden)'));
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

        if (pendingConfirmationSubmitter && document.body.contains(pendingConfirmationSubmitter)) {
            pendingDeleteForm.requestSubmit(pendingConfirmationSubmitter);
            return;
        }

        pendingDeleteForm.requestSubmit();
    });
});
