import { createIcons, icons } from 'lucide';
import Panzoom from '@panzoom/panzoom';
import SimplePhoneMask from 'simple-phone-mask';
import 'summernote/dist/summernote-lite.css';
import { initEventScanner } from './event-scanner';

let pendingDeleteForm = null;
let pendingConfirmationSubmitter = null;
let pendingConfirmationPhrase = null;
let publicScheduleAbortController = null;
let publicCalendarSwipeStart = null;
let publicBookingModalOpener = null;
let trainerPrivateLessonsAbortController = null;
let trainerPermissionDetailsOpener = null;
let festivalAnnouncementModalOpener = null;
let festivalProgramModalOpener = null;

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

    const phraseContainer = modal.querySelector('[data-confirm-phrase-container]');
    const phraseInput = modal.querySelector('[data-confirm-phrase-input]');
    const acceptButton = modal.querySelector('[data-confirm-accept]');

    phraseContainer?.classList.add('hidden');

    if (phraseInput) {
        phraseInput.value = '';
    }

    if (acceptButton) {
        acceptButton.disabled = false;
    }

    pendingDeleteForm = null;
    pendingConfirmationSubmitter = null;
    pendingConfirmationPhrase = null;
}

function applyClassVariant(element, allVariantClasses, variantClasses) {
    if (!element) {
        return;
    }

    element.classList.remove(...allVariantClasses);
    element.classList.add(...variantClasses.split(' '));
}

function toggleClassTypeList(toggle) {
    const list = toggle.closest('[data-class-type-list]');

    if (!list) {
        return;
    }

    const expanded = toggle.getAttribute('aria-expanded') !== 'true';

    list.querySelectorAll('[data-class-type-list-extra]').forEach((classType) => {
        classType.classList.toggle('hidden', !expanded);
    });

    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');

    const label = toggle.querySelector('[data-class-type-list-toggle-label]');

    if (label) {
        label.textContent = expanded ? toggle.dataset.expandedLabel : toggle.dataset.collapsedLabel;
    }
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

function setSalaryFieldGroupEnabled(group, enabled) {
    if (!group) {
        return;
    }

    group.classList.toggle('hidden', !enabled);
    group.querySelectorAll('input, select, textarea, button').forEach((field) => {
        field.disabled = !enabled;
    });
}

function renameSalaryTierFields(rule) {
    const ruleIndex = rule.dataset.ruleIndex;

    rule.querySelectorAll('[data-salary-tier-row]').forEach((row, tierIndex) => {
        row.querySelectorAll('[data-salary-tier-field]').forEach((field) => {
            field.name = `rules[${ruleIndex}][tiers][${tierIndex}][${field.dataset.salaryTierField}]`;
        });
    });
}

function updateSalaryRule(rule, formulaDescriptions) {
    const overrideToggle = rule.querySelector('[data-salary-override-toggle]');
    const ruleFields = rule.querySelector('[data-salary-rule-fields]');
    const overrideEnabled = !overrideToggle || overrideToggle.checked;

    setSalaryFieldGroupEnabled(ruleFields, overrideEnabled);

    if (!overrideEnabled) {
        return;
    }

    const formula = rule.querySelector('[data-salary-formula]')?.value;
    const description = rule.querySelector('[data-salary-formula-description]');

    if (description) {
        description.textContent = formulaDescriptions[formula] || '';
    }

    rule.querySelectorAll('[data-salary-formula-fields]').forEach((group) => {
        setSalaryFieldGroupEnabled(group, group.dataset.salaryFormulaFields === formula);
    });
    renameSalaryTierFields(rule);
}

function initSalaryRuleTabs(form) {
    const container = form.querySelector('[data-salary-rule-tabs]');

    if (!container || container.dataset.salaryRuleTabsReady === 'true') {
        return;
    }

    const tabs = Array.from(container.querySelectorAll('[data-salary-rule-tab]'));
    const panels = Array.from(container.querySelectorAll('[data-salary-rule-panel]'));
    const activeTabInput = container.querySelector('[data-salary-rule-active-tab]');

    if (!tabs.length || !panels.length) {
        return;
    }

    container.dataset.salaryRuleTabsReady = 'true';

    const activate = (tabValue, focusTab = false) => {
        tabs.forEach((tab) => {
            const selected = tab.dataset.salaryRuleTab === tabValue;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;

            if (selected && focusTab) {
                tab.focus();
            }
        });

        panels.forEach((panel) => {
            panel.classList.toggle('hidden', panel.dataset.salaryRulePanel !== tabValue);
        });

        if (activeTabInput) {
            activeTabInput.value = tabValue;
        }
    };

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab.dataset.salaryRuleTab));
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
            activate(tabs[nextIndex].dataset.salaryRuleTab, true);
        });
    });

    const initialTab = tabs.some((tab) => tab.dataset.salaryRuleTab === container.dataset.activeTab)
        ? container.dataset.activeTab
        : tabs[0].dataset.salaryRuleTab;

    activate(initialTab);
}

function initSalaryModelForms() {
    document.querySelectorAll('[data-salary-model-form]').forEach((form) => {
        let formulaDescriptions = {};

        try {
            formulaDescriptions = JSON.parse(form.dataset.formulaDescriptions || '{}');
        } catch {
            formulaDescriptions = {};
        }

        initSalaryRuleTabs(form);

        const updateModelType = () => {
            const modelType = form.querySelector('[data-salary-model-type]:checked')?.value
                || form.querySelector('input[name="type"][type="hidden"]')?.value;
            const isFixed = modelType === 'fixed_period';

            setSalaryFieldGroupEnabled(form.querySelector('[data-salary-fixed-fields]'), isFixed);
            setSalaryFieldGroupEnabled(form.querySelector('[data-salary-per-class-fields]'), !isFixed);

            if (!isFixed) {
                form.querySelectorAll('[data-salary-rule]').forEach((rule) => {
                    updateSalaryRule(rule, formulaDescriptions);
                });
            }
        };

        form.querySelectorAll('[data-salary-rule]').forEach((rule) => {
            updateSalaryRule(rule, formulaDescriptions);

            rule.querySelector('[data-salary-formula]')?.addEventListener('change', () => {
                updateSalaryRule(rule, formulaDescriptions);
            });
            rule.querySelector('[data-salary-override-toggle]')?.addEventListener('change', () => {
                updateSalaryRule(rule, formulaDescriptions);
            });
            rule.querySelector('[data-salary-add-tier]')?.addEventListener('click', () => {
                const template = rule.querySelector('[data-salary-tier-template]');
                const rows = rule.querySelector('[data-salary-tier-rows]');

                if (!template || !rows) {
                    return;
                }

                rows.append(template.content.cloneNode(true));
                renameSalaryTierFields(rule);
            });
            rule.querySelector('[data-salary-tier-rows]')?.addEventListener('click', (event) => {
                const removeButton = event.target.closest('[data-salary-remove-tier]');

                if (!removeButton) {
                    return;
                }

                removeButton.closest('[data-salary-tier-row]')?.remove();
                renameSalaryTierFields(rule);
            });
        });

        form.querySelectorAll('[data-salary-model-type]').forEach((input) => {
            input.addEventListener('change', updateModelType);
        });
        updateModelType();
    });

    document.querySelectorAll('[data-salary-assignment-form]').forEach((form) => {
        form.querySelector('[data-salary-select-unassigned]')?.addEventListener('click', () => {
            form.querySelectorAll('[data-salary-trainer]').forEach((checkbox) => {
                checkbox.checked = checkbox.dataset.unassigned === 'true';
            });
        });
        form.querySelector('[data-salary-clear-trainers]')?.addEventListener('click', () => {
            form.querySelectorAll('[data-salary-trainer]').forEach((checkbox) => {
                checkbox.checked = false;
            });
        });
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
            const configuredHeight = Number.parseInt(editor.dataset.editorHeight || '420', 10);

            $(editor).summernote({
                height: Number.isNaN(configuredHeight) ? 420 : configuredHeight,
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
                    container.dispatchEvent(new CustomEvent('customer:selected', {
                        bubbles: true,
                        detail: { customer },
                    }));

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

function initTrainerMultiSelect(root = document) {
    root.querySelectorAll('[data-trainer-multi-select]').forEach((container) => {
        if (container.dataset.trainerMultiSelectReady === 'true') {
            return;
        }

        const valueSelect = container.querySelector('[data-trainer-multi-select-values]');
        const ui = container.querySelector('[data-trainer-multi-select-ui]');
        const input = container.querySelector('[data-trainer-multi-select-input]');
        const selectedContainer = container.querySelector('[data-trainer-multi-select-selected]');
        const results = container.querySelector('[data-trainer-multi-select-results]');
        const mainTrainerSelect = container.closest('form')?.querySelector('select[name="trainer_id"]');

        if (!valueSelect || !ui || !input || !selectedContainer || !results) {
            return;
        }

        container.dataset.trainerMultiSelectReady = 'true';
        valueSelect.classList.add('hidden');
        ui.classList.remove('hidden');

        const options = () => Array.from(valueSelect.options);
        const selectedOptions = () => options().filter((option) => option.selected);
        const mainTrainerId = () => mainTrainerSelect?.value ?? '';
        const notifyValueChange = () => valueSelect.dispatchEvent(new Event('change', { bubbles: true }));
        const hideResults = () => {
            results.classList.add('hidden');
            results.innerHTML = '';
        };

        const renderSelected = () => {
            selectedContainer.innerHTML = '';
            const selected = selectedOptions();
            selectedContainer.classList.toggle('hidden', selected.length === 0);

            selected.forEach((option) => {
                const chip = document.createElement('span');
                chip.className = 'inline-flex max-w-full items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3 py-1.5 text-sm font-semibold text-brand-800';

                const label = document.createElement('span');
                label.className = 'truncate';
                label.textContent = option.textContent;

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'inline-flex size-5 shrink-0 items-center justify-center rounded-full text-brand-700 hover:bg-brand-100 hover:text-brand-950';
                remove.setAttribute('aria-label', `${container.dataset.removeLabel ?? 'Remove'}: ${option.textContent}`);
                remove.textContent = '×';
                remove.addEventListener('click', () => {
                    option.selected = false;
                    notifyValueChange();
                    renderSelected();
                    renderResults();
                    input.focus();
                });

                chip.append(label, remove);
                selectedContainer.append(chip);
            });
        };

        const renderResults = () => {
            const term = input.value.trim().toLocaleLowerCase();
            const candidates = options().filter((option) => (
                !option.selected
                && option.value !== mainTrainerId()
                && option.textContent.toLocaleLowerCase().includes(term)
            ));

            results.innerHTML = '';

            if (candidates.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'px-3 py-2 text-sm text-slate-500';
                empty.textContent = container.dataset.noResults ?? 'No matching trainers.';
                results.append(empty);
            } else {
                candidates.forEach((option) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-brand-50 hover:text-slate-950';
                    button.setAttribute('role', 'option');
                    button.textContent = option.textContent;
                    button.addEventListener('click', () => {
                        option.selected = true;
                        notifyValueChange();
                        input.value = '';
                        renderSelected();
                        renderResults();
                        input.focus();
                    });
                    results.append(button);
                });
            }

            results.classList.remove('hidden');
        };

        const syncMainTrainer = () => {
            const selectedMain = options().find((option) => option.value === mainTrainerId());

            if (selectedMain?.selected) {
                selectedMain.selected = false;
                notifyValueChange();
            }

            renderSelected();

            if (!results.classList.contains('hidden')) {
                renderResults();
            }
        };

        input.addEventListener('focus', renderResults);
        input.addEventListener('input', renderResults);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                hideResults();
            }
        });
        mainTrainerSelect?.addEventListener('change', syncMainTrainer);
        document.addEventListener('click', (event) => {
            if (!container.contains(event.target)) {
                hideResults();
            }
        });

        syncMainTrainer();
    });
}

function initStudioLoginPickers(root = document) {
    root.querySelectorAll('[data-studio-login-picker]').forEach((container) => {
        if (container.dataset.studioLoginPickerReady === 'true') {
            return;
        }

        const input = container.querySelector('[data-studio-login-picker-input]');
        const results = container.querySelector('[data-studio-login-picker-results]');
        const emptyResult = container.querySelector('[data-studio-login-picker-empty]');
        const page = container.closest('main') ?? document;
        const cards = Array.from(page.querySelectorAll('[data-studio-login-picker-card]'));
        const gridEmpty = page.querySelector('[data-studio-login-picker-grid-empty]');
        const options = Array.from(container.querySelectorAll('[data-studio-login-picker-option]'));

        if (!input || !results) {
            return;
        }

        container.dataset.studioLoginPickerReady = 'true';

        const normalized = (value) => value.trim().toLocaleLowerCase();
        const matchesTerm = (element, term) => term === '' || (element.dataset.studioSearchText || '').includes(term);
        const visibleOptions = () => options.filter((option) => !option.classList.contains('hidden'));

        const setResultsVisible = (isVisible) => {
            results.classList.toggle('hidden', !isVisible);
            input.setAttribute('aria-expanded', isVisible ? 'true' : 'false');
        };

        const render = (showResults = false) => {
            const term = normalized(input.value);
            let optionCount = 0;
            let cardCount = 0;

            options.forEach((option) => {
                const isVisible = matchesTerm(option, term);

                option.classList.toggle('hidden', !isVisible);

                if (isVisible) {
                    optionCount += 1;
                }
            });

            cards.forEach((card) => {
                const isVisible = matchesTerm(card, term);

                card.classList.toggle('hidden', !isVisible);

                if (isVisible) {
                    cardCount += 1;
                }
            });

            emptyResult?.classList.toggle('hidden', optionCount > 0);
            gridEmpty?.classList.toggle('hidden', cardCount > 0);
            setResultsVisible(showResults && (optionCount > 0 || Boolean(emptyResult)));
        };

        input.addEventListener('focus', () => render(true));
        input.addEventListener('input', () => render(true));
        input.addEventListener('keydown', (event) => {
            const firstOption = visibleOptions()[0];

            if (event.key === 'ArrowDown' && firstOption) {
                event.preventDefault();
                firstOption.focus();
                return;
            }

            if (event.key === 'Enter' && firstOption) {
                event.preventDefault();
                window.location.href = firstOption.href;
            }
        });

        options.forEach((option) => {
            option.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    setResultsVisible(false);
                    input.focus();
                }
            });
        });

        document.addEventListener('click', (event) => {
            if (!container.contains(event.target)) {
                setResultsVisible(false);
            }
        });

        render(false);
    });
}

function initClassPassPreviews(root = document) {
    root.querySelectorAll('[data-class-pass-preview-url]').forEach((form) => {
        if (form.dataset.classPassPreviewReady === 'true') {
            return;
        }

        const output = form.querySelector('[data-class-pass-preview]');
        const previewUrl = form.dataset.classPassPreviewUrl;

        if (!output || !previewUrl) {
            return;
        }

        form.dataset.classPassPreviewReady = 'true';

        form.addEventListener('customer:selected', async (event) => {
            const customerId = event.detail?.customer?.id;

            if (!customerId) {
                return;
            }

            const url = new URL(previewUrl, window.location.origin);
            url.searchParams.set('customer_id', customerId);
            output.textContent = output.dataset.loading || 'Checking class pass...';
            output.classList.remove('border-rose-200', 'bg-rose-50', 'text-rose-700', 'border-emerald-200', 'bg-emerald-50', 'text-emerald-800');
            output.classList.add('border-slate-200', 'bg-slate-50', 'text-slate-600');

            try {
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const payload = await response.json();

                output.classList.remove('border-slate-200', 'bg-slate-50', 'text-slate-600');

                if (response.ok && payload.pass) {
                    output.textContent = `${payload.message}: ${payload.pass.code} · ${payload.pass.plan_name} · ${payload.pass.remaining_sessions}`;
                    output.classList.add('border-emerald-200', 'bg-emerald-50', 'text-emerald-800');
                    return;
                }

                output.textContent = payload.message || 'No matching class pass.';
                output.classList.add('border-rose-200', 'bg-rose-50', 'text-rose-700');
            } catch {
                output.textContent = output.dataset.error || 'Could not check class pass.';
                output.classList.remove('border-slate-200', 'bg-slate-50', 'text-slate-600');
                output.classList.add('border-rose-200', 'bg-rose-50', 'text-rose-700');
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

function initFestivalRegistrantProfiles() {
    document.querySelectorAll('[data-festival-registrant-type]').forEach((select) => {
        const form = select.closest('form');
        const input = form?.querySelector('[data-participant-required-input]');
        const marker = form?.querySelector('[data-participant-required-marker]');

        const sync = () => {
            const isParticipant = select.value === 'adult_athlete';
            input?.toggleAttribute('required', isParticipant);
            marker?.classList.toggle('hidden', !isParticipant);
        };

        select.addEventListener('change', sync);
        sync();
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

function initTrainerFormTabs(root = document) {
    root.querySelectorAll('[data-trainer-form-tabs]').forEach((container) => {
        if (container.dataset.trainerFormTabsReady === 'true') {
            return;
        }

        const tabs = Array.from(container.querySelectorAll('[data-trainer-form-tab]'));
        const panels = Array.from(container.querySelectorAll('[data-trainer-form-panel]'));
        const activeTabInput = container.querySelector('[data-trainer-form-active-tab]');

        if (!tabs.length || !panels.length) {
            return;
        }

        container.dataset.trainerFormTabsReady = 'true';

        const activate = (tabName, focusTab = false) => {
            tabs.forEach((tab) => {
                const selected = tab.dataset.trainerFormTab === tabName;
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.tabIndex = selected ? 0 : -1;

                if (selected && focusTab) {
                    tab.focus();
                }
            });

            panels.forEach((panel) => {
                panel.classList.toggle('hidden', panel.dataset.trainerFormPanel !== tabName);
            });

            if (activeTabInput) {
                activeTabInput.value = tabName;
            }
        };

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => {
                if (tab.dataset.trainerFormTab) {
                    activate(tab.dataset.trainerFormTab);
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

                const tabName = tabs[nextIndex]?.dataset.trainerFormTab;

                if (tabName) {
                    activate(tabName, true);
                }
            });
        });

        let invalidPanelActivated = false;
        const form = container.closest('form');

        form?.addEventListener('invalid', (event) => {
            if (invalidPanelActivated) {
                return;
            }

            const invalidPanel = event.target.closest('[data-trainer-form-panel]');

            if (!invalidPanel?.dataset.trainerFormPanel) {
                return;
            }

            invalidPanelActivated = true;
            activate(invalidPanel.dataset.trainerFormPanel);
            window.setTimeout(() => {
                invalidPanelActivated = false;
            }, 0);
        }, true);

        const initialTab = tabs.some((tab) => tab.dataset.trainerFormTab === container.dataset.activeTab)
            ? container.dataset.activeTab
            : tabs[0]?.dataset.trainerFormTab;

        if (initialTab) {
            activate(initialTab);
        }
    });
}

function initPublicPriceTabSet(container, tabSelector, panelSelector, activeValue) {
    if (container.dataset.publicPriceTabsReady === 'true') {
        return;
    }

    const tabs = Array.from(container.querySelectorAll(tabSelector));
    const panels = Array.from(container.querySelectorAll(panelSelector));

    if (!tabs.length || !panels.length) {
        return;
    }

    container.dataset.publicPriceTabsReady = 'true';

    const tabAttribute = tabSelector.slice(1, -1);
    const panelAttribute = panelSelector.slice(1, -1);
    const valueFor = (element, attribute) => element.getAttribute(attribute);

    const activate = (tabValue, focusTab = false) => {
        tabs.forEach((tab) => {
            const selected = valueFor(tab, tabAttribute) === tabValue;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;

            if (selected && focusTab) {
                tab.focus();
            }
        });

        panels.forEach((panel) => {
            panel.classList.toggle('hidden', valueFor(panel, panelAttribute) !== tabValue);
        });
    };

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => {
            const tabValue = valueFor(tab, tabAttribute);

            if (tabValue) {
                activate(tabValue);
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

            const tabValue = tabs[nextIndex] ? valueFor(tabs[nextIndex], tabAttribute) : null;

            if (tabValue) {
                activate(tabValue, true);
            }
        });
    });

    const initialValue = tabs.some((tab) => valueFor(tab, tabAttribute) === activeValue)
        ? activeValue
        : valueFor(tabs[0], tabAttribute);

    if (initialValue) {
        activate(initialValue);
    }
}

function initPublicPriceTabs(root = document) {
    root.querySelectorAll('[data-public-price-kind-tabs]').forEach((container) => {
        initPublicPriceTabSet(
            container,
            '[data-public-price-kind-tab]',
            '[data-public-price-kind-panel]',
            container.dataset.activeKind,
        );
    });

    root.querySelectorAll('[data-public-price-segment-tabs]').forEach((container) => {
        initPublicPriceTabSet(
            container,
            '[data-public-price-segment-tab]',
            '[data-public-price-segment-panel]',
            container.dataset.activeSection,
        );
    });
}

function classPassSortItems(section) {
    return Array.from(section.querySelectorAll('[data-class-pass-sort-item]'));
}

function classPassSortPlanIds(section) {
    return classPassSortItems(section).map((item) => item.dataset.planId);
}

function restoreClassPassSortOrder(section, planIds) {
    const list = section.querySelector('[data-class-pass-sort-list]');
    const itemsById = new Map(classPassSortItems(section).map((item) => [item.dataset.planId, item]));

    planIds.forEach((planId) => {
        const item = itemsById.get(String(planId));

        if (item) {
            list?.append(item);
        }
    });
}

function syncClassPassSortInputs(section) {
    const form = section.querySelector('[data-class-pass-reorder-form]');

    if (!form) {
        return;
    }

    form.querySelectorAll('input[name="plan_ids[]"]').forEach((input) => input.remove());
    classPassSortPlanIds(section).forEach((planId) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'plan_ids[]';
        input.value = planId;
        form.append(input);
    });
}

function syncClassPassSortControls(section) {
    const items = classPassSortItems(section);
    const multipleItems = items.length > 1;

    items.forEach((item, index) => {
        const handle = item.querySelector('[data-class-pass-sort-handle]');
        const upButton = item.querySelector('[data-class-pass-sort-up]');
        const downButton = item.querySelector('[data-class-pass-sort-down]');

        if (handle) {
            handle.disabled = !multipleItems;
            handle.draggable = multipleItems;
        }

        if (upButton) {
            upButton.disabled = index === 0;
        }

        if (downButton) {
            downButton.disabled = index === items.length - 1;
        }
    });
}

function setClassPassSortBusy(section, busy) {
    section.querySelectorAll('[data-class-pass-sort-control]').forEach((control) => {
        if (busy) {
            control.dataset.classPassSortWasDisabled = control.disabled ? 'true' : 'false';
            control.disabled = true;
            return;
        }

        control.disabled = control.dataset.classPassSortWasDisabled === 'true';
        delete control.dataset.classPassSortWasDisabled;
    });
    section.setAttribute('aria-busy', busy ? 'true' : 'false');

    if (!busy) {
        syncClassPassSortControls(section);
    }
}

function classPassSortErrorMessage(payload, form) {
    const firstValidationMessage = payload.errors
        ? Object.values(payload.errors).flat().find(Boolean)
        : null;

    return firstValidationMessage ?? payload.message ?? fallbackAsyncMessage('error', form);
}

async function saveClassPassSortOrder(section, previousPlanIds) {
    const form = section.querySelector('[data-class-pass-reorder-form]');

    if (!form) {
        return;
    }

    syncClassPassSortInputs(section);
    clearAsyncFormErrors(form);
    const formData = new FormData(form);
    setClassPassSortBusy(section, true);

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

        if (!response.ok) {
            restoreClassPassSortOrder(section, previousPlanIds);
            syncClassPassSortInputs(section);
            setAsyncStatus(classPassSortErrorMessage(payload, form), 'error', form);
            return;
        }

        Object.entries(payload.sort_orders ?? {}).forEach(([planId, sortOrder]) => {
            const item = section.querySelector(`[data-class-pass-sort-item][data-plan-id="${planId}"]`);
            const value = item?.querySelector('[data-class-pass-sort-order-value]');

            if (value) {
                value.textContent = sortOrder;
            }
        });
        setAsyncStatus(payload.message, 'success', form);
    } catch {
        restoreClassPassSortOrder(section, previousPlanIds);
        syncClassPassSortInputs(section);
        setAsyncStatus(fallbackAsyncMessage('error', form), 'error', form);
    } finally {
        setClassPassSortBusy(section, false);
    }
}

function initClassPassPlanSorting(root = document) {
    root.querySelectorAll('[data-class-pass-sort-section]').forEach((section) => {
        if (section.dataset.classPassSortReady === 'true') {
            return;
        }

        const list = section.querySelector('[data-class-pass-sort-list]');

        if (!list) {
            return;
        }

        section.dataset.classPassSortReady = 'true';
        let draggedItem = null;
        let previousPlanIds = [];
        let dropHandled = false;

        section.querySelectorAll('[data-class-pass-sort-handle]').forEach((handle) => {
            handle.addEventListener('dragstart', (event) => {
                if (handle.disabled) {
                    event.preventDefault();
                    return;
                }

                draggedItem = handle.closest('[data-class-pass-sort-item]');
                previousPlanIds = classPassSortPlanIds(section);
                dropHandled = false;
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', draggedItem?.dataset.planId ?? '');
                draggedItem?.classList.add('opacity-50', 'ring-2', 'ring-violet-crm-300');
            });

            handle.addEventListener('dragend', () => {
                if (!dropHandled && draggedItem) {
                    restoreClassPassSortOrder(section, previousPlanIds);
                }

                draggedItem?.classList.remove('opacity-50', 'ring-2', 'ring-violet-crm-300');
                draggedItem = null;
                dropHandled = false;
                syncClassPassSortControls(section);
            });
        });

        list.addEventListener('dragover', (event) => {
            if (!draggedItem) {
                return;
            }

            const target = event.target.closest('[data-class-pass-sort-item]');

            if (!target || target === draggedItem || target.parentElement !== list) {
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            const targetBox = target.getBoundingClientRect();
            const insertBeforeTarget = event.clientY < targetBox.top + (targetBox.height / 2);
            list.insertBefore(draggedItem, insertBeforeTarget ? target : target.nextElementSibling);
        });

        list.addEventListener('drop', async (event) => {
            if (!draggedItem) {
                return;
            }

            event.preventDefault();
            dropHandled = true;
            const currentPlanIds = classPassSortPlanIds(section);

            if (currentPlanIds.join(',') !== previousPlanIds.join(',')) {
                syncClassPassSortControls(section);
                await saveClassPassSortOrder(section, previousPlanIds);
            }
        });

        section.addEventListener('click', async (event) => {
            const upButton = event.target.closest('[data-class-pass-sort-up]');
            const downButton = event.target.closest('[data-class-pass-sort-down]');
            const button = upButton ?? downButton;

            if (!button || button.disabled) {
                return;
            }

            const item = button.closest('[data-class-pass-sort-item]');
            const sibling = upButton ? item?.previousElementSibling : item?.nextElementSibling;

            if (!item || !sibling) {
                return;
            }

            const originalPlanIds = classPassSortPlanIds(section);

            if (upButton) {
                list.insertBefore(item, sibling);
            } else {
                list.insertBefore(sibling, item);
            }

            syncClassPassSortControls(section);
            await saveClassPassSortOrder(section, originalPlanIds);
        });

        syncClassPassSortControls(section);
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

            container.dispatchEvent(new CustomEvent('platform-settings:tab-activated', {
                bubbles: true,
                detail: { tabName },
            }));
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

function initOwnerVoiceSettings(root = document) {
    root.querySelectorAll('[data-owner-voice-settings]').forEach((settings) => {
        if (settings.dataset.ownerVoiceSettingsReady === 'true') {
            return;
        }

        const form = settings.closest('form');
        const ownerAssistantToggle = form?.querySelector('[data-owner-ai-assistant-toggle]');
        const voiceInputToggle = settings.querySelector('[data-owner-voice-input-toggle]');
        const voiceProvider = settings.querySelector('[data-owner-voice-provider]');
        const hiddenVoiceProvider = settings.querySelector('input[type="hidden"][name="owner_voice_recognition_provider"]');

        if (!ownerAssistantToggle || !voiceInputToggle || !voiceProvider || !hiddenVoiceProvider) {
            return;
        }

        const sync = () => {
            const assistantEnabled = ownerAssistantToggle.checked;
            const providerAvailable = voiceProvider.value === 'openai';

            if (!assistantEnabled || !providerAvailable) {
                voiceInputToggle.checked = false;
            }

            voiceInputToggle.disabled = !assistantEnabled || !providerAvailable;
            voiceProvider.disabled = !assistantEnabled;
            hiddenVoiceProvider.value = voiceProvider.value;
            settings.classList.toggle('opacity-60', !assistantEnabled);
        };

        ownerAssistantToggle.addEventListener('change', sync);
        voiceProvider.addEventListener('change', sync);
        settings.dataset.ownerVoiceSettingsReady = 'true';
        sync();
    });
}

function initAiProviderModels(root = document) {
    root.querySelectorAll('[data-ai-models-url]').forEach((container) => {
        if (container.dataset.aiModelsReady === 'true') {
            return;
        }

        const activeProvider = container.querySelector('[data-ai-active-provider]');
        const url = container.dataset.aiModelsUrl;

        if (!activeProvider || !url) {
            return;
        }

        container.dataset.aiModelsReady = 'true';

        const loadedProviders = new Set();

        const statusFor = (provider) => container.querySelector(`[data-ai-model-status="${provider}"]`);
        const selectFor = (provider) => container.querySelector(`[data-ai-model-select="${provider}"]`);

        const setStatus = (provider, message = '') => {
            const status = statusFor(provider);

            if (status) {
                status.textContent = message;
            }
        };

        const setOptions = (select, models) => {
            const current = select.value || select.dataset.currentModel || '';
            select.innerHTML = '';

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = select.closest('form')?.dataset.aiModelPlaceholder || '';
            select.append(placeholder);

            const hasCurrent = current && models.includes(current);

            if (current && !hasCurrent) {
                const option = document.createElement('option');
                option.value = current;
                option.textContent = current;
                select.append(option);
            }

            models.forEach((model) => {
                const option = document.createElement('option');
                option.value = model;
                option.textContent = model;
                select.append(option);
            });

            select.value = current;
        };

        const loadProvider = (provider, force = false) => {
            const select = selectFor(provider);

            if (!provider || !select || (!force && loadedProviders.has(provider))) {
                return;
            }

            loadedProviders.add(provider);
            select.disabled = true;
            setStatus(provider, container.dataset.aiModelLoading || '');

            const requestUrl = new URL(url, window.location.origin);
            requestUrl.searchParams.set('provider', provider);

            fetch(requestUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
                .then(async (response) => {
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        throw new Error(payload.message || container.dataset.aiModelFailed || 'Failed to load models');
                    }

                    return payload;
                })
                .then((payload) => {
                    const models = Array.isArray(payload.models) ? payload.models : [];
                    setOptions(select, models);
                    setStatus(provider, payload.message || (models.length ? '' : container.dataset.aiModelEmpty || ''));
                })
                .catch((error) => {
                    loadedProviders.delete(provider);
                    setStatus(provider, error.message || container.dataset.aiModelFailed || '');
                })
                .finally(() => {
                    select.disabled = false;
                });
        };

        activeProvider.addEventListener('change', () => loadProvider(activeProvider.value));
        container.querySelectorAll('[data-ai-model-refresh]').forEach((button) => {
            button.addEventListener('click', () => loadProvider(button.dataset.aiModelRefresh, true));
        });
        container.addEventListener('platform-settings:tab-activated', (event) => {
            if (event.detail?.tabName === 'ai-owner') {
                loadProvider(activeProvider.value);
            }
        });

        if (!container.querySelector('[data-platform-settings-panel="ai-owner"]')?.classList.contains('hidden')) {
            loadProvider(activeProvider.value);
        }
    });
}

function initPlatformTelegramWebhook(root = document) {
    root.querySelectorAll('[data-telegram-webhook-status-url]').forEach((container) => {
        if (container.dataset.telegramWebhookReady === 'true') {
            return;
        }

        const panel = container.querySelector('[data-telegram-webhook-panel]');
        const statusUrl = container.dataset.telegramWebhookStatusUrl;
        const registerUrl = container.dataset.telegramWebhookRegisterUrl;
        const deleteUrl = container.dataset.telegramWebhookDeleteUrl;
        const csrfToken = container.querySelector('input[name="_token"]')?.value || '';

        if (!panel || !statusUrl || !registerUrl || !deleteUrl || !csrfToken) {
            return;
        }

        container.dataset.telegramWebhookReady = 'true';

        const summary = panel.querySelector('[data-telegram-webhook-summary]');
        const localStatus = panel.querySelector('[data-telegram-webhook-local]');
        const liveStatus = panel.querySelector('[data-telegram-webhook-live]');
        const syncedAt = panel.querySelector('[data-telegram-webhook-synced]');
        const pendingUpdates = panel.querySelector('[data-telegram-webhook-pending]');
        const registeredUrl = panel.querySelector('[data-telegram-webhook-url]');
        const errorRow = panel.querySelector('[data-telegram-webhook-error-row]');
        const errorText = panel.querySelector('[data-telegram-webhook-error]');
        const refreshButton = panel.querySelector('[data-telegram-webhook-refresh]');
        const registerButton = panel.querySelector('[data-telegram-webhook-register]');
        const deleteButton = panel.querySelector('[data-telegram-webhook-delete]');
        let loaded = false;

        const requestJson = (url, options = {}) => fetch(url, {
            ...options,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.headers || {}),
            },
        }).then(async (response) => {
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || container.dataset.telegramWebhookStatusFailed || 'Request failed');
            }

            return payload;
        });

        const setBusy = (busy) => {
            [refreshButton, registerButton, deleteButton].forEach((button) => {
                button?.toggleAttribute('disabled', busy);
                button?.classList.toggle('opacity-60', busy);
            });
        };

        const formatDate = (value) => {
            if (!value) {
                return '—';
            }

            const date = new Date(value);

            return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
        };

        const liveMessage = (telegram) => {
            if (!telegram?.checked) {
                return telegram?.message || container.dataset.telegramWebhookUnknown || '';
            }

            if (!telegram.ok) {
                return telegram.message || container.dataset.telegramWebhookStatusFailed || '';
            }

            if (telegram.url_matches) {
                return container.dataset.telegramWebhookRegistered || '';
            }

            if (telegram.is_registered) {
                return container.dataset.telegramWebhookUrlMismatch || '';
            }

            return container.dataset.telegramWebhookNotRegistered || '';
        };

        const render = (payload) => {
            const status = payload.status || payload;
            const local = status.local || {};
            const telegram = status.telegram || {};
            const message = payload.message || liveMessage(telegram);
            const hasError = Boolean(telegram.checked && (telegram.last_error_message || (!telegram.ok && telegram.message)));

            if (summary) {
                summary.textContent = message || container.dataset.telegramWebhookUnknown || '';
                summary.classList.toggle('text-emerald-700', Boolean(telegram.url_matches));
                summary.classList.toggle('text-amber-700', Boolean(telegram.checked && telegram.ok && !telegram.url_matches));
                summary.classList.toggle('text-rose-700', Boolean(telegram.checked && !telegram.ok));
            }

            if (localStatus) {
                localStatus.textContent = local.status_label || local.status || '—';
            }

            if (liveStatus) {
                liveStatus.textContent = liveMessage(telegram) || '—';
            }

            if (syncedAt) {
                syncedAt.textContent = formatDate(local.last_webhook_synced_at);
            }

            if (pendingUpdates) {
                pendingUpdates.textContent = telegram.pending_update_count ?? '—';
            }

            if (registeredUrl) {
                registeredUrl.textContent = telegram.url || '—';
            }

            if (errorRow && errorText) {
                errorRow.classList.toggle('hidden', !hasError);
                errorText.textContent = telegram.last_error_message || telegram.message || '';
            }
        };

        const loadStatus = (force = false) => {
            if (loaded && !force) {
                return;
            }

            loaded = true;
            setBusy(true);

            if (summary) {
                summary.textContent = container.dataset.telegramWebhookLoading || '';
            }

            requestJson(statusUrl)
                .then(render)
                .catch((error) => {
                    if (summary) {
                        summary.textContent = error.message || container.dataset.telegramWebhookStatusFailed || '';
                        summary.classList.add('text-rose-700');
                    }
                })
                .finally(() => setBusy(false));
        };

        const runAction = (url, method) => {
            setBusy(true);

            if (summary) {
                summary.textContent = container.dataset.telegramWebhookLoading || '';
            }

            requestJson(url, { method, body: '{}' })
                .then(render)
                .catch((error) => {
                    if (summary) {
                        summary.textContent = error.message || container.dataset.telegramWebhookStatusFailed || '';
                        summary.classList.add('text-rose-700');
                    }
                })
                .finally(() => setBusy(false));
        };

        refreshButton?.addEventListener('click', () => loadStatus(true));
        registerButton?.addEventListener('click', () => runAction(registerUrl, 'POST'));
        deleteButton?.addEventListener('click', () => runAction(deleteUrl, 'DELETE'));

        container.addEventListener('platform-settings:tab-activated', (event) => {
            if (event.detail?.tabName === 'ai-owner') {
                loadStatus();
            }
        });

        if (!container.querySelector('[data-platform-settings-panel="ai-owner"]')?.classList.contains('hidden')) {
            loadStatus();
        }
    });
}

function selectPrintSection(printableSection = document.querySelector('[data-print-section]')) {
    if (!printableSection) {
        return;
    }

    document.querySelectorAll('[data-print-section]').forEach((section) => {
        if (section === printableSection) {
            delete section.dataset.printHidden;

            return;
        }

        section.dataset.printHidden = 'true';
    });
}

function initPrintButtons() {
    document.querySelectorAll('[data-print-button]').forEach((button) => {
        if (button.dataset.printReady === 'true') {
            return;
        }

        button.dataset.printReady = 'true';
        button.addEventListener('click', () => {
            selectPrintSection(button.closest('[data-print-section]'));
            window.print();
        });
    });
}

function initPeopleCounterMaskEditors(root = document) {
    root.querySelectorAll('[data-people-counter-mask-editor]').forEach((editor) => {
        if (editor.dataset.peopleCounterMaskReady === 'true') {
            return;
        }

        const form = editor.closest('form');
        const input = form?.querySelector('[data-people-counter-mask-input]');
        const image = editor.querySelector('[data-people-counter-mask-image]');
        const canvas = editor.querySelector('[data-people-counter-mask-canvas]');
        const stage = editor.querySelector('[data-people-counter-mask-stage]');
        const finishButton = editor.querySelector('[data-people-counter-mask-finish]');
        const undoButton = editor.querySelector('[data-people-counter-mask-undo]');
        const clearButton = editor.querySelector('[data-people-counter-mask-clear]');
        const context = canvas?.getContext('2d');

        if (!form || !input || !image || !canvas || !context || !stage) {
            return;
        }

        editor.dataset.peopleCounterMaskReady = 'true';

        const clamp = (value) => Math.max(0, Math.min(1, value));
        const normalizePoint = (point) => ({
            x: Number.parseFloat(clamp(point.x).toFixed(6)),
            y: Number.parseFloat(clamp(point.y).toFixed(6)),
        });
        const parsePolygons = () => {
            try {
                const decoded = JSON.parse(input.value || '[]');

                if (!Array.isArray(decoded)) {
                    return [];
                }

                return decoded
                    .map((polygon) => (Array.isArray(polygon?.points) ? polygon.points : polygon))
                    .filter(Array.isArray)
                    .map((points) => points
                        .filter((point) => Number.isFinite(Number(point?.x)) && Number.isFinite(Number(point?.y)))
                        .map((point) => normalizePoint({ x: Number(point.x), y: Number(point.y) })))
                    .filter((points) => points.length >= 3);
            } catch {
                return [];
            }
        };

        let polygons = parsePolygons();
        let draft = [];

        const syncInput = () => {
            input.value = JSON.stringify(polygons.map((points) => ({ points })));
        };

        const canvasRect = () => canvas.getBoundingClientRect();
        const pointToPixels = (point, rect = canvasRect()) => ({
            x: point.x * rect.width,
            y: point.y * rect.height,
        });
        const pointerPoint = (event) => {
            const rect = canvasRect();

            return normalizePoint({
                x: (event.clientX - rect.left) / Math.max(1, rect.width),
                y: (event.clientY - rect.top) / Math.max(1, rect.height),
            });
        };

        const drawPoint = (point, radius = 4) => {
            const pixel = pointToPixels(point);

            context.beginPath();
            context.arc(pixel.x, pixel.y, radius, 0, Math.PI * 2);
            context.fillStyle = '#ffffff';
            context.fill();
            context.lineWidth = 2;
            context.strokeStyle = '#dc2626';
            context.stroke();
        };

        const drawPolygon = (points, closed) => {
            if (points.length === 0) {
                return;
            }

            context.beginPath();
            points.forEach((point, index) => {
                const pixel = pointToPixels(point);

                if (index === 0) {
                    context.moveTo(pixel.x, pixel.y);
                    return;
                }

                context.lineTo(pixel.x, pixel.y);
            });

            if (closed) {
                context.closePath();
                context.fillStyle = 'rgba(220, 38, 38, 0.28)';
                context.fill();
            }

            context.lineWidth = 2;
            context.strokeStyle = closed ? '#dc2626' : '#f97316';
            context.setLineDash(closed ? [] : [8, 6]);
            context.stroke();
            context.setLineDash([]);
            points.forEach((point) => drawPoint(point, closed ? 4 : 5));
        };

        const draw = () => {
            const rect = canvasRect();

            context.clearRect(0, 0, rect.width, rect.height);
            polygons.forEach((polygon) => drawPolygon(polygon, true));
            drawPolygon(draft, false);
        };

        const resizeCanvas = () => {
            const rect = image.getBoundingClientRect();
            const ratio = window.devicePixelRatio || 1;
            const width = Math.max(1, Math.round(rect.width * ratio));
            const height = Math.max(1, Math.round(rect.height * ratio));

            if (canvas.width !== width || canvas.height !== height) {
                canvas.width = width;
                canvas.height = height;
            }

            canvas.style.width = `${rect.width}px`;
            canvas.style.height = `${rect.height}px`;
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            draw();
        };

        const finishDraft = () => {
            if (draft.length < 3) {
                return false;
            }

            polygons = [...polygons, draft];
            draft = [];
            syncInput();
            draw();

            return true;
        };

        const isClosingDraft = (point) => {
            if (draft.length < 3) {
                return false;
            }

            const first = pointToPixels(draft[0]);
            const current = pointToPixels(point);

            return Math.hypot(first.x - current.x, first.y - current.y) <= 16;
        };

        canvas.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) {
                return;
            }

            event.preventDefault();
            canvas.setPointerCapture?.(event.pointerId);

            const point = pointerPoint(event);

            if (isClosingDraft(point)) {
                finishDraft();
                return;
            }

            draft = [...draft, point];
            draw();
        });

        canvas.addEventListener('dblclick', (event) => {
            event.preventDefault();
            finishDraft();
        });

        finishButton?.addEventListener('click', () => finishDraft());
        undoButton?.addEventListener('click', () => {
            if (draft.length > 0) {
                draft = draft.slice(0, -1);
            } else {
                polygons = polygons.slice(0, -1);
                syncInput();
            }

            draw();
        });
        clearButton?.addEventListener('click', () => {
            polygons = [];
            draft = [];
            syncInput();
            draw();
        });
        form.addEventListener('submit', () => {
            if (!finishDraft()) {
                draft = [];
                draw();
            }

            syncInput();
        });

        if (image.complete) {
            resizeCanvas();
        } else {
            image.addEventListener('load', resizeCanvas, { once: true });
        }

        if ('ResizeObserver' in window) {
            new ResizeObserver(resizeCanvas).observe(stage);
        } else {
            window.addEventListener('resize', resizeCanvas);
        }

        syncInput();
        draw();
    });
}

window.addEventListener('beforeprint', () => {
    const hasSelectedPrintSection = Array.from(document.querySelectorAll('[data-print-section]'))
        .some((section) => section.dataset.printHidden === 'true');

    if (!hasSelectedPrintSection) {
        selectPrintSection();
    }
});

window.addEventListener('afterprint', () => {
    document.querySelectorAll('[data-print-section][data-print-hidden]').forEach((section) => {
        delete section.dataset.printHidden;
    });
});

const manualTrainerOverlapAvailabilitySelector = [
    '[name="location_id"]',
    '[name="room_id"]',
    '[name="class_type_id"]',
    '[name="trainer_id"]',
    '[name="additional_trainer_ids[]"]',
    '[name="starts_at"]',
    '[name="duration_minutes"]',
].join(', ');

function resetManualTrainerOverlapConfirmation(form) {
    const confirmation = form?.querySelector('[data-manual-trainer-overlap-confirmation]');

    if (!confirmation) {
        return;
    }

    const wasVisible = !confirmation.classList.contains('hidden');
    const checkbox = confirmation.querySelector('[data-manual-trainer-overlap-checkbox]');

    confirmation.classList.add('hidden');

    if (checkbox) {
        checkbox.checked = false;
        checkbox.removeAttribute('data-async-invalid');
        checkbox.classList.remove('border-rose-300', 'focus:border-rose-500', 'focus:ring-rose-100');
    }

    confirmation.querySelectorAll('[data-async-error]').forEach((error) => error.remove());

    if (wasVisible) {
        const status = asyncStatusElement(form);

        if (status) {
            status.textContent = '';
            status.classList.add('hidden');
        }
    }
}

function revealManualTrainerOverlapConfirmation(form, errors) {
    if (!Object.prototype.hasOwnProperty.call(errors, 'confirm_trainer_overlap')) {
        return;
    }

    form.querySelector('[data-manual-trainer-overlap-confirmation]')?.classList.remove('hidden');
}

function closeManualClassModal(modal) {
    resetManualTrainerOverlapConfirmation(modal?.querySelector('form[data-manual-class-form]'));
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
}

function closeQuickBookingModal(modal) {
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
}

function closeAsyncSuccessModal(modal) {
    if (!modal) {
        return;
    }

    const shouldReload = modal.dataset.reload === 'true';

    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.dataset.reload = 'false';

    if (shouldReload) {
        window.location.reload();
    }
}

function closeAsyncFormModal(form) {
    const manualClassModal = form.closest('[data-manual-class-modal]');
    const quickBookingModal = form.closest('[data-quick-booking-modal]');
    const trainerSubstitutionModal = form.closest('[data-trainer-substitution-modal]');
    const customerTransferModal = form.closest('[data-customer-transfer-modal]');

    if (manualClassModal) {
        closeManualClassModal(manualClassModal);
    } else if (quickBookingModal) {
        closeQuickBookingModal(quickBookingModal);
    } else if (trainerSubstitutionModal) {
        closeTrainerSubstitutionModal(trainerSubstitutionModal);
    } else if (customerTransferModal) {
        closeCustomerTransferModal(customerTransferModal);
    }
}

function closeTrainerSubstitutionModal(modal) {
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
}

function closeTrainerIssuesModal(modal) {
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
}

function closeTrainerPermissionDetailsModal(modal, restoreFocus = true) {
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');

    if (restoreFocus && trainerPermissionDetailsOpener?.isConnected) {
        trainerPermissionDetailsOpener.focus();
    }

    trainerPermissionDetailsOpener = null;
}

function closeTrainerPrivateLessonsModal(modal) {
    trainerPrivateLessonsAbortController?.abort();
    trainerPrivateLessonsAbortController = null;
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
}

async function loadTrainerPrivateLessons(modal, url) {
    const content = modal?.querySelector('[data-trainer-private-lessons-content]');

    if (!modal || !content || !url) {
        return;
    }

    trainerPrivateLessonsAbortController?.abort();
    trainerPrivateLessonsAbortController = new AbortController();
    content.innerHTML = `<p class="text-sm text-slate-500">${modal.dataset.loading || ''}</p>`;

    try {
        const response = await fetch(url, {
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: trainerPrivateLessonsAbortController.signal,
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        content.innerHTML = await response.text();
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }

        content.innerHTML = `<p class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">${modal.dataset.error || ''}</p>`;
    }
}

function initTrainerPrivateLessonsModal() {
    const modal = document.querySelector('[data-trainer-private-lessons-modal]');

    if (!modal || modal.dataset.trainerPrivateLessonsReady === 'true') {
        return;
    }

    modal.dataset.trainerPrivateLessonsReady = 'true';
    const title = modal.querySelector('[data-trainer-private-lessons-title]');

    document.querySelectorAll('[data-trainer-private-lessons-open]').forEach((button) => {
        button.addEventListener('click', () => {
            if (title) {
                title.textContent = `${button.dataset.trainerName} · ${modal.dataset.title}`;
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            modal.querySelector('[data-trainer-private-lessons-close]')?.focus();
            loadTrainerPrivateLessons(modal, button.dataset.url);
        });
    });

    modal.querySelectorAll('[data-trainer-private-lessons-close]').forEach((button) => {
        button.addEventListener('click', () => closeTrainerPrivateLessonsModal(modal));
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeTrainerPrivateLessonsModal(modal);
            return;
        }

        const paginationLink = event.target.closest('[data-trainer-private-lessons-pagination] a');

        if (paginationLink) {
            event.preventDefault();
            loadTrainerPrivateLessons(modal, paginationLink.href);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeTrainerPrivateLessonsModal(modal);
        }
    });
}

function closePaymentRefundModal(modal) {
    if (!modal) {
        return;
    }

    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function initPaymentRefundModal() {
    const modal = document.querySelector('[data-payment-refund-modal]');

    if (!modal || modal.dataset.paymentRefundReady === 'true') {
        return;
    }

    modal.dataset.paymentRefundReady = 'true';
    const form = modal.querySelector('[data-payment-refund-form]');
    const amountInput = modal.querySelector('[data-payment-refund-amount]');
    const methodSelect = modal.querySelector('[data-payment-refund-method]');
    const cashLocationWrapper = modal.querySelector('[data-payment-refund-cash-location]');
    const cashLocationSelect = modal.querySelector('[data-payment-refund-cash-location-select]');
    const reasonInput = modal.querySelector('[data-payment-refund-reason]');
    let currentCurrency = '';

    const syncCashLocation = () => {
        const isCash = methodSelect?.value === 'cash';

        cashLocationWrapper?.classList.toggle('hidden', !isCash);

        if (cashLocationSelect) {
            cashLocationSelect.disabled = !isCash;
            cashLocationSelect.required = isCash;
        }
    };

    const fillModal = (button, restoreOldInput = false) => {
        if (!form || !button) {
            return;
        }

        form.action = button.dataset.action || '#';
        modal.querySelector('[data-payment-refund-customer]').textContent = button.dataset.customer || '';
        modal.querySelector('[data-payment-refund-description]').textContent = button.dataset.description || '';
        modal.querySelector('[data-payment-refund-original]').textContent = button.dataset.originalAmount || '';
        modal.querySelector('[data-payment-refund-refunded]').textContent = button.dataset.refundedAmount || '';
        modal.querySelector('[data-payment-refund-remaining]').textContent = button.dataset.remainingAmount || '';
        modal.querySelector('[data-payment-refund-payment-id]').value = button.dataset.paymentId || '';
        currentCurrency = button.dataset.currency || '';

        if (amountInput) {
            amountInput.max = button.dataset.remainingInput || '';
            amountInput.value = restoreOldInput && modal.dataset.oldAmount
                ? modal.dataset.oldAmount
                : button.dataset.remainingInput || '';
        }

        if (methodSelect) {
            methodSelect.value = restoreOldInput && modal.dataset.oldMethod
                ? modal.dataset.oldMethod
                : button.dataset.defaultMethod || 'cashless';
        }

        if (cashLocationSelect) {
            cashLocationSelect.value = restoreOldInput && modal.dataset.oldCashLocationId
                ? modal.dataset.oldCashLocationId
                : button.dataset.cashLocationId || '';
        }

        if (reasonInput) {
            reasonInput.value = restoreOldInput ? modal.dataset.oldReason || '' : '';
        }

        modal.querySelector('[data-payment-refund-idempotency-key]').value = restoreOldInput && modal.dataset.oldIdempotencyKey
            ? modal.dataset.oldIdempotencyKey
            : button.dataset.idempotencyKey || '';
        syncCashLocation();
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        amountInput?.focus();
    };

    document.querySelectorAll('[data-payment-refund-open]').forEach((button) => {
        button.addEventListener('click', () => fillModal(button));
    });

    methodSelect?.addEventListener('change', syncCashLocation);
    modal.querySelectorAll('[data-payment-refund-close]').forEach((button) => {
        button.addEventListener('click', () => closePaymentRefundModal(modal));
    });
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closePaymentRefundModal(modal);
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
            closePaymentRefundModal(modal);
        }
    });
    form?.addEventListener('submit', () => {
        const template = modal.dataset.confirmBodyTemplate || '';
        const method = methodSelect?.selectedOptions[0]?.textContent?.trim() || '';
        const amount = `${amountInput?.value || ''} ${currentCurrency}`.trim();

        form.dataset.confirmBody = template
            .replace(':amount', amount)
            .replace(':method', method);
    });

    if (modal.dataset.autoOpenPaymentId) {
        const button = document.querySelector(`[data-payment-refund-open][data-payment-id="${CSS.escape(modal.dataset.autoOpenPaymentId)}"]`);

        if (button) {
            fillModal(button, true);
        }
    }
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

    syncQuickBookingLocationState(form);
    updateQuickBookingModalRooms(form);
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
        meta.textContent = [
            scheduledClass.location,
            scheduledClass.room,
            scheduledClass.trainer,
            `${scheduledClass.available_spots}/${scheduledClass.capacity}`,
        ].filter(Boolean).join(' · ');

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

    if (input.dataset.locationId) {
        url.searchParams.set('location_id', input.dataset.locationId);
    }

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
    const endTimeInput = form?.querySelector('[data-anytime-rental-end-time]');
    const endsAtInput = form?.querySelector('[data-anytime-rental-ends-at]');

    if (!dateInput || !timeInput || !startsAtInput) {
        return;
    }

    startsAtInput.value = dateInput.value && timeInput.value ? `${dateInput.value}T${timeInput.value}` : '';

    if (endsAtInput) {
        endsAtInput.value = dateInput.value && endTimeInput?.value ? `${dateInput.value}T${endTimeInput.value}` : '';
    }
}

function resetManualBookingTime(form) {
    const timeInput = form?.querySelector('[data-manual-booking-time]');

    if (timeInput) {
        timeInput.value = '';
    }

    const endTimeInput = form?.querySelector('[data-anytime-rental-end-time]');

    if (endTimeInput) {
        endTimeInput.value = '';
    }

    updateManualBookingStartsAt(form);
}

function privateTrainerTimeframeMode(form) {
    const scheduleKind = form?.querySelector('input[name="schedule_kind"]')?.value;
    const ignoreCheckbox = form?.querySelector('[data-ignore-trainer-timeframes]');

    return scheduleKind === 'private_lesson'
        && form?.dataset.privateTimeframesEnabled === '1'
        && !ignoreCheckbox?.checked;
}

function cachedRoomOptions(roomSelect) {
    if (!roomSelect) {
        return [];
    }

    if (!roomSelect.dataset.originalOptions) {
        roomSelect.dataset.originalOptions = JSON.stringify(Array.from(roomSelect.options).map((option) => ({
            value: option.value,
            text: option.textContent,
            locationId: option.dataset.locationId || '',
            activityDirectionIds: option.dataset.activityDirectionIds || '',
        })));
    }

    try {
        const options = JSON.parse(roomSelect.dataset.originalOptions || '[]');

        return Array.isArray(options) ? options : [];
    } catch {
        return [];
    }
}

function syncQuickBookingSelectState(select, valueInput, disabled) {
    if (!select) {
        return;
    }

    select.disabled = disabled;

    if (valueInput) {
        valueInput.value = select.value;
    }
}

function syncQuickBookingLocationState(form) {
    const locationSelect = form?.querySelector('[data-quick-booking-location]');
    const locationValueInput = form?.querySelector('[data-quick-booking-location-value]');

    if (!locationSelect || !locationValueInput) {
        return;
    }

    syncQuickBookingSelectState(locationSelect, locationValueInput, locationSelect.options.length <= 1);
}

function replaceQuickBookingRoomOptions(form, options, preferredRoomId = '', emptyLabel = '') {
    const roomSelect = form?.querySelector('[data-quick-booking-room]');
    const roomValueInput = form?.querySelector('[data-quick-booking-room-value]');

    if (!roomSelect || !roomValueInput) {
        return;
    }

    roomSelect.innerHTML = '';

    options.forEach((option) => {
        const element = document.createElement('option');

        element.value = String(option.value);
        element.textContent = option.text;

        if (option.locationId) {
            element.dataset.locationId = String(option.locationId);
        }

        if (option.activityDirectionIds) {
            element.dataset.activityDirectionIds = String(option.activityDirectionIds);
        }

        roomSelect.append(element);
    });

    if (options.length === 0) {
        const placeholder = document.createElement('option');

        placeholder.value = '';
        placeholder.textContent = emptyLabel;
        roomSelect.append(placeholder);
    }

    const selectedRoom = options.find((option) => String(option.value) === String(preferredRoomId)) ?? options[0];

    roomSelect.value = selectedRoom ? String(selectedRoom.value) : '';
    syncQuickBookingSelectState(roomSelect, roomValueInput, options.length <= 1);
}

function roomsForQuickBookingLocation(form) {
    const locationId = form?.querySelector('[data-quick-booking-location]')?.value || '';
    const roomSelect = form?.querySelector('[data-quick-booking-room]');

    return cachedRoomOptions(roomSelect)
        .filter((option) => roomOptionIsAllowed(form, option, locationId));
}

function updateQuickBookingModalRooms(form, preferredRoomId = '') {
    const roomSelect = form?.querySelector('[data-quick-booking-room]');

    if (!roomSelect || !form?.querySelector('[data-quick-booking-room-value]')) {
        return;
    }

    const locationRooms = roomsForQuickBookingLocation(form);

    replaceQuickBookingRoomOptions(
        form,
        locationRooms,
        preferredRoomId || roomSelect.value,
        roomSelect.dataset.noRoomsLabel || '',
    );
}

function restoreManualRoomOptions(form) {
    const roomSelect = form?.querySelector('[data-quick-booking-room]');

    if (!roomSelect) {
        return;
    }

    updateQuickBookingModalRooms(form, roomSelect.value);
}

function resetPrivateTimeframeRoomOptions(form) {
    const roomSelect = form?.querySelector('[data-quick-booking-room]');

    if (!roomSelect) {
        return;
    }

    const locationRooms = roomsForQuickBookingLocation(form);

    if (locationRooms.length === 1) {
        replaceQuickBookingRoomOptions(form, locationRooms, locationRooms[0].value);
        return;
    }

    replaceQuickBookingRoomOptions(
        form,
        [],
        '',
        locationRooms.length === 0
            ? (roomSelect.dataset.noRoomsLabel || '')
            : (roomSelect.dataset.chooseTimeLabel || ''),
    );
}

function applyManualSlotRooms(form, rooms = []) {
    const roomSelect = form?.querySelector('[data-quick-booking-room]');

    if (!roomSelect || !privateTrainerTimeframeMode(form)) {
        return;
    }

    cachedRoomOptions(roomSelect);

    replaceQuickBookingRoomOptions(
        form,
        rooms.map((room) => ({
            value: room.id,
            text: room.name,
            locationId: '',
        })),
        roomSelect.value,
        roomSelect.dataset.noRoomsLabel || '',
    );
}

function optionMatchesActivityDirection(option, activityDirectionId, multiple = false) {
    if (!activityDirectionId) {
        return true;
    }

    if (multiple) {
        const activityDirectionIds = (option.dataset.activityDirectionIds || '')
            .split(',')
            .map((id) => id.trim())
            .filter(Boolean);

        return activityDirectionIds.length === 0 || activityDirectionIds.includes(activityDirectionId);
    }

    return !option.dataset.activityDirectionId || option.dataset.activityDirectionId === activityDirectionId;
}

function roomFilterScheduleKind(form) {
    return form?.querySelector('input[name="schedule_kind"]')?.value
        || form?.closest('[data-manual-class-modal]')?.dataset.manualClassModal
        || (form?.matches('[data-schedule-series-form]') ? 'group_class' : '');
}

function selectedRoomFilterDirectionId(form) {
    const classTypeSelect = form?.querySelector(
        '[data-manual-booking-class-type], [data-manual-class-type], [data-schedule-series-class-type]',
    );
    const classTypeDirectionId = classTypeSelect?.selectedOptions[0]?.dataset.activityDirectionId || '';

    if (classTypeDirectionId) {
        return classTypeDirectionId;
    }

    return form?.querySelector('[data-manual-booking-activity-direction]')?.value || '';
}

function roomOptionDirectionIds(option) {
    const rawIds = option?.dataset?.activityDirectionIds ?? option?.activityDirectionIds ?? '';

    return String(rawIds)
        .split(',')
        .map((id) => id.trim())
        .filter(Boolean);
}

function roomOptionIsAllowed(form, option, locationId) {
    const optionLocationId = option?.dataset?.locationId ?? option?.locationId ?? '';

    if (optionLocationId && optionLocationId !== locationId) {
        return false;
    }

    const scheduleKind = roomFilterScheduleKind(form);

    if (!['group_class', 'private_lesson'].includes(scheduleKind)) {
        return true;
    }

    const roomDirectionIds = roomOptionDirectionIds(option);

    if (roomDirectionIds.length === 0) {
        return true;
    }

    const activityDirectionId = selectedRoomFilterDirectionId(form);

    return Boolean(activityDirectionId) && roomDirectionIds.includes(activityDirectionId);
}

function selectFirstEnabledOption(select) {
    if (!select || !select.value || !select.selectedOptions[0]?.disabled) {
        return;
    }

    const firstEnabledOption = Array.from(select.options).find((option) => !option.disabled);

    select.value = firstEnabledOption?.value || '';
}

function updateManualBookingDirectionFilters(form) {
    const activityDirectionSelect = form?.querySelector('[data-manual-booking-activity-direction]');

    if (!activityDirectionSelect) {
        return;
    }

    const activityDirectionId = activityDirectionSelect.value;
    const classTypeSelect = form.querySelector('[data-manual-booking-class-type]');
    const trainerSelect = form.querySelector('[data-manual-booking-trainer]');

    Array.from(classTypeSelect?.options || []).forEach((option) => {
        const isAllowed = optionMatchesActivityDirection(option, activityDirectionId);

        option.disabled = !isAllowed;
        option.hidden = !isAllowed;
    });

    selectFirstEnabledOption(classTypeSelect);
    activityDirectionSelect.required = !classTypeSelect?.selectedOptions[0]?.dataset.activityDirectionId;
    const effectiveActivityDirectionId = selectedRoomFilterDirectionId(form);

    Array.from(trainerSelect?.options || []).forEach((option) => {
        if (!option.value) {
            return;
        }

        const isAllowed = optionMatchesActivityDirection(option, effectiveActivityDirectionId, true);

        option.disabled = !isAllowed;
        option.hidden = !isAllowed;
    });

    if (trainerSelect?.selectedOptions[0]?.disabled) {
        trainerSelect.value = '';
    }
}

function isAnytimeRental(form) {
    return form?.querySelector('[data-rental-mode-choice]:checked')?.value === 'anytime';
}

function syncRentalModeFields(form) {
    const anytime = isAnytimeRental(form);
    const anytimeFields = form?.querySelector('[data-anytime-rental-fields]');
    const anytimeTimeRow = form?.querySelector('[data-anytime-rental-time-row]');
    const anytimeStartTime = form?.querySelector('[data-anytime-rental-start-time]');
    const anytimePayment = form?.querySelector('[data-anytime-rental-payment]');
    const endTimeInput = form?.querySelector('[data-anytime-rental-end-time]');
    const paymentInput = form?.querySelector('input[name="payment_amount"]');
    const presetFields = form?.querySelector('[data-rental-preset-fields]');
    const results = form?.querySelector('[data-manual-booking-results]');
    const timeInput = form?.querySelector('[data-manual-booking-time]');

    anytimeFields?.classList.toggle('hidden', !anytime);
    anytimeTimeRow?.classList.toggle('hidden', !anytime);
    anytimeStartTime?.classList.toggle('hidden', !anytime);
    anytimePayment?.classList.toggle('hidden', !anytime);
    presetFields?.classList.toggle('hidden', anytime);

    if (endTimeInput) {
        endTimeInput.required = anytime;
    }

    if (timeInput && form?.querySelector('[data-rental-mode-choice]')) {
        timeInput.required = anytime;
    }

    if (paymentInput) {
        paymentInput.disabled = !anytime;

        if (!anytime) {
            paymentInput.value = '';
        }
    }

    results?.classList.toggle('hidden', anytime);
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

            applyManualSlotRooms(form, slot.rooms ?? []);
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
    const activityDirectionInput = form.querySelector('[data-manual-booking-activity-direction]');
    const activityDirectionId = activityDirectionInput?.value || '';
    const trainerInput = form.querySelector('[data-manual-booking-trainer]');
    const trainerId = trainerInput?.value || '';
    const anytimeRental = isAnytimeRental(form);
    const timeframeMode = privateTrainerTimeframeMode(form);

    if (anytimeRental) {
        syncRentalModeFields(form);
        return;
    }

    resetManualBookingTime(form);

    if (timeframeMode) {
        resetPrivateTimeframeRoomOptions(form);
    }

    if (!dateInput.value || !scheduleKind || !locationId || (!timeframeMode && !roomId) || !classTypeId || (activityDirectionInput?.required && !activityDirectionId) || (trainerInput?.required && !trainerId)) {
        setManualBookingResultsMessage(results, results.dataset.empty || 'No available times.');
        return;
    }

    setManualBookingResultsMessage(results, dateInput.dataset.loading || 'Loading...');

    const url = new URL(availabilityUrl, window.location.origin);
    url.searchParams.set('schedule_kind', scheduleKind);
    url.searchParams.set('date', dateInput.value);
    url.searchParams.set('location_id', locationId);
    url.searchParams.set('class_type_id', classTypeId);

    if (roomId && !timeframeMode) {
        url.searchParams.set('room_id', roomId);
    }

    if (trainerId) {
        url.searchParams.set('trainer_id', trainerId);
    }

    if (activityDirectionId) {
        url.searchParams.set('activity_direction_id', activityDirectionId);
    }

    if (!timeframeMode && scheduleKind === 'private_lesson') {
        url.searchParams.set('ignore_trainer_timeframes', '1');
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
        const isAllowed = roomOptionIsAllowed(form, option, locationId);

        option.disabled = !isAllowed;
        option.hidden = !isAllowed;

        if (option.selected && isAllowed) {
            selectedOptionAllowed = true;
        }
    });

    if (!selectedOptionAllowed) {
        const firstAllowedOption = Array.from(roomSelect.options).find((option) => !option.disabled);

        roomSelect.value = firstAllowedOption?.value || '';
    }
}

function updateScheduleSeriesRooms(form) {
    const locationSelect = form?.querySelector('[data-schedule-series-location]');
    const roomSelect = form?.querySelector('[data-schedule-series-room]');

    if (!locationSelect || !roomSelect) {
        return;
    }

    const locationId = locationSelect.value;
    let selectedOptionAllowed = false;

    Array.from(roomSelect.options).forEach((option) => {
        const isAllowed = roomOptionIsAllowed(form, option, locationId);

        option.disabled = !isAllowed;
        option.hidden = !isAllowed;

        if (option.selected && isAllowed) {
            selectedOptionAllowed = true;
        }
    });

    if (!selectedOptionAllowed) {
        roomSelect.value = Array.from(roomSelect.options).find((option) => !option.disabled)?.value || '';
    }
}

function initScheduleSeriesForms() {
    document.querySelectorAll('[data-schedule-series-form]').forEach((form) => {
        if (form.dataset.scheduleSeriesReady === 'true') {
            return;
        }

        form.dataset.scheduleSeriesReady = 'true';
        form.querySelector('[data-schedule-series-location]')?.addEventListener('change', () => updateScheduleSeriesRooms(form));
        form.querySelector('[data-schedule-series-class-type]')?.addEventListener('change', () => updateScheduleSeriesRooms(form));
        updateScheduleSeriesRooms(form);
    });
}

function initTrainerPrivateTimeframes() {
    document.querySelectorAll('[data-trainer-private-timeframes]').forEach((container) => {
        if (container.dataset.trainerPrivateTimeframesReady === 'true') {
            return;
        }

        const toggleUrl = container.dataset.toggleUrl;
        const locationId = container.dataset.locationId;
        const csrfToken = container.dataset.csrfToken || '';

        if (!toggleUrl || !locationId || !csrfToken) {
            return;
        }

        container.dataset.trainerPrivateTimeframesReady = 'true';
        container.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-timeframe-cell]');

            if (!button || button.disabled) {
                return;
            }

            const nextSelected = button.dataset.selected !== '1';

            button.disabled = true;
            button.classList.add('opacity-70');

            try {
                const response = await fetch(toggleUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        location_id: locationId,
                        starts_at: button.dataset.startsAt,
                        selected: nextSelected,
                    }),
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message || 'Request failed');
                }

                button.dataset.selected = payload.selected ? '1' : '0';
                button.classList.toggle('border-emerald-300', payload.selected);
                button.classList.toggle('bg-emerald-50', payload.selected);
                button.classList.toggle('text-emerald-800', payload.selected);
                button.classList.toggle('shadow-sm', payload.selected);
                button.classList.toggle('border-stone-200', !payload.selected);
                button.classList.toggle('bg-white', !payload.selected);
                button.classList.toggle('text-slate-700', !payload.selected);
            } catch {
                button.classList.add('border-rose-300', 'bg-rose-50', 'text-rose-800');
                window.setTimeout(() => {
                    button.classList.remove('border-rose-300', 'bg-rose-50', 'text-rose-800');
                }, 1400);
            } finally {
                button.disabled = false;
                button.classList.remove('opacity-70');
            }
        });
    });
}

function updateTrainerSubstitutionRooms(form) {
    const locationInput = form?.querySelector('[data-trainer-substitution-location]');
    const roomSelect = form?.querySelector('[data-trainer-substitution-room]');

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

function trainerSubstitutionDatasetIds(value) {
    try {
        const decoded = JSON.parse(value || '[]');

        if (Array.isArray(decoded)) {
            return decoded.map((id) => String(id));
        }
    } catch {
        return [];
    }

    return [];
}

function renderTrainerSubstitutionClassResults(container, classes, selectedIds = []) {
    container.innerHTML = '';

    if (!classes.length) {
        const empty = document.createElement('p');
        empty.className = 'px-2 py-3 text-sm text-slate-500';
        empty.textContent = container.dataset.empty || 'No classes found.';
        container.appendChild(empty);
        return;
    }

    classes.forEach((scheduledClass) => {
        const label = document.createElement('label');
        label.className = 'flex items-start gap-3 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm text-slate-700';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'scheduled_class_ids[]';
        checkbox.value = String(scheduledClass.id);
        checkbox.className = 'crm-checkbox mt-1';
        checkbox.checked = selectedIds.includes(String(scheduledClass.id));

        const content = document.createElement('span');
        content.className = 'min-w-0';

        const title = document.createElement('span');
        title.className = 'block font-semibold text-slate-950';
        title.textContent = `${scheduledClass.time} · ${scheduledClass.title}`;

        const meta = document.createElement('span');
        meta.className = 'mt-1 block text-xs font-medium text-slate-500';
        meta.textContent = [scheduledClass.class_type, scheduledClass.current_trainer].filter(Boolean).join(' · ');

        content.append(title, meta);
        label.append(checkbox, content);
        container.appendChild(label);
    });
}

function loadTrainerSubstitutionClasses(form, selectedIds = []) {
    const dateInput = form?.querySelector('[data-trainer-substitution-date]');
    const locationInput = form?.querySelector('[data-trainer-substitution-location]');
    const roomInput = form?.querySelector('[data-trainer-substitution-room]');
    const results = form?.querySelector('[data-trainer-substitution-class-results]');
    const classesUrl = dateInput?.dataset.classesUrl;

    if (!dateInput || !locationInput || !roomInput || !results || !classesUrl || !dateInput.value || !locationInput.value || !roomInput.value) {
        return;
    }

    results.innerHTML = `<p class="px-2 py-3 text-sm text-slate-500">${dateInput.dataset.loading || 'Loading...'}</p>`;

    const url = new URL(classesUrl, window.location.origin);
    url.searchParams.set('date', dateInput.value);
    url.searchParams.set('location_id', locationInput.value);
    url.searchParams.set('room_id', roomInput.value);

    fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    })
        .then((response) => (response.ok ? response.json() : { data: [] }))
        .then((payload) => renderTrainerSubstitutionClassResults(results, payload.data ?? [], selectedIds))
        .catch(() => renderTrainerSubstitutionClassResults(results, [], selectedIds));
}

function resetTrainerSubstitutionForm(form) {
    const methodInput = form?.querySelector('[data-trainer-substitution-method]');
    const title = form?.closest('[data-trainer-substitution-modal]')?.querySelector('[data-trainer-substitution-title]');

    form?.reset();

    if (form?.dataset.storeAction) {
        form.action = form.dataset.storeAction;
    }

    if (methodInput) {
        methodInput.disabled = true;
    }

    if (title) {
        title.textContent = title.dataset.createTitle || title.textContent;
    }

    updateTrainerSubstitutionRooms(form);
}

function openTrainerSubstitutionModal(mode, button = null) {
    const modal = document.querySelector(`[data-trainer-substitution-modal="${mode}"]`);
    const form = modal?.querySelector(`[data-trainer-substitution-form="${mode}"]`);

    if (!modal || !form) {
        return;
    }

    resetTrainerSubstitutionForm(form);

    if (button) {
        const methodInput = form.querySelector('[data-trainer-substitution-method]');
        const title = modal.querySelector('[data-trainer-substitution-title]');

        form.action = button.dataset.action || form.action;

        if (methodInput) {
            methodInput.disabled = false;
        }

        if (title) {
            title.textContent = title.dataset.editTitle || title.textContent;
        }

        const locationInput = form.querySelector('[data-trainer-substitution-location]');
        const roomInput = form.querySelector('[data-trainer-substitution-room]');
        const substituteInput = form.querySelector('[data-trainer-substitution-substitute]');

        if (locationInput) {
            locationInput.value = button.dataset.locationId || locationInput.value;
        }

        updateTrainerSubstitutionRooms(form);

        if (roomInput) {
            roomInput.value = button.dataset.roomId || roomInput.value;
        }

        if (substituteInput) {
            substituteInput.value = button.dataset.substituteTrainerId || '';
        }

        if (mode === 'classes') {
            const dateInput = form.querySelector('[data-trainer-substitution-date]');
            const selectedIds = trainerSubstitutionDatasetIds(button.dataset.scheduledClassIds);

            if (dateInput) {
                dateInput.value = button.dataset.dateFrom || dateInput.value;
            }

            loadTrainerSubstitutionClasses(form, selectedIds);
        } else {
            const dateFromInput = form.querySelector('[data-trainer-substitution-date-from]');
            const dateToInput = form.querySelector('[data-trainer-substitution-date-to]');
            const selectedClassTypeIds = trainerSubstitutionDatasetIds(button.dataset.classTypeIds);

            if (dateFromInput) {
                dateFromInput.value = button.dataset.dateFrom || dateFromInput.value;
            }

            if (dateToInput) {
                dateToInput.value = button.dataset.dateTo || dateToInput.value;
                dateToInput.min = dateFromInput?.value || dateToInput.min;
            }

            form.querySelectorAll('[data-trainer-substitution-class-type]').forEach((checkbox) => {
                checkbox.checked = selectedClassTypeIds.includes(String(checkbox.value));
            });
        }
    } else if (mode === 'classes') {
        loadTrainerSubstitutionClasses(form);
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.querySelector('[data-trainer-substitution-close]')?.focus();
}

function initManualClassModals() {
    document.querySelectorAll('[data-manual-class-modal]').forEach((modal) => {
        if (modal.dataset.manualClassReady === 'true') {
            return;
        }

        modal.dataset.manualClassReady = 'true';
        const form = modal.querySelector('form[data-manual-class-form]');
        const resetTrainerOverlapWhenAvailabilityChanges = (event) => {
            if (event.target instanceof Element && event.target.matches(manualTrainerOverlapAvailabilitySelector)) {
                resetManualTrainerOverlapConfirmation(form);
            }
        };

        form?.addEventListener('input', resetTrainerOverlapWhenAvailabilityChanges);
        form?.addEventListener('change', resetTrainerOverlapWhenAvailabilityChanges);
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

            resetManualTrainerOverlapConfirmation(modal.querySelector('form[data-manual-class-form]'));
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

    document.querySelectorAll('[data-manual-class-type]').forEach((input) => {
        if (input.dataset.manualClassTypeReady === 'true') {
            return;
        }

        input.dataset.manualClassTypeReady = 'true';
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

function initTrainerSubstitutionModals() {
    document.querySelectorAll('[data-trainer-substitution-modal]').forEach((modal) => {
        if (modal.dataset.trainerSubstitutionReady === 'true') {
            return;
        }

        modal.dataset.trainerSubstitutionReady = 'true';
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeTrainerSubstitutionModal(modal);
            }
        });
    });

    document.querySelectorAll('[data-trainer-substitution-open]').forEach((button) => {
        if (button.dataset.trainerSubstitutionOpenReady === 'true') {
            return;
        }

        button.dataset.trainerSubstitutionOpenReady = 'true';
        button.addEventListener('click', () => openTrainerSubstitutionModal(button.dataset.trainerSubstitutionOpen));
    });

    document.querySelectorAll('[data-trainer-substitution-edit]').forEach((button) => {
        if (button.dataset.trainerSubstitutionEditReady === 'true') {
            return;
        }

        button.dataset.trainerSubstitutionEditReady = 'true';
        button.addEventListener('click', () => openTrainerSubstitutionModal(button.dataset.trainerSubstitutionEdit, button));
    });

    document.querySelectorAll('[data-trainer-substitution-close]').forEach((button) => {
        if (button.dataset.trainerSubstitutionCloseReady === 'true') {
            return;
        }

        button.dataset.trainerSubstitutionCloseReady = 'true';
        button.addEventListener('click', () => closeTrainerSubstitutionModal(button.closest('[data-trainer-substitution-modal]')));
    });

    document.querySelectorAll('[data-trainer-substitution-location]').forEach((input) => {
        if (input.dataset.trainerSubstitutionLocationReady === 'true') {
            return;
        }

        input.dataset.trainerSubstitutionLocationReady = 'true';
        input.addEventListener('change', () => {
            const form = input.closest('form');

            updateTrainerSubstitutionRooms(form);
            loadTrainerSubstitutionClasses(form);
        });
        updateTrainerSubstitutionRooms(input.closest('form'));
    });

    document.querySelectorAll('[data-trainer-substitution-room], [data-trainer-substitution-date]').forEach((input) => {
        if (input.dataset.trainerSubstitutionClassReady === 'true') {
            return;
        }

        input.dataset.trainerSubstitutionClassReady = 'true';
        input.addEventListener('change', () => loadTrainerSubstitutionClasses(input.closest('form')));
    });

    document.querySelectorAll('[data-trainer-substitution-date-from]').forEach((input) => {
        if (input.dataset.trainerSubstitutionDateFromReady === 'true') {
            return;
        }

        input.dataset.trainerSubstitutionDateFromReady = 'true';
        input.addEventListener('change', () => {
            const dateToInput = input.closest('form')?.querySelector('[data-trainer-substitution-date-to]');

            if (!dateToInput) {
                return;
            }

            dateToInput.min = input.value || dateToInput.min;

            if (dateToInput.value < input.value) {
                dateToInput.value = input.value;
            }
        });
    });

    document.addEventListener('change', (event) => {
        const checkbox = event.target.closest('[data-trainer-substitution-class-results] input[type="checkbox"]');

        if (!checkbox?.checked) {
            return;
        }

        const checked = checkbox.closest('[data-trainer-substitution-class-results]').querySelectorAll('input[type="checkbox"]:checked');

        if (checked.length > 2) {
            checkbox.checked = false;
        }
    });
}

function initTrainerIssueModals() {
    document.querySelectorAll('[data-trainer-issues-modal]').forEach((modal) => {
        if (modal.dataset.trainerIssuesReady === 'true') {
            return;
        }

        modal.dataset.trainerIssuesReady = 'true';
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeTrainerIssuesModal(modal);
            }
        });
    });

    document.querySelectorAll('[data-trainer-issues-open]').forEach((button) => {
        if (button.dataset.trainerIssuesOpenReady === 'true') {
            return;
        }

        button.dataset.trainerIssuesOpenReady = 'true';
        button.addEventListener('click', () => {
            const modal = document.querySelector(`[data-trainer-issues-modal="${button.dataset.trainerIssuesOpen}"]`);

            if (!modal) {
                return;
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            modal.querySelector('[data-trainer-issues-close]')?.focus();
        });
    });

    document.querySelectorAll('[data-trainer-issues-close]').forEach((button) => {
        if (button.dataset.trainerIssuesCloseReady === 'true') {
            return;
        }

        button.dataset.trainerIssuesCloseReady = 'true';
        button.addEventListener('click', () => closeTrainerIssuesModal(button.closest('[data-trainer-issues-modal]')));
    });
}

function initTrainerPermissionDetailsModal() {
    const modal = document.querySelector('[data-trainer-permission-details-modal]');

    if (!modal || modal.dataset.trainerPermissionDetailsReady === 'true') {
        return;
    }

    modal.dataset.trainerPermissionDetailsReady = 'true';
    const title = modal.querySelector('[data-trainer-permission-details-title]');
    const summary = modal.querySelector('[data-trainer-permission-details-summary]');
    const details = modal.querySelector('[data-trainer-permission-details-copy]');
    const group = modal.querySelector('[data-trainer-permission-details-group]');
    const sensitivity = modal.querySelector('[data-trainer-permission-details-sensitivity]');
    const sensitivityCopy = modal.querySelector('[data-trainer-permission-details-sensitivity-copy]');

    document.querySelectorAll('[data-trainer-permission-details-open]').forEach((button) => {
        button.addEventListener('click', () => {
            trainerPermissionDetailsOpener = button;

            if (title) {
                title.textContent = button.dataset.permissionTitle || '';
            }

            if (summary) {
                summary.textContent = button.dataset.permissionSummary || '';
            }

            if (details) {
                details.textContent = button.dataset.permissionDetails || '';
            }

            if (group) {
                group.textContent = button.dataset.permissionGroup || '';
            }

            if (sensitivity) {
                sensitivity.textContent = button.dataset.permissionSensitivity || '';
            }

            if (sensitivityCopy) {
                sensitivityCopy.textContent = button.dataset.permissionSensitivityCopy || '';
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            modal.querySelector('[data-trainer-permission-details-close]')?.focus();
        });
    });

    modal.querySelectorAll('[data-trainer-permission-details-close]').forEach((button) => {
        button.addEventListener('click', () => closeTrainerPermissionDetailsModal(modal));
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeTrainerPermissionDetailsModal(modal);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeTrainerPermissionDetailsModal(modal);
        }
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

            if (form?.closest('[data-quick-booking-modal]')) {
                syncQuickBookingLocationState(form);
                updateQuickBookingModalRooms(form);
            } else {
                updateQuickBookingRooms(form);
            }

            loadManualBookingAvailability(form);
        });

        const form = input.closest('form');

        if (form?.closest('[data-quick-booking-modal]')) {
            syncQuickBookingLocationState(form);
            updateQuickBookingModalRooms(form);
        } else {
            updateQuickBookingRooms(form);
        }
    });

    document.querySelectorAll('[data-quick-booking-room], [data-manual-booking-date], [data-manual-booking-activity-direction], [data-manual-booking-class-type], [data-manual-booking-trainer]').forEach((input) => {
        if (input.dataset.manualAvailabilityReady === 'true') {
            return;
        }

        input.dataset.manualAvailabilityReady = 'true';
        input.addEventListener('change', () => {
            const form = input.closest('form');

            updateManualBookingDirectionFilters(form);

            if (input.matches('[data-manual-booking-activity-direction], [data-manual-booking-class-type]')) {
                updateQuickBookingModalRooms(form);
            }

            if (input.matches('[data-quick-booking-room]') && privateTrainerTimeframeMode(form)) {
                const roomValueInput = form?.querySelector('[data-quick-booking-room-value]');

                syncQuickBookingSelectState(input, roomValueInput, input.disabled);
                return;
            }

            if (input.matches('[data-quick-booking-room]')) {
                const roomValueInput = form?.querySelector('[data-quick-booking-room-value]');

                syncQuickBookingSelectState(input, roomValueInput, input.disabled);
            }

            loadManualBookingAvailability(form);
        });
        updateManualBookingDirectionFilters(input.closest('form'));
    });

    document.querySelectorAll('[data-ignore-trainer-timeframes]').forEach((input) => {
        if (input.dataset.ignoreTrainerTimeframesReady === 'true') {
            return;
        }

        input.dataset.ignoreTrainerTimeframesReady = 'true';
        input.addEventListener('change', () => {
            const form = input.closest('form');

            if (input.checked) {
                restoreManualRoomOptions(form);
            } else {
                resetPrivateTimeframeRoomOptions(form);
            }

            loadManualBookingAvailability(form);
        });
    });

    document.querySelectorAll('[data-rental-mode-choice]').forEach((input) => {
        if (input.dataset.rentalModeReady === 'true') {
            return;
        }

        input.dataset.rentalModeReady = 'true';
        input.addEventListener('change', () => {
            const form = input.closest('form');

            syncRentalModeFields(form);
            loadManualBookingAvailability(form);
        });
        syncRentalModeFields(input.closest('form'));
    });

    document.querySelectorAll('[data-manual-booking-time], [data-anytime-rental-end-time]').forEach((input) => {
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

function copySourceForButton(button) {
    if (button.dataset.copyTarget) {
        return document.querySelector(button.dataset.copyTarget);
    }

    return button.closest('[data-copy-container]')?.querySelector('[data-copy-source]')
        ?? button.closest('article')?.querySelector('[data-copy-source]');
}

function fallbackCopy(value, source) {
    if (source && typeof source.select === 'function') {
        source.select();
        document.execCommand('copy');

        return;
    }

    const textarea = document.createElement('textarea');

    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.classList.add('fixed', 'left-[-9999px]', 'top-0');
    document.body.append(textarea);
    textarea.select();
    document.execCommand('copy');
    textarea.remove();
}

function showCopyConfirmation(button) {
    const label = button.querySelector('[data-copy-label]');
    const successLabel = button.dataset.copySuccessLabel;

    if (!label || !successLabel) {
        return;
    }

    if (!button.dataset.copyOriginalLabel) {
        button.dataset.copyOriginalLabel = label.textContent;
    }

    label.textContent = successLabel;
    window.clearTimeout(Number(button.dataset.copyResetTimeout || 0));
    button.dataset.copyResetTimeout = String(window.setTimeout(() => {
        label.textContent = button.dataset.copyOriginalLabel || '';
    }, 1600));
}

function initCopyButtons() {
    document.querySelectorAll('[data-copy-button], [data-copy-token]').forEach((button) => {
        if (button.dataset.copyReady === 'true') {
            return;
        }

        button.dataset.copyReady = 'true';
        button.addEventListener('click', async () => {
            const source = copySourceForButton(button);
            const value = button.dataset.copyValue ?? source?.value ?? source?.textContent ?? '';

            if (!value) {
                return;
            }

            if (source && typeof source.select === 'function') {
                source.select();
            }

            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(value);
                } else {
                    fallbackCopy(value, source);
                }
            } catch {
                fallbackCopy(value, source);
            }

            showCopyConfirmation(button);
        });
    });
}

function initOnboardingLogoPreviews() {
    document.querySelectorAll('[data-onboarding-logo-input]').forEach((input) => {
        if (input.dataset.onboardingLogoReady === 'true') {
            return;
        }

        const preview = document.querySelector('[data-onboarding-logo-preview]');
        const placeholder = document.querySelector('[data-onboarding-logo-placeholder]');
        let objectUrl = null;

        if (!preview) {
            return;
        }

        input.dataset.onboardingLogoReady = 'true';
        input.addEventListener('change', () => {
            const file = input.files?.[0];

            if (!file) {
                return;
            }

            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }

            objectUrl = URL.createObjectURL(file);
            preview.src = objectUrl;
            preview.classList.remove('hidden');
            placeholder?.classList.add('hidden');
        });
    });
}

async function trackOnboardingShare(button) {
    const url = button.dataset.onboardingShareTrackUrl;
    const csrf = button.dataset.onboardingShareCsrf;

    if (!url || !csrf) {
        return;
    }

    try {
        await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
    } catch {}
}

function initOnboardingShareActions() {
    document.querySelectorAll('[data-onboarding-share]').forEach((button) => {
        if (button.dataset.onboardingShareReady === 'true') {
            return;
        }

        button.dataset.onboardingShareReady = 'true';

        if (!button.matches('[data-native-share]')) {
            button.addEventListener('click', () => trackOnboardingShare(button));
            return;
        }

        button.addEventListener('click', async () => {
            const shareData = {
                title: button.dataset.shareTitle || document.title,
                text: button.dataset.shareText || '',
                url: button.dataset.shareUrl || window.location.href,
            };

            try {
                if (navigator.share) {
                    await navigator.share(shareData);
                } else if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(shareData.url);
                } else {
                    fallbackCopy(shareData.url, null);
                }

                await trackOnboardingShare(button);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    fallbackCopy(shareData.url, null);
                }
            }
        });
    });
}

function asyncStatusElement(form = null) {
    return form?.querySelector('[data-async-form-status]')
        ?? form?.closest('[data-festival-application-fragment]')?.querySelector('[data-async-form-status]')
        ?? document.querySelector('[data-async-status]');
}

function setAsyncStatus(message, type = 'success', form = null) {
    const status = asyncStatusElement(form);

    if (!status || !message) {
        return;
    }

    status.textContent = message;
    status.className = type === 'error'
        ? 'mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700 shadow-xs'
        : 'mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900 shadow-xs';
    status.classList.remove('hidden');
}

function fallbackAsyncMessage(type = 'error', form = null) {
    const status = asyncStatusElement(form);

    if (!status) {
        return 'Request failed.';
    }

    return type === 'validation'
        ? status.dataset.validationMessage ?? 'Please check the highlighted fields.'
        : status.dataset.errorMessage ?? 'Could not save changes. Please try again.';
}

function setFormDisabled(form, disabled) {
    form.querySelectorAll('button, input, select, textarea').forEach((field) => {
        if (disabled) {
            if (field.dataset.asyncWasDisabled === undefined) {
                field.dataset.asyncWasDisabled = field.disabled ? 'true' : 'false';
            }

            field.disabled = true;
            return;
        }

        field.disabled = field.dataset.asyncWasDisabled === 'true';
        delete field.dataset.asyncWasDisabled;
    });
    form.setAttribute('aria-busy', disabled ? 'true' : 'false');
}

function clearAsyncFormErrors(form) {
    form.querySelectorAll('[data-async-error]').forEach((error) => error.remove());
    form.querySelectorAll('[data-async-invalid]').forEach((field) => {
        field.removeAttribute('data-async-invalid');
        field.classList.remove('border-rose-300', 'focus:border-rose-500', 'focus:ring-rose-100');
    });
    form.querySelectorAll('[data-async-form-status]').forEach((status) => {
        status.textContent = '';
        status.classList.add('hidden');
    });

    const status = asyncStatusElement(form);

    if (status) {
        status.textContent = '';
        status.classList.add('hidden');
    }
}

function formFieldSelector(attribute, name) {
    const escapedName = window.CSS?.escape
        ? window.CSS.escape(name)
        : String(name).replaceAll('\\', '\\\\').replaceAll('"', '\\"');

    return `[${attribute}="${escapedName}"]`;
}

function formControlByName(form, name) {
    return form.querySelector(formFieldSelector('data-async-field', name))
        ?? Array.from(form.elements).find((element) => element.name === name && element.type !== 'hidden')
        ?? Array.from(form.elements).find((element) => element.name === name);
}

function asyncErrorContainer(form, name, control) {
    return form.querySelector(formFieldSelector('data-async-error-for', name))
        ?? control?.closest('[data-customer-autocomplete]')
        ?? control?.closest('label')
        ?? control?.parentElement
        ?? form;
}

function renderAsyncFormErrors(form, errors) {
    clearAsyncFormErrors(form);

    Object.entries(errors).forEach(([field, messages]) => {
        const control = formControlByName(form, field);
        const container = asyncErrorContainer(form, field, control);
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

    setAsyncStatus(firstMessage ?? fallbackAsyncMessage('validation', form), 'error', form);

    const firstInvalidControl = form.querySelector('[data-async-invalid]');
    const firstError = form.querySelector('[data-async-error]');
    const visibleInvalidControl = firstInvalidControl instanceof HTMLElement && firstInvalidControl.offsetParent !== null
        ? firstInvalidControl
        : null;

    if (visibleInvalidControl) {
        visibleInvalidControl.focus({ preventScroll: true });
    }

    (visibleInvalidControl ?? firstError)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
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
    initClassPassPreviews(replacement);
    initPhoneMasks(replacement);
    initScheduledClassTrainerModals(replacement);
    createIcons({ icons });
}

function replaceFestivalRequirementCard(cardHtml, fallbackCard) {
    const template = document.createElement('template');
    template.innerHTML = cardHtml.trim();
    const replacement = template.content.querySelector('[data-festival-requirement-card]');

    if (!replacement) {
        return null;
    }

    const requirementId = replacement.dataset.festivalRequirementId;
    const target = requirementId
        ? document.querySelector(`[data-festival-requirement-card][data-festival-requirement-id="${requirementId}"]`)
        : fallbackCard;

    (target ?? fallbackCard)?.replaceWith(replacement);
    createIcons({ icons });

    return replacement;
}

function replaceFestivalApplicationFragment(fragmentHtml, fallbackFragment) {
    const template = document.createElement('template');
    template.innerHTML = fragmentHtml.trim();
    const replacement = template.content.querySelector('[data-festival-application-fragment]');

    if (!replacement) {
        return null;
    }

    const fragmentKey = replacement.dataset.festivalApplicationFragmentKey;
    const target = fragmentKey
        ? document.querySelector('[data-festival-application-fragment-key="' + window.CSS.escape(fragmentKey) + '"]')
        : fallbackFragment;

    (target ?? fallbackFragment)?.replaceWith(replacement);
    createIcons({ icons });

    return replacement;
}

function closeScheduledClassTrainerModal(modal) {
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
}

function initScheduledClassTrainerModals(root = document) {
    root.querySelectorAll('[data-scheduled-class-trainer-modal]').forEach((modal) => {
        if (modal.dataset.scheduledClassTrainerModalReady === 'true') {
            return;
        }

        modal.dataset.scheduledClassTrainerModalReady = 'true';
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeScheduledClassTrainerModal(modal);
            }
        });

        modal.querySelectorAll('[data-scheduled-class-trainer-close]').forEach((button) => {
            button.addEventListener('click', () => closeScheduledClassTrainerModal(modal));
        });
    });

    root.querySelectorAll('[data-scheduled-class-trainer-open]').forEach((button) => {
        if (button.dataset.scheduledClassTrainerOpenReady === 'true') {
            return;
        }

        button.dataset.scheduledClassTrainerOpenReady = 'true';
        button.addEventListener('click', () => {
            const modal = root.querySelector(`[data-scheduled-class-trainer-modal="${button.dataset.scheduledClassTrainerOpen}"]`)
                ?? document.querySelector(`[data-scheduled-class-trainer-modal="${button.dataset.scheduledClassTrainerOpen}"]`);

            if (!modal) {
                return;
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            modal.querySelector('[data-scheduled-class-trainer-select]')?.focus();
        });
    });
}

function showAsyncSuccessModal(form, payload) {
    const modal = document.querySelector('[data-async-success-modal]');
    const title = modal?.querySelector('[data-async-success-title]');
    const body = modal?.querySelector('[data-async-success-body]');
    const closeButton = modal?.querySelector('[data-async-success-close]');

    if (!modal || !body) {
        return false;
    }

    if (title) {
        title.textContent = payload.modal_title || payload.title || title.textContent;
    }

    body.textContent = payload.modal_message || payload.message || '';
    modal.dataset.reload = payload.reload || form.dataset.asyncSuccess === 'modal-reload' ? 'true' : 'false';
    closeAsyncFormModal(form);
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    closeButton?.focus();

    return true;
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
    initPhoneMasks(replacement);
    initPublicBookingModal(replacement);

    return replacement;
}

function publicBookingFocusableElements(modal) {
    return [...modal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])')]
        .filter((element) => element instanceof HTMLElement && element.offsetParent !== null);
}

function syncPublicBookingUrl(bookingId = null) {
    const url = new URL(window.location.href);

    if (bookingId) {
        url.searchParams.set('booking', bookingId);
    } else {
        url.searchParams.delete('booking');
    }

    window.history.replaceState({ publicSchedule: true }, '', url);
    syncPublicLegalReturnUrls();
}

function openPublicBookingModal(modal, opener = null) {
    if (!modal) {
        return;
    }

    publicBookingModalOpener = opener instanceof HTMLElement ? opener : publicBookingModalOpener;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');

    if (modal.dataset.bookingId) {
        syncPublicBookingUrl(modal.dataset.bookingId);
    }

    window.requestAnimationFrame(() => {
        modal.querySelector('[data-public-booking-close]')?.focus();
    });
}

function closePublicBookingModal(modal, restoreFocus = true) {
    if (!modal) {
        return;
    }

    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
    syncPublicBookingUrl();

    const root = modal.closest('[data-public-booking-modal-root]');

    if (root) {
        root.innerHTML = '';
    }

    if (restoreFocus && publicBookingModalOpener?.isConnected) {
        publicBookingModalOpener.focus();
    }

    publicBookingModalOpener = null;
}

function initPublicBookingModal(root = document) {
    const modal = root.matches?.('[data-public-booking-modal]')
        ? root
        : root.querySelector?.('[data-public-booking-modal]');

    if (!modal) {
        return;
    }

    if (modal.dataset.publicBookingModalReady !== 'true') {
        modal.dataset.publicBookingModalReady = 'true';

        modal.addEventListener('click', (event) => {
            if (event.target === modal || event.target.closest('[data-public-booking-close]')) {
                closePublicBookingModal(modal);
            }
        });

        modal.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closePublicBookingModal(modal);
                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            const focusableElements = publicBookingFocusableElements(modal);

            if (focusableElements.length === 0) {
                event.preventDefault();
                return;
            }

            const firstElement = focusableElements[0];
            const lastElement = focusableElements.at(-1);

            if (event.shiftKey && document.activeElement === firstElement) {
                event.preventDefault();
                lastElement.focus();
            } else if (!event.shiftKey && document.activeElement === lastElement) {
                event.preventDefault();
                firstElement.focus();
            }
        });
    }

    if (modal.dataset.autoOpen === 'true') {
        delete modal.dataset.autoOpen;
        openPublicBookingModal(modal);
    }
}

async function fetchPublicBookingModal(link) {
    const root = document.querySelector('[data-public-booking-modal-root]');

    if (!root) {
        window.location.href = link.href;
        return;
    }

    link.setAttribute('aria-busy', 'true');

    try {
        const response = await fetch(link.href, {
            credentials: 'same-origin',
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (response.status === 409 || response.status === 422) {
            const payload = await response.json();

            if (payload.redirect_url) {
                window.location.href = payload.redirect_url;
                return;
            }
        }

        if (!response.ok) {
            throw new Error('Public booking modal request failed.');
        }

        const template = document.createElement('template');
        template.innerHTML = await response.text();

        if (!template.content.querySelector('[data-public-booking-modal]')) {
            throw new Error('Public booking modal response was invalid.');
        }

        root.replaceChildren(template.content);
        createIcons({ icons });
        initPhoneMasks(root);
        initPublicBookingModal(root);
        openPublicBookingModal(root.querySelector('[data-public-booking-modal]'), link);
    } catch {
        window.location.href = link.href;
    } finally {
        link.removeAttribute('aria-busy');
    }
}

async function submitPublicBookingForm(form) {
    const modal = form.closest('[data-public-booking-modal]');
    const modalBody = modal?.querySelector('[data-public-booking-modal-body]');
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

        if (response.status === 409 && payload.redirect_url) {
            window.location.href = payload.redirect_url;
            return;
        }

        if (response.ok && payload.success_html && modalBody) {
            const modalTitle = modal?.querySelector('#public-booking-modal-title');

            if (modalTitle && payload.modal_title) {
                modalTitle.textContent = payload.modal_title;
            }

            modalBody.innerHTML = payload.success_html;
            syncPublicBookingUrl();
            createIcons({ icons });
            modalBody.querySelector('[data-public-booking-success]')?.focus();
            return;
        }

        if (response.status === 422 && payload.errors) {
            renderAsyncFormErrors(form, payload.errors);
            return;
        }

        setAsyncStatus(payload.message ?? fallbackAsyncMessage('error', form), 'error', form);
    } catch {
        setAsyncStatus(fallbackAsyncMessage('error', form), 'error', form);
    } finally {
        if (document.body.contains(form)) {
            setFormDisabled(form, false);
        }
    }
}

function syncPublicLegalReturnUrls() {
    document.querySelectorAll('a[data-public-legal-link]').forEach((link) => {
        const url = new URL(link.href, window.location.origin);
        url.searchParams.set('return_to', window.location.href);
        link.href = url.toString();
    });
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

        syncPublicLegalReturnUrls();
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

function initPublicCalendarSwipe() {
    document.addEventListener('pointerdown', (event) => {
        const calendar = event.target.closest('[data-public-calendar]');

        if (!calendar || !event.isPrimary || (event.pointerType === 'mouse' && event.button !== 0)) {
            return;
        }

        publicCalendarSwipeStart = {
            calendar,
            pointerId: event.pointerId,
            x: event.clientX,
            y: event.clientY,
        };
    });

    document.addEventListener('pointerup', (event) => {
        if (!publicCalendarSwipeStart || publicCalendarSwipeStart.pointerId !== event.pointerId) {
            return;
        }

        const swipe = publicCalendarSwipeStart;
        publicCalendarSwipeStart = null;
        const horizontalDistance = event.clientX - swipe.x;
        const verticalDistance = event.clientY - swipe.y;

        if (Math.abs(horizontalDistance) < 50 || Math.abs(horizontalDistance) <= Math.abs(verticalDistance) * 1.25) {
            return;
        }

        const url = horizontalDistance < 0
            ? swipe.calendar.dataset.nextUrl
            : swipe.calendar.dataset.previousUrl;

        if (!url) {
            return;
        }

        event.preventDefault();
        swipe.calendar.dataset.swiping = 'true';
        window.setTimeout(() => {
            delete swipe.calendar.dataset.swiping;
        }, 500);
        loadPublicScheduleUrl(url);
    });

    document.addEventListener('pointercancel', () => {
        publicCalendarSwipeStart = null;
    });
}

async function submitAsyncForm(form) {
    if (form.matches('[data-public-booking-form]')) {
        await submitPublicBookingForm(form);
        return;
    }

    const fallbackCard = form.closest('[data-scheduled-class-card]');
    const fallbackRequirementCard = form.closest('[data-festival-requirement-card]');
    const fallbackFestivalApplicationFragment = form.closest('[data-festival-application-fragment]');
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

            if (payload.requirement_html) {
                const replacement = replaceFestivalRequirementCard(payload.requirement_html, fallbackRequirementCard);
                const replacementForm = replacement?.querySelector('form[data-async-form]') ?? null;
                setAsyncStatus(payload.message, 'success', replacementForm);
                return;
            }

            if (payload.fragment_html) {
                const replacement = replaceFestivalApplicationFragment(payload.fragment_html, fallbackFestivalApplicationFragment);
                setAsyncStatus(payload.message, 'success', replacement);
                return;
            }

            if (Array.isArray(payload.fragments_html)) {
                const replacements = payload.fragments_html
                    .map((fragmentHtml) => replaceFestivalApplicationFragment(fragmentHtml, fallbackFestivalApplicationFragment))
                    .filter(Boolean);
                setAsyncStatus(payload.message, 'success', replacements[0] ?? fallbackFestivalApplicationFragment);
                return;
            }

            setAsyncStatus(payload.message, 'success', form);

            if ((payload.success_modal || form.dataset.asyncSuccess === 'modal' || form.dataset.asyncSuccess === 'modal-reload') && showAsyncSuccessModal(form, payload)) {
                return;
            }

            if (payload.reload || form.dataset.asyncSuccess === 'reload') {
                window.setTimeout(() => window.location.reload(), 50);
                return;
            }

            replaceScheduledClassCard(payload.card_html ?? '', fallbackCard);
            return;
        }

        if (response.status === 422 && payload.errors) {
            revealManualTrainerOverlapConfirmation(form, payload.errors);
            renderAsyncFormErrors(form, payload.errors);
            return;
        }

        setAsyncStatus(payload.message ?? fallbackAsyncMessage('error', form), 'error', form);
    } catch {
        setAsyncStatus(fallbackAsyncMessage('error', form), 'error', form);
    } finally {
        delete form.dataset.confirmed;

        if (document.body.contains(form)) {
            setFormDisabled(form, false);
        }
    }
}

function closeCustomerTransferModal(modal) {
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
}

function importStatusClasses(status) {
    if (status === 'inserted') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }

    if (status === 'updated') {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }

    return 'border-rose-200 bg-rose-50 text-rose-700';
}

function importStatusLabel(form, status) {
    if (status === 'inserted') {
        return form.dataset.insertedLabel || 'Inserted';
    }

    if (status === 'updated') {
        return form.dataset.updatedLabel || 'Updated';
    }

    return form.dataset.skippedLabel || 'Skipped';
}

function setCustomerImportProgress(form, visible, percent = 0, label = '') {
    const progress = form.querySelector('[data-customer-import-progress]');
    const bar = form.querySelector('[data-customer-import-progress-bar]');
    const value = form.querySelector('[data-customer-import-progress-value]');
    const labelElement = form.querySelector('[data-customer-import-progress-label]');

    if (!progress || !bar || !value || !labelElement) {
        return;
    }

    progress.classList.toggle('hidden', !visible);
    bar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
    value.textContent = `${Math.round(percent)}%`;

    if (label) {
        labelElement.textContent = label;
    }
}

function setCustomerImportError(form, message = '') {
    const error = form.querySelector('[data-customer-import-error]');

    if (!error) {
        return;
    }

    error.textContent = message;
    error.classList.toggle('hidden', !message);
}

function resetCustomerImportResults(form) {
    form.querySelectorAll('[data-customer-import-summary]').forEach((summary) => {
        summary.textContent = '0';
    });

    const results = form.querySelector('[data-customer-import-results]');

    if (results) {
        results.innerHTML = '';
        const empty = document.createElement('div');
        empty.className = 'px-4 py-5 text-sm text-slate-500';
        empty.textContent = form.dataset.empty || 'No rows processed yet.';
        results.append(empty);
    }
}

function rowContactText(row) {
    return [row.name, row.phone, row.email].filter(Boolean).join(' · ');
}

function rowResultText(row) {
    const parts = [row.message].filter(Boolean);
    const matchedCustomer = row.matched_customer;

    if (matchedCustomer?.name || matchedCustomer?.phone || matchedCustomer?.email) {
        parts.push([matchedCustomer.name, matchedCustomer.phone, matchedCustomer.email].filter(Boolean).join(' · '));
    }

    return parts.join(' · ');
}

function renderCustomerImportResults(form, payload) {
    const summary = payload.summary || {};

    Object.entries(summary).forEach(([key, value]) => {
        const target = form.querySelector(`[data-customer-import-summary="${key}"]`);

        if (target) {
            target.textContent = String(value ?? 0);
        }
    });

    const results = form.querySelector('[data-customer-import-results]');

    if (!results) {
        return;
    }

    results.innerHTML = '';

    if (!Array.isArray(payload.rows) || payload.rows.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'px-4 py-5 text-sm text-slate-500';
        empty.textContent = form.dataset.empty || 'No rows processed yet.';
        results.append(empty);
        return;
    }

    payload.rows.forEach((row) => {
        const item = document.createElement('div');
        item.className = 'grid min-w-[720px] grid-cols-[72px_130px_1fr_1.4fr] gap-3 border-t border-stone-100 px-4 py-3 text-sm first:border-t-0';

        const rowNumber = document.createElement('div');
        rowNumber.className = 'font-semibold text-slate-600';
        rowNumber.textContent = String(row.row ?? '');

        const status = document.createElement('div');
        const badge = document.createElement('span');
        badge.className = `inline-flex h-6 w-fit items-center rounded-md border px-2 text-xs font-semibold ${importStatusClasses(row.status)}`;
        badge.textContent = importStatusLabel(form, row.status);
        status.append(badge);

        const contact = document.createElement('div');
        contact.className = 'min-w-0 truncate text-slate-700';
        contact.textContent = rowContactText(row);
        contact.title = contact.textContent;

        const reason = document.createElement('div');
        reason.className = 'min-w-0 truncate text-slate-500';
        reason.textContent = rowResultText(row);
        reason.title = reason.textContent;

        item.append(rowNumber, status, contact, reason);
        results.append(item);
    });
}

function selectedCustomerImportFile(input) {
    return input?.files?.[0] ?? null;
}

function setCustomerImportFile(form, file = null, options = {}) {
    const input = form.querySelector('[data-customer-import-input]');
    const fileName = form.querySelector('[data-customer-import-file-name]');
    const submit = form.querySelector('[data-customer-import-submit]');
    const validate = options.validate ?? true;
    const resetValidation = options.resetValidation ?? true;

    if (file && input) {
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        input.files = dataTransfer.files;
    }

    const selectedFile = selectedCustomerImportFile(input);

    if (fileName) {
        fileName.textContent = selectedFile
            ? (form.dataset.fileReadyTemplate || '__name__').replace('__name__', selectedFile.name)
            : '';
        fileName.classList.toggle('hidden', !selectedFile);
    }

    if (resetValidation) {
        form.dataset.customerImportHeaderValid = 'false';
        setCustomerImportError(form);
        setCustomerImportProgress(form, false);
        resetCustomerImportResults(form);
    }

    if (submit) {
        submit.disabled = !selectedFile || form.dataset.customerImportHeaderValid !== 'true';
    }

    if (selectedFile && validate) {
        validateCustomerImportFile(form);
    }
}

function customerImportValidationMessage(form, payload) {
    return payload.errors?.file?.[0] || payload.message || form.dataset.failed || 'Could not import this file.';
}

function validateCustomerImportFile(form) {
    const file = selectedCustomerImportFile(form.querySelector('[data-customer-import-input]'));
    const submit = form.querySelector('[data-customer-import-submit]');

    if (!file || !form.dataset.validateAction) {
        return;
    }

    form.dataset.customerImportHeaderValid = 'false';
    form.dataset.customerImportValidationToken = String(Date.now());
    const validationToken = form.dataset.customerImportValidationToken;

    if (submit) {
        submit.disabled = true;
    }

    const xhr = new XMLHttpRequest();
    const formData = new FormData();
    const csrfToken = form.querySelector('input[name="_token"]')?.value;

    formData.append('file', file);

    if (csrfToken) {
        formData.append('_token', csrfToken);
    }

    setCustomerImportError(form);
    setCustomerImportProgress(form, true, 0, form.dataset.validating || 'Checking columns...');

    xhr.upload.addEventListener('progress', (event) => {
        if (!event.lengthComputable) {
            return;
        }

        setCustomerImportProgress(form, true, Math.min(95, (event.loaded / event.total) * 100), form.dataset.validating || 'Checking columns...');
    });

    xhr.addEventListener('load', () => {
        if (form.dataset.customerImportValidationToken !== validationToken) {
            return;
        }

        let payload = {};

        try {
            payload = JSON.parse(xhr.responseText || '{}');
        } catch {
            payload = {};
        }

        if (xhr.status >= 200 && xhr.status < 300) {
            form.dataset.customerImportHeaderValid = 'true';
            setCustomerImportProgress(form, false);

            if (submit) {
                submit.disabled = false;
            }

            return;
        }

        setCustomerImportError(form, customerImportValidationMessage(form, payload));
        setCustomerImportProgress(form, false);
    });

    xhr.addEventListener('error', () => {
        if (form.dataset.customerImportValidationToken !== validationToken) {
            return;
        }

        setCustomerImportError(form, form.dataset.failed || 'Could not import this file.');
        setCustomerImportProgress(form, false);
    });

    xhr.open('POST', form.dataset.validateAction);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.send(formData);
}

function submitCustomerImportForm(form) {
    const file = selectedCustomerImportFile(form.querySelector('[data-customer-import-input]'));

    if (!file || form.dataset.customerImportHeaderValid !== 'true') {
        return;
    }

    const xhr = new XMLHttpRequest();
    const formData = new FormData(form);

    setCustomerImportError(form);
    resetCustomerImportResults(form);
    setCustomerImportProgress(form, true, 0, form.dataset.uploading || 'Uploading...');
    setFormDisabled(form, true);

    xhr.upload.addEventListener('progress', (event) => {
        if (!event.lengthComputable) {
            return;
        }

        const percent = Math.min(95, (event.loaded / event.total) * 100);
        setCustomerImportProgress(form, true, percent, form.dataset.uploading || 'Uploading...');
    });

    xhr.addEventListener('load', () => {
        let payload = {};

        try {
            payload = JSON.parse(xhr.responseText || '{}');
        } catch {
            payload = {};
        }

        if (xhr.status >= 200 && xhr.status < 300) {
            setCustomerImportProgress(form, true, 100, form.dataset.processing || 'Processing customers...');
            renderCustomerImportResults(form, payload);
            return;
        }

        const message = payload.errors?.file?.[0] || payload.message || form.dataset.failed || 'Could not import this file.';
        setCustomerImportError(form, message);
        setCustomerImportProgress(form, false);
    });

    xhr.addEventListener('error', () => {
        setCustomerImportError(form, form.dataset.failed || 'Could not import this file.');
        setCustomerImportProgress(form, false);
    });

    xhr.addEventListener('loadend', () => {
        setFormDisabled(form, false);
        setCustomerImportFile(form, null, { validate: false, resetValidation: false });
    });

    xhr.open(form.method.toUpperCase(), form.action);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.send(formData);
}

function initCustomerTransferModals() {
    document.querySelectorAll('[data-customer-transfer-modal]').forEach((modal) => {
        if (modal.dataset.customerTransferReady === 'true') {
            return;
        }

        modal.dataset.customerTransferReady = 'true';
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeCustomerTransferModal(modal);
            }
        });
    });

    document.querySelectorAll('[data-customer-transfer-open]').forEach((button) => {
        if (button.dataset.customerTransferOpenReady === 'true') {
            return;
        }

        button.dataset.customerTransferOpenReady = 'true';
        button.addEventListener('click', () => {
            const modal = document.querySelector(`[data-customer-transfer-modal="${button.dataset.customerTransferOpen}"]`);

            if (!modal) {
                return;
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            modal.querySelector('[data-customer-transfer-close]')?.focus();
        });
    });

    document.querySelectorAll('[data-customer-transfer-close]').forEach((button) => {
        if (button.dataset.customerTransferCloseReady === 'true') {
            return;
        }

        button.dataset.customerTransferCloseReady = 'true';
        button.addEventListener('click', () => closeCustomerTransferModal(button.closest('[data-customer-transfer-modal]')));
    });

    document.querySelectorAll('[data-customer-import-form]').forEach((form) => {
        if (form.dataset.customerImportReady === 'true') {
            return;
        }

        const input = form.querySelector('[data-customer-import-input]');
        const dropzone = form.querySelector('[data-customer-import-dropzone]');
        const browse = form.querySelector('[data-customer-import-browse]');

        form.dataset.customerImportReady = 'true';
        setCustomerImportFile(form, null, { validate: false, resetValidation: false });
        resetCustomerImportResults(form);

        browse?.addEventListener('click', () => input?.click());
        dropzone?.addEventListener('click', (event) => {
            if (event.target.closest('button, a')) {
                return;
            }

            input?.click();
        });
        input?.addEventListener('change', () => setCustomerImportFile(form));

        dropzone?.addEventListener('dragover', (event) => {
            event.preventDefault();
            dropzone.classList.add('border-brand-500', 'bg-brand-50');
        });
        dropzone?.addEventListener('dragleave', () => {
            dropzone.classList.remove('border-brand-500', 'bg-brand-50');
        });
        dropzone?.addEventListener('drop', (event) => {
            event.preventDefault();
            dropzone.classList.remove('border-brand-500', 'bg-brand-50');
            const file = event.dataTransfer?.files?.[0] ?? null;

            if (file) {
                setCustomerImportFile(form, file);
            }
        });

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            submitCustomerImportForm(form);
        });
    });
}

function initActiveScrollTargets() {
    document.querySelectorAll('[data-active-scroll-target]').forEach((element) => {
        element.scrollIntoView({ block: 'nearest', inline: 'center' });
    });
}

function initVelvetScrollTop() {
    const button = document.querySelector('[data-velvet-scroll-top]');

    if (!button || button.dataset.velvetScrollTopReady === 'true') {
        return;
    }

    const topTarget = document.querySelector('#festival-page-top');
    let visibilityUpdateRequested = false;

    const updateVisibility = () => {
        const visibilityThreshold = Math.max(400, window.innerHeight * 0.75);

        button.dataset.visible = window.scrollY >= visibilityThreshold ? 'true' : 'false';
        visibilityUpdateRequested = false;
    };

    const requestVisibilityUpdate = () => {
        if (visibilityUpdateRequested) {
            return;
        }

        visibilityUpdateRequested = true;
        window.requestAnimationFrame(updateVisibility);
    };

    button.dataset.velvetScrollTopReady = 'true';
    button.hidden = false;
    button.addEventListener('click', () => {
        topTarget?.focus({ preventScroll: true });
        window.scrollTo({
            top: 0,
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
    });
    window.addEventListener('scroll', requestVisibilityUpdate, { passive: true });
    updateVisibility();
}

function initProfilePhoneMergeScroll() {
    const mergePanel = document.querySelector('[data-profile-phone-merge]');

    if (!mergePanel) {
        return;
    }

    window.setTimeout(() => {
        mergePanel.scrollIntoView({ block: 'center', inline: 'nearest' });
    }, 80);
}

function normalizeAssistantText(content) {
    return String(content || '')
        .replace(/\r\n?/g, '\n')
        .replace(/\$\\(?:rightarrow|to)\$/gu, '→')
        .replace(/\\(?:rightarrow|to)\b/gu, '→')
        .replace(/:\s+-\s+/gu, ':\n- ')
        .replace(/\s+-\s+(?=(?:\d{1,2}:\d{2}|[A-ZА-ЯІЇЄҐ][A-Za-zА-Яа-яІіЇїЄєҐґ'’ʼ .-]{0,80}:))/gu, '\n- ')
        .trim();
}

function appendAssistantInlineText(container, content) {
    const text = String(content || '');
    const inlinePattern = /\*\*(.+?)\*\*|`([^`\n]+)`/gu;
    let lastIndex = 0;
    let match;

    while ((match = inlinePattern.exec(text)) !== null) {
        if (match.index > lastIndex) {
            container.append(document.createTextNode(text.slice(lastIndex, match.index)));
        }

        if (match[1] !== undefined) {
            const strong = document.createElement('strong');
            strong.className = 'font-semibold text-inherit';
            strong.textContent = match[1];
            container.append(strong);
        } else {
            const code = document.createElement('code');
            code.className = 'rounded bg-black/5 px-1 py-0.5 font-mono text-[0.92em] text-inherit';
            code.textContent = match[2];
            container.append(code);
        }

        lastIndex = match.index + match[0].length;
    }

    if (lastIndex < text.length) {
        container.append(document.createTextNode(text.slice(lastIndex)));
    }
}

function appendAssistantText(container, content) {
    const normalized = normalizeAssistantText(content);

    if (!normalized) {
        return;
    }

    const lines = normalized.split('\n').map((line) => line.trim());
    let paragraphLines = [];
    let currentList = null;

    const flushParagraph = () => {
        if (paragraphLines.length === 0) {
            return;
        }

        const paragraph = document.createElement('p');
        appendAssistantInlineText(paragraph, paragraphLines.join(' '));
        container.append(paragraph);
        paragraphLines = [];
    };

    const appendListItem = (listType, text) => {
        flushParagraph();

        if (!currentList || currentList.tagName.toLowerCase() !== listType) {
            currentList = document.createElement(listType);
            currentList.className = `${listType === 'ol' ? 'list-decimal' : 'list-disc'} space-y-1 pl-4`;
            container.append(currentList);
        }

        const item = document.createElement('li');
        item.className = 'pl-1';
        appendAssistantInlineText(item, text);
        currentList.append(item);
    };

    lines.forEach((line) => {
        if (line === '') {
            flushParagraph();
            currentList = null;
            return;
        }

        const heading = line.match(/^#{1,6}\s+(.+)$/u);

        if (heading) {
            flushParagraph();
            currentList = null;
            const paragraph = document.createElement('p');
            paragraph.className = 'font-semibold text-inherit';
            appendAssistantInlineText(paragraph, heading[1].replace(/^\*\*(.+)\*\*$/u, '$1'));
            container.append(paragraph);
            return;
        }

        const numbered = line.match(/^(\d+)[.)]\s+(.+)$/u);

        if (numbered) {
            appendListItem('ol', numbered[2]);
            return;
        }

        const bulleted = line.match(/^[-*•]\s+(.+)$/u);

        if (bulleted) {
            appendListItem('ul', bulleted[1]);
            return;
        }

        currentList = null;
        paragraphLines.push(line);
    });

    flushParagraph();

    if (!container.hasChildNodes()) {
        container.textContent = normalized;
    }
}

function initAssistantChat() {
    document.querySelectorAll('[data-assistant-chat]').forEach((widget) => {
        if (widget.dataset.assistantReady === 'true') {
            return;
        }

        const toggle = widget.querySelector('[data-assistant-toggle]');
        const close = widget.querySelector('[data-assistant-close]');
        const clear = widget.querySelector('[data-assistant-clear]');
        const clearModal = widget.querySelector('[data-assistant-clear-modal]');
        const clearCancel = widget.querySelector('[data-assistant-clear-cancel]');
        const clearConfirm = widget.querySelector('[data-assistant-clear-confirm]');
        const panel = widget.querySelector('[data-assistant-panel]');
        const messages = widget.querySelector('[data-assistant-messages]');
        const actions = widget.querySelector('[data-assistant-actions]');
        const followUps = widget.querySelector('[data-assistant-follow-ups]');
        const form = widget.querySelector('[data-assistant-form]');
        const input = widget.querySelector('[data-assistant-input]');
        const imageInput = widget.querySelector('[data-assistant-image-input]');
        const imagePicker = widget.querySelector('[data-assistant-image-picker]');
        const imagePreview = widget.querySelector('[data-assistant-image-preview]');
        const imagePreviewSource = widget.querySelector('[data-assistant-image-preview-source]');
        const imageRemove = widget.querySelector('[data-assistant-image-remove]');
        const dropZone = widget.querySelector('[data-assistant-drop-zone]');
        const imageInputEnabled = widget.dataset.imageInputEnabled === 'true';
        const voiceRecord = widget.querySelector('[data-assistant-voice-record]');
        const voiceRecording = widget.querySelector('[data-assistant-voice-recording]');
        const voiceTimer = widget.querySelector('[data-assistant-voice-timer]');
        const voiceStop = widget.querySelector('[data-assistant-voice-stop]');
        const voiceCancel = widget.querySelector('[data-assistant-voice-cancel]');
        const composerControls = widget.querySelector('[data-assistant-composer-controls]');
        const voiceInputEnabled = widget.dataset.voiceInputEnabled === 'true';

        if (
            !toggle
            || !panel
            || !messages
            || !actions
            || !followUps
            || !form
            || !input
            || !dropZone
            || !composerControls
            || (
                imageInputEnabled
                && (!imageInput || !imagePicker || !imagePreview || !imagePreviewSource || !imageRemove)
            )
            || (
                voiceInputEnabled
                && (!voiceRecord || !voiceRecording || !voiceTimer || !voiceStop || !voiceCancel)
            )
        ) {
            return;
        }

        widget.dataset.assistantReady = 'true';

        let loaded = false;
        let loading = false;
        let currentMessages = [];
        let selectedImage = null;
        let selectedImageUrl = '';
        let dragDepth = 0;
        let voiceRecorder = null;
        let voiceStream = null;
        let voiceChunks = [];
        let voiceStartedAt = 0;
        let voiceTimerId = null;
        let voiceCancelled = false;
        let recordingVoice = false;
        const localImageUrls = new Set();
        const acceptedImageTypes = ['image/jpeg', 'image/png', 'image/webp'];
        const maximumImageSize = 2 * 1024 * 1024;
        const maximumVoiceSize = 25 * 1024 * 1024;
        const maximumVoiceDurationSeconds = 120;
        const voiceSupported = voiceInputEnabled
            && typeof window.MediaRecorder !== 'undefined'
            && typeof navigator.mediaDevices?.getUserMedia === 'function';

        const csrfToken = widget.dataset.csrfToken || '';
        const focusInputSoon = () => {
            if (panel.classList.contains('hidden')) {
                return;
            }

            window.requestAnimationFrame(() => input.focus());
        };
        const requestJson = (url, options = {}) => fetch(url, {
            ...options,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                ...(options.headers || {}),
            },
        }).then(async (response) => {
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || widget.dataset.errorMessage || 'Request failed');
            }

            return payload;
        });

        const resetSelectedImage = ({ revokeUrl = true } = {}) => {
            if (selectedImageUrl && revokeUrl) {
                URL.revokeObjectURL(selectedImageUrl);
                localImageUrls.delete(selectedImageUrl);
            }

            selectedImage = null;
            selectedImageUrl = '';
            if (imageInput) {
                imageInput.value = '';
            }
            imagePreviewSource?.removeAttribute('src');
            imagePreview?.classList.add('hidden');
        };

        const syncComposerControls = () => {
            const composerDisabled = loading || recordingVoice;
            input.disabled = composerDisabled;

            if (imageInput) {
                imageInput.disabled = composerDisabled;
            }

            if (imagePicker) {
                imagePicker.disabled = composerDisabled;
            }

            if (imageRemove) {
                imageRemove.disabled = composerDisabled;
            }

            form.querySelector('button[type="submit"]').disabled = composerDisabled;

            if (voiceRecord) {
                voiceRecord.disabled = composerDisabled || input.value.trim() !== '' || Boolean(selectedImage);
                voiceRecord.classList.toggle('hidden', !voiceSupported);
                voiceRecord.classList.toggle('inline-flex', voiceSupported);
            }

            composerControls.classList.toggle('hidden', recordingVoice);
            voiceRecording?.classList.toggle('hidden', !recordingVoice);
            voiceRecording?.classList.toggle('flex', recordingVoice);
        };

        const setLoading = (value) => {
            loading = value;
            syncComposerControls();
            clear?.toggleAttribute('disabled', value);
            clearConfirm?.toggleAttribute('disabled', value);
            form.classList.toggle('opacity-70', value);

            if (!value) {
                focusInputSoon();
            }
        };

        const closeClearModal = () => {
            clearModal?.classList.add('hidden');
            clearModal?.classList.remove('flex');
        };

        const openClearModal = () => {
            if (loading || !clearModal || !clearConfirm) {
                return;
            }

            clearModal.classList.remove('hidden');
            clearModal.classList.add('flex');
            clearConfirm.focus();
        };

        const bubbleClass = (role) => {
            if (role === 'user') {
                return 'ml-auto bg-[#3B223F] text-white';
            }

            if (role === 'rejected_intent') {
                return 'mr-auto border border-rose-100 bg-rose-50 text-rose-800';
            }

            if (role === 'tool') {
                return 'mr-auto border border-emerald-100 bg-emerald-50 text-emerald-900';
            }

            if (role === 'thinking') {
                return 'mr-auto border border-stone-200 bg-white text-slate-500 animate-pulse';
            }

            return 'mr-auto border border-stone-200 bg-white text-slate-800';
        };

        const attachmentPreviewUrl = (attachment) => {
            if (!attachment || typeof attachment !== 'object') {
                return '';
            }

            return attachment.preview_url || '';
        };

        const renderMessageAttachments = (bubble, attachments = []) => {
            attachments.forEach((attachment) => {
                const previewUrl = attachmentPreviewUrl(attachment);

                if (!previewUrl) {
                    return;
                }

                const link = document.createElement('a');
                link.href = previewUrl;
                link.target = '_blank';
                link.rel = 'noopener';
                link.className = 'block overflow-hidden rounded-lg';

                const image = document.createElement('img');
                image.src = previewUrl;
                image.alt = attachment.original_name || widget.dataset.imageLabel || '';
                image.loading = 'lazy';
                image.className = 'max-h-56 w-full rounded-lg object-cover';

                link.append(image);
                bubble.append(link);
            });
        };

        const releaseUnusedLocalImageUrls = (items) => {
            const activeUrls = new Set(selectedImageUrl ? [selectedImageUrl] : []);

            items.forEach((message) => {
                (message.attachments || []).forEach((attachment) => {
                    if (attachment.local && attachment.preview_url) {
                        activeUrls.add(attachment.preview_url);
                    }
                });
            });

            localImageUrls.forEach((url) => {
                if (!activeUrls.has(url)) {
                    URL.revokeObjectURL(url);
                    localImageUrls.delete(url);
                }
            });
        };

        const renderMessages = (items = []) => {
            messages.innerHTML = '';
            releaseUnusedLocalImageUrls(items);

            if (items.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'm-auto max-w-64 text-center text-sm leading-6 text-slate-500';
                empty.textContent = widget.dataset.emptyMessage || '';
                messages.append(empty);
                return;
            }

            items.forEach((message) => {
                const bubble = document.createElement('div');
                bubble.className = `max-w-[86%] break-words rounded-lg px-3 py-2 text-sm leading-6 shadow-xs ${bubbleClass(message.role)}`;
                const attachments = Array.isArray(message.attachments) ? message.attachments : [];

                if (attachments.length) {
                    bubble.classList.add('space-y-2');
                    renderMessageAttachments(bubble, attachments);
                }

                if (['assistant', 'tool', 'rejected_intent'].includes(message.role)) {
                    if (message.content) {
                        bubble.classList.add('space-y-2');
                    }

                    appendAssistantText(bubble, message.content || '');
                } else if (message.content) {
                    const content = document.createElement('p');
                    content.textContent = message.content;
                    bubble.append(content);
                }

                messages.append(bubble);
            });

            messages.scrollTop = messages.scrollHeight;
        };

        const renderActions = (items = []) => {
            actions.innerHTML = '';
            actions.classList.toggle('hidden', items.length === 0);

            items.forEach((action) => {
                const card = document.createElement('div');
                card.className = 'rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950';

                const summary = document.createElement('div');
                summary.className = 'font-semibold';
                summary.textContent = action.preview?.summary || action.action_name;
                card.append(summary);

                (action.preview?.warnings || []).forEach((warning) => {
                    const warningLine = document.createElement('div');
                    warningLine.className = 'mt-1 text-xs font-semibold text-rose-700';
                    warningLine.textContent = warning;
                    card.append(warningLine);
                });

                const controls = document.createElement('div');
                controls.className = 'mt-3 flex gap-2';

                const confirm = document.createElement('button');
                confirm.type = 'button';
                confirm.className = 'rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-60';
                confirm.textContent = widget.dataset.confirmLabel || 'Confirm';
                confirm.addEventListener('click', () => submitAction(widget.dataset.confirmUrlTemplate, action.id));
                controls.append(confirm);

                const cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.className = 'rounded-lg border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60';
                cancel.textContent = widget.dataset.cancelLabel || 'Cancel';
                cancel.addEventListener('click', () => submitAction(widget.dataset.cancelUrlTemplate, action.id));
                controls.append(cancel);

                card.append(controls);
                actions.append(card);
            });
        };

        const latestFollowUpActions = (items = []) => {
            const latest = [...items]
                .reverse()
                .find((message) => message.role === 'assistant' && Array.isArray(message.metadata?.follow_up_actions));

            return latest?.metadata?.follow_up_actions?.slice(0, 3) || [];
        };

        const submitSuggestedMessage = (message) => {
            if (!message || loading) {
                return;
            }

            input.value = message;

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        };

        const renderFollowUps = (items = [], pendingActions = []) => {
            const suggestions = pendingActions.length ? [] : latestFollowUpActions(items);
            followUps.innerHTML = '';
            followUps.classList.toggle('hidden', suggestions.length === 0);

            suggestions.forEach((suggestion) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'mr-2 mb-2 inline-flex rounded-full border border-brand-100 bg-brand-50 px-3 py-1.5 text-left text-xs font-semibold leading-5 text-brand-900 transition hover:border-brand-200 hover:bg-brand-100 disabled:opacity-60';
                button.textContent = suggestion;
                button.addEventListener('click', () => submitSuggestedMessage(suggestion));
                followUps.append(button);
            });
        };

        const render = (payload) => {
            const pendingActions = payload.pending_actions || [];
            currentMessages = payload.messages || [];
            renderMessages(currentMessages);
            renderActions(pendingActions);
            renderFollowUps(currentMessages, pendingActions);
        };

        const renderWithLocalMessage = (message, image = null, imageUrl = '') => {
            const attachments = image && imageUrl
                ? [{
                    preview_url: imageUrl,
                    original_name: image.name,
                    mime_type: image.type,
                    byte_size: image.size,
                    local: true,
                }]
                : [];

            const localMessageId = `local-${Date.now()}`;

            currentMessages = [
                ...currentMessages,
                {
                    id: localMessageId,
                    role: 'user',
                    content: message,
                    attachments,
                },
                {
                    id: `thinking-${Date.now()}`,
                    role: 'thinking',
                    content: widget.dataset.thinkingMessage || 'Ladna is thinking...',
                },
            ];
            renderMessages(currentMessages);
            renderFollowUps([]);

            return localMessageId;
        };

        const updateThinkingStatus = (statusMessage) => {
            for (let index = currentMessages.length - 1; index >= 0; index -= 1) {
                if (currentMessages[index].role === 'thinking') {
                    currentMessages[index] = {
                        ...currentMessages[index],
                        content: statusMessage,
                    };
                    renderMessages(currentMessages);
                    return;
                }
            }
        };

        const requestAssistantStream = async (message, image = null, voice = null) => {
            const body = new FormData();

            if (message) {
                body.append('message', message);
            }

            if (image) {
                body.append('image', image);
            }

            if (voice) {
                body.append('voice', voice);
            }

            const response = await fetch(widget.dataset.sendUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/x-ndjson',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body,
            });

            if (!response.ok) {
                const payload = await response.json().catch(() => ({}));
                throw new Error(payload.message || widget.dataset.errorMessage || 'Request failed');
            }

            if (!(response.headers.get('content-type') || '').includes('application/x-ndjson')) {
                const payload = await response.json();
                loaded = true;
                render(payload);
                return;
            }

            if (!response.body) {
                throw new Error(widget.dataset.errorMessage || 'Request failed');
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let receivedResult = false;

            const processLine = (line) => {
                if (!line.trim()) {
                    return;
                }

                const event = JSON.parse(line);

                if (event.type === 'status' && event.message) {
                    updateThinkingStatus(event.message);
                    return;
                }

                if (event.type === 'result' && event.payload) {
                    receivedResult = true;
                    loaded = true;
                    render(event.payload);
                    return;
                }

                if (event.type === 'error') {
                    throw new Error(event.message || widget.dataset.errorMessage || 'Request failed');
                }
            };

            while (true) {
                const { value, done } = await reader.read();
                buffer += decoder.decode(value || new Uint8Array(), { stream: !done });
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';

                lines.forEach(processLine);

                if (done) {
                    break;
                }
            }

            if (buffer.trim()) {
                processLine(buffer);
            }

            if (!receivedResult) {
                throw new Error(widget.dataset.errorMessage || 'Request failed');
            }
        };

        const showError = (message) => {
            currentMessages = currentMessages.filter((item) => item.role !== 'thinking');

            if (currentMessages.length) {
                renderMessages(currentMessages);
            }

            const bubble = document.createElement('div');
            bubble.className = 'mr-auto max-w-[82%] rounded-lg border border-rose-100 bg-rose-50 px-3 py-2 text-sm leading-6 text-rose-800';
            bubble.textContent = message || widget.dataset.errorMessage || '';
            messages.append(bubble);
            messages.scrollTop = messages.scrollHeight;
        };

        const removeOptimisticMessage = (messageId) => {
            currentMessages = currentMessages.filter(
                (item) => item.id !== messageId && item.role !== 'thinking',
            );
            renderMessages(currentMessages);
            renderFollowUps(currentMessages, []);
        };

        const releaseVoiceStream = () => {
            voiceStream?.getTracks().forEach((track) => track.stop());
            voiceStream = null;
        };

        const clearVoiceTimer = () => {
            if (voiceTimerId !== null) {
                window.clearInterval(voiceTimerId);
                voiceTimerId = null;
            }
        };

        const updateVoiceTimer = () => {
            if (!voiceTimer || !voiceStartedAt) {
                return;
            }

            const elapsedSeconds = Math.min(
                maximumVoiceDurationSeconds,
                Math.floor((Date.now() - voiceStartedAt) / 1000),
            );
            const minutes = Math.floor(elapsedSeconds / 60).toString().padStart(2, '0');
            const seconds = (elapsedSeconds % 60).toString().padStart(2, '0');
            voiceTimer.textContent = `${minutes}:${seconds}`;

            if (elapsedSeconds >= maximumVoiceDurationSeconds && voiceRecorder?.state === 'recording') {
                voiceRecorder.stop();
            }
        };

        const finishVoiceRecording = () => {
            clearVoiceTimer();
            releaseVoiceStream();
            voiceRecorder = null;
            voiceStartedAt = 0;
            recordingVoice = false;
            syncComposerControls();
        };

        const voiceFileExtension = (mimeType) => {
            if (mimeType.includes('mp4')) {
                return 'm4a';
            }

            if (mimeType.includes('ogg')) {
                return 'ogg';
            }

            return 'webm';
        };

        const sendVoiceRecording = (blob) => {
            if (!blob.size || blob.size > maximumVoiceSize) {
                showError(widget.dataset.voiceTooLargeMessage);
                return;
            }

            const voice = new File(
                [blob],
                `voice-message.${voiceFileExtension(blob.type)}`,
                { type: blob.type || 'application/octet-stream' },
            );
            const localMessageId = renderWithLocalMessage(widget.dataset.voiceMessageLabel || 'Voice message');
            setLoading(true);
            requestAssistantStream('', null, voice)
                .catch((error) => {
                    removeOptimisticMessage(localMessageId);
                    showError(error.message);
                })
                .finally(() => setLoading(false));
        };

        const cancelVoiceRecording = () => {
            if (!recordingVoice) {
                return;
            }

            voiceCancelled = true;

            if (voiceRecorder?.state === 'recording') {
                voiceRecorder.stop();
                return;
            }

            voiceChunks = [];
            finishVoiceRecording();
        };

        const startVoiceRecording = async () => {
            if (!voiceSupported || loading || recordingVoice || input.value.trim() || selectedImage) {
                return;
            }

            try {
                voiceStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                const supportedMimeType = [
                    'audio/webm;codecs=opus',
                    'audio/ogg;codecs=opus',
                    'audio/mp4',
                    'audio/webm',
                    'audio/ogg',
                ].find((mimeType) => MediaRecorder.isTypeSupported(mimeType));
                const recorderOptions = {
                    audioBitsPerSecond: 64000,
                    ...(supportedMimeType ? { mimeType: supportedMimeType } : {}),
                };

                try {
                    voiceRecorder = new MediaRecorder(voiceStream, recorderOptions);
                } catch {
                    voiceRecorder = new MediaRecorder(voiceStream);
                }
                voiceChunks = [];
                voiceCancelled = false;
                recordingVoice = true;
                voiceStartedAt = Date.now();
                syncComposerControls();
                updateVoiceTimer();
                voiceTimerId = window.setInterval(updateVoiceTimer, 250);

                voiceRecorder.addEventListener('dataavailable', (event) => {
                    if (event.data.size > 0) {
                        voiceChunks.push(event.data);
                    }
                });
                voiceRecorder.addEventListener('error', () => {
                    voiceCancelled = true;
                    finishVoiceRecording();
                    showError(widget.dataset.voiceRecordingErrorMessage);
                });
                voiceRecorder.addEventListener('stop', () => {
                    const mimeType = voiceRecorder?.mimeType || supportedMimeType || 'audio/webm';
                    const blob = new Blob(voiceChunks, { type: mimeType });
                    const shouldSend = !voiceCancelled;
                    voiceChunks = [];
                    finishVoiceRecording();

                    if (shouldSend) {
                        sendVoiceRecording(blob);
                    }
                });
                voiceRecorder.start(250);
            } catch {
                releaseVoiceStream();
                recordingVoice = false;
                syncComposerControls();
                showError(widget.dataset.voicePermissionMessage);
            }
        };

        const selectImage = (file) => {
            if (!imageInputEnabled || !file || loading) {
                return;
            }

            if (!acceptedImageTypes.includes(file.type)) {
                imageInput.value = '';
                showError(widget.dataset.imageInvalidTypeMessage);
                return;
            }

            if (file.size > maximumImageSize) {
                imageInput.value = '';
                showError(widget.dataset.imageTooLargeMessage);
                return;
            }

            resetSelectedImage();
            selectedImage = file;
            selectedImageUrl = URL.createObjectURL(file);
            localImageUrls.add(selectedImageUrl);
            imagePreviewSource.src = selectedImageUrl;
            imagePreviewSource.alt = file.name || widget.dataset.imageLabel || '';
            imagePreview.classList.remove('hidden');
            syncComposerControls();
        };

        const setDropActive = (active) => {
            dropZone.classList.toggle('bg-brand-50/40', active);
            dropZone.classList.toggle('ring-2', active);
            dropZone.classList.toggle('ring-brand-200', active);
        };

        const load = () => {
            if (loaded || loading) {
                return;
            }

            setLoading(true);
            requestJson(widget.dataset.showUrl)
                .then((payload) => {
                    loaded = true;
                    render(payload);
                })
                .catch((error) => showError(error.message))
                .finally(() => setLoading(false));
        };

        const submitAction = (template, actionId) => {
            if (!template || loading) {
                return;
            }

            setLoading(true);
            requestJson(template.replace('__ACTION__', actionId), { method: 'POST', body: '{}' })
                .then(render)
                .catch((error) => showError(error.message))
                .finally(() => setLoading(false));
        };

        const clearChat = () => {
            if (loading || !widget.dataset.clearUrl) {
                return;
            }

            closeClearModal();
            setLoading(true);
            requestJson(widget.dataset.clearUrl, { method: 'DELETE', body: '{}' })
                .then((payload) => {
                    loaded = true;
                    resetSelectedImage();
                    render(payload);
                })
                .catch((error) => showError(error.message))
                .finally(() => setLoading(false));
        };

        toggle.addEventListener('click', () => {
            panel.classList.toggle('hidden');

            if (!panel.classList.contains('hidden')) {
                load();
                input.focus();
            } else {
                cancelVoiceRecording();
            }
        });

        close?.addEventListener('click', () => {
            cancelVoiceRecording();
            panel.classList.add('hidden');
        });
        clear?.addEventListener('click', openClearModal);
        clearCancel?.addEventListener('click', closeClearModal);
        clearModal?.addEventListener('click', (event) => {
            if (event.target === clearModal) {
                closeClearModal();
            }
        });
        clearConfirm?.addEventListener('click', clearChat);
        if (imageInputEnabled) {
            imagePicker?.addEventListener('click', () => {
                imageInput.value = '';
                imageInput.click();
            });
            imageRemove?.addEventListener('click', () => {
                resetSelectedImage();
                syncComposerControls();
                focusInputSoon();
            });
            imageInput?.addEventListener('change', () => {
                selectImage(imageInput.files?.[0]);
            });
            form.addEventListener('paste', (event) => {
                const pastedImage = Array.from(event.clipboardData?.files || [])
                    .find((file) => file.type.startsWith('image/'));

                if (!pastedImage) {
                    return;
                }

                event.preventDefault();
                selectImage(pastedImage);
            });
            dropZone.addEventListener('dragenter', (event) => {
                if (!Array.from(event.dataTransfer?.types || []).includes('Files')) {
                    return;
                }

                event.preventDefault();
                dragDepth += 1;
                setDropActive(true);
            });
            dropZone.addEventListener('dragover', (event) => {
                if (!Array.from(event.dataTransfer?.types || []).includes('Files')) {
                    return;
                }

                event.preventDefault();

                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'copy';
                }
            });
            dropZone.addEventListener('dragleave', () => {
                dragDepth = Math.max(0, dragDepth - 1);

                if (dragDepth === 0) {
                    setDropActive(false);
                }
            });
            dropZone.addEventListener('drop', (event) => {
                event.preventDefault();
                dragDepth = 0;
                setDropActive(false);

                const droppedFiles = Array.from(event.dataTransfer?.files || []);
                const droppedImage = droppedFiles.find((file) => file.type.startsWith('image/')) || droppedFiles[0];

                selectImage(droppedImage);
            });
        }

        voiceRecord?.addEventListener('click', startVoiceRecording);
        voiceStop?.addEventListener('click', () => {
            if (voiceRecorder?.state === 'recording') {
                voiceRecorder.stop();
            }
        });
        voiceCancel?.addEventListener('click', cancelVoiceRecording);
        input.addEventListener('input', syncComposerControls);
        syncComposerControls();

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const message = input.value.trim();
            const image = selectedImage;
            const imageUrl = selectedImageUrl;

            if ((!message && !image) || loading || recordingVoice) {
                return;
            }

            input.value = '';
            resetSelectedImage({ revokeUrl: false });
            renderWithLocalMessage(message, image, imageUrl);
            setLoading(true);
            requestAssistantStream(message, image)
                .catch((error) => showError(error.message))
                .finally(() => setLoading(false));
        });
    });
}

function initAppUpdatePrompt() {
    const prompt = document.querySelector('[data-app-update]');

    if (!prompt) {
        return;
    }

    const versionUrl = prompt.dataset.versionUrl;
    const currentRevision = prompt.dataset.currentRevision;
    const serviceWorkerUrl = prompt.dataset.serviceWorkerUrl;
    const reloadButton = prompt.querySelector('[data-app-update-reload]');
    const pollIntervalMs = 5 * 60 * 1000;
    let pendingServiceWorker = null;
    let reloading = false;
    let hadServiceWorkerController = Boolean(navigator.serviceWorker?.controller);

    const showPrompt = () => {
        prompt.classList.remove('hidden');
    };

    const reloadPage = () => {
        if (reloading) {
            return;
        }

        reloading = true;

        if (reloadButton) {
            reloadButton.disabled = true;
        }

        if (pendingServiceWorker) {
            pendingServiceWorker.postMessage({ type: 'SKIP_WAITING' });
            window.setTimeout(() => window.location.reload(), 700);

            return;
        }

        window.location.reload();
    };

    const checkVersion = () => {
        if (!versionUrl || !currentRevision) {
            return Promise.resolve();
        }

        return fetch(versionUrl, {
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
            },
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((payload) => {
                if (payload?.revision && payload.revision !== currentRevision) {
                    showPrompt();
                }
            })
            .catch(() => {});
    };

    reloadButton?.addEventListener('click', reloadPage);

    checkVersion();
    window.setInterval(checkVersion, pollIntervalMs);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            checkVersion();
        }
    });

    if (!('serviceWorker' in navigator) || !serviceWorkerUrl || !window.isSecureContext) {
        return;
    }

    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (reloading) {
            window.location.reload();

            return;
        }

        if (hadServiceWorkerController) {
            showPrompt();
        }

        hadServiceWorkerController = true;
    });

    navigator.serviceWorker.register(serviceWorkerUrl)
        .then((registration) => {
            if (registration.waiting && navigator.serviceWorker.controller) {
                pendingServiceWorker = registration.waiting;
                showPrompt();
            }

            registration.addEventListener('updatefound', () => {
                const installingWorker = registration.installing;

                installingWorker?.addEventListener('statechange', () => {
                    if (installingWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        pendingServiceWorker = installingWorker;
                        showPrompt();
                    }
                });
            });
        })
        .catch(() => {});
}

function initPwaInstallPrompt() {
    const banners = Array.from(document.querySelectorAll('[data-pwa-install-banner]'));
    const buttons = Array.from(document.querySelectorAll('[data-pwa-install]'));
    const dismissButtons = Array.from(document.querySelectorAll('[data-pwa-install-dismiss]'));

    if (buttons.length === 0) {
        return;
    }

    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

    if (isStandalone) {
        return;
    }

    let deferredPrompt = null;
    let dismissed = false;

    const showPrompt = () => {
        if (dismissed) {
            return;
        }

        banners.forEach((banner) => {
            banner.hidden = false;
        });

        buttons.forEach((button) => {
            button.hidden = false;
            button.disabled = false;
        });
    };

    const hidePrompt = () => {
        banners.forEach((banner) => {
            banner.hidden = true;
        });

        buttons.forEach((button) => {
            button.hidden = true;
            button.disabled = false;
        });
    };

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt = event;
        showPrompt();
    });

    buttons.forEach((button) => {
        button.addEventListener('click', async () => {
            if (!deferredPrompt) {
                hidePrompt();

                return;
            }

            const promptEvent = deferredPrompt;
            deferredPrompt = null;

            buttons.forEach((installButton) => {
                installButton.disabled = true;
            });

            try {
                await promptEvent.prompt();
                await promptEvent.userChoice;
            } catch (error) {
                deferredPrompt = null;
            } finally {
                hidePrompt();
            }
        });
    });

    dismissButtons.forEach((button) => {
        button.addEventListener('click', () => {
            dismissed = true;
            deferredPrompt = null;
            hidePrompt();
        });
    });

    window.addEventListener('appinstalled', () => {
        dismissed = true;
        hidePrompt();
    });
}

function initPeopleCounterScreenshotViewer() {
    const modal = document.querySelector('[data-people-counter-screenshot-modal]');

    if (!modal || modal.dataset.peopleCounterScreenshotReady === 'true') {
        return;
    }

    const image = modal.querySelector('[data-people-counter-screenshot-image]');
    const stage = modal.querySelector('[data-people-counter-screenshot-stage]');
    const title = modal.querySelector('[data-people-counter-screenshot-title]');
    const meta = modal.querySelector('[data-people-counter-screenshot-meta]');
    const thumbs = modal.querySelector('[data-people-counter-screenshot-thumbs]');
    const closeButtons = modal.querySelectorAll('[data-people-counter-screenshot-close]');
    const previousButton = modal.querySelector('[data-people-counter-screenshot-prev]');
    const nextButton = modal.querySelector('[data-people-counter-screenshot-next]');
    const zoomInButton = modal.querySelector('[data-people-counter-screenshot-zoom-in]');
    const zoomOutButton = modal.querySelector('[data-people-counter-screenshot-zoom-out]');
    const resetButton = modal.querySelector('[data-people-counter-screenshot-reset]');
    let gallery = [];
    let currentIndex = 0;
    let panzoom = null;

    if (!image || !stage || !title || !meta || !thumbs) {
        return;
    }

    modal.dataset.peopleCounterScreenshotReady = 'true';

    const normalizeGallery = (items) => items
        .filter((item) => item && typeof item.url === 'string' && item.url.trim() !== '')
        .map((item) => ({
            url: item.url,
            thumbnailUrl: item.thumbnail_url || item.thumbnailUrl || item.url,
            title: item.title || '',
            meta: item.meta || '',
            alt: item.alt || item.title || '',
        }));

    const destroyPanzoom = () => {
        panzoom?.destroy();
        panzoom = null;
        image.style.transform = '';
    };

    const updateNavigationState = () => {
        const hasMultipleItems = gallery.length > 1;

        [previousButton, nextButton].forEach((button) => {
            if (!button) {
                return;
            }

            button.disabled = !hasMultipleItems;
            button.classList.toggle('cursor-not-allowed', !hasMultipleItems);
            button.classList.toggle('opacity-40', !hasMultipleItems);
        });
    };

    const renderThumbs = () => {
        thumbs.innerHTML = '';

        gallery.forEach((item, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = [
                'h-16 w-24 shrink-0 overflow-hidden rounded-md border bg-slate-900 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                index === currentIndex ? 'border-brand-500 ring-2 ring-brand-500' : 'border-white/10 hover:border-white/40',
            ].join(' ');
            button.setAttribute('aria-label', item.title || `${index + 1}`);

            if (index === currentIndex) {
                button.setAttribute('aria-current', 'true');
            }

            const thumb = document.createElement('img');
            thumb.src = item.thumbnailUrl;
            thumb.alt = '';
            thumb.loading = 'lazy';
            thumb.className = 'h-full w-full object-cover';
            button.append(thumb);
            button.addEventListener('click', () => showImage(index));
            thumbs.append(button);
        });
    };

    function showImage(index) {
        if (gallery.length === 0) {
            return;
        }

        currentIndex = (index + gallery.length) % gallery.length;
        const item = gallery[currentIndex];

        destroyPanzoom();
        title.textContent = item.title || '';
        meta.textContent = item.meta || '';
        meta.classList.toggle('hidden', item.meta === '');
        image.alt = item.alt || item.title || '';
        image.src = item.url;
        renderThumbs();
        updateNavigationState();
    }

    const close = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
        destroyPanzoom();
        image.removeAttribute('src');
        gallery = [];
        thumbs.innerHTML = '';
    };

    const open = (trigger) => {
        try {
            gallery = normalizeGallery(JSON.parse(trigger.dataset.peopleCounterGallery || '[]'));
        } catch {
            gallery = [];
        }

        if (gallery.length === 0) {
            return;
        }

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
        showImage(Number.parseInt(trigger.dataset.peopleCounterStartIndex || '0', 10) || 0);
        modal.querySelector('[data-people-counter-screenshot-close]')?.focus();
    };

    image.addEventListener('load', () => {
        destroyPanzoom();
        panzoom = Panzoom(image, {
            contain: 'inside',
            maxScale: 6,
            minScale: 1,
        });
    });

    stage.addEventListener('wheel', (event) => {
        if (!panzoom) {
            return;
        }

        event.preventDefault();
        panzoom.zoomWithWheel(event);
    }, { passive: false });

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-people-counter-screenshot-trigger]');

        if (!trigger) {
            return;
        }

        event.preventDefault();
        open(trigger);
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            close();
        }
    });

    closeButtons.forEach((button) => button.addEventListener('click', close));
    previousButton?.addEventListener('click', () => showImage(currentIndex - 1));
    nextButton?.addEventListener('click', () => showImage(currentIndex + 1));
    zoomInButton?.addEventListener('click', () => panzoom?.zoomIn());
    zoomOutButton?.addEventListener('click', () => panzoom?.zoomOut());
    resetButton?.addEventListener('click', () => panzoom?.reset());

    document.addEventListener('keydown', (event) => {
        if (modal.classList.contains('hidden')) {
            return;
        }

        if (event.key === 'Escape') {
            close();
        }

        if (event.key === 'ArrowLeft') {
            showImage(currentIndex - 1);
        }

        if (event.key === 'ArrowRight') {
            showImage(currentIndex + 1);
        }
    });
}

function initPublicPricingCalculators(root = document) {
    root.querySelectorAll('[data-public-pricing]').forEach((calculator) => {
        if (calculator.dataset.publicPricingReady === 'true') {
            return;
        }

        const locationInput = calculator.querySelector('[data-pricing-location-count]');
        const total = calculator.querySelector('[data-pricing-total]');
        const period = calculator.querySelector('[data-pricing-period]');
        const annualSavings = calculator.querySelector('[data-pricing-annual-savings]');
        const locationLabel = calculator.querySelector('[data-pricing-location-label]');
        const decrementButton = calculator.querySelector('[data-pricing-decrement]');
        const incrementButton = calculator.querySelector('[data-pricing-increment]');
        const intervalButtons = calculator.querySelectorAll('[data-pricing-interval]');

        if (!locationInput || !total || !period || !annualSavings || !locationLabel) {
            return;
        }

        let quotes;

        try {
            quotes = JSON.parse(calculator.dataset.pricingQuotes || '{}');
        } catch {
            return;
        }

        const minimumLocationCount = Number.parseInt(locationInput.min, 10);
        const maximumLocationCount = Number.parseInt(locationInput.max, 10);
        let interval = 'monthly';

        const update = (requestedLocationCount = Number.parseInt(locationInput.value, 10)) => {
            const locationCount = Math.min(
                maximumLocationCount,
                Math.max(minimumLocationCount, Number.isNaN(requestedLocationCount) ? minimumLocationCount : requestedLocationCount),
            );
            const quote = quotes[String(locationCount)];

            if (!quote?.[interval]) {
                return;
            }

            locationInput.value = String(locationCount);
            total.textContent = quote[interval].total;
            period.textContent = interval === 'annual'
                ? calculator.dataset.pricingAnnualPeriod
                : calculator.dataset.pricingMonthlyPeriod;
            annualSavings.textContent = quote.annual.discount;
            locationLabel.textContent = quote.location_label;
            decrementButton?.toggleAttribute('disabled', locationCount <= minimumLocationCount);
            incrementButton?.toggleAttribute('disabled', locationCount >= maximumLocationCount);
        };

        intervalButtons.forEach((button) => {
            button.addEventListener('click', () => {
                interval = button.dataset.pricingInterval;
                intervalButtons.forEach((candidate) => candidate.setAttribute('aria-pressed', String(candidate === button)));
                update();
            });
        });

        decrementButton?.addEventListener('click', () => update(Number.parseInt(locationInput.value, 10) - 1));
        incrementButton?.addEventListener('click', () => update(Number.parseInt(locationInput.value, 10) + 1));
        locationInput.addEventListener('input', () => update());
        locationInput.addEventListener('change', () => update());

        calculator.dataset.publicPricingReady = 'true';
        update();
    });
}

function initIntegrationForms(root = document) {
    root.querySelectorAll('[data-integration-form]').forEach((form) => {
        if (form.dataset.integrationFormReady === 'true') {
            return;
        }

        const updateFieldVisibility = () => {
            form.querySelectorAll('[data-integration-field-wrapper][data-visible-when]').forEach((wrapper) => {
                let conditions = {};

                try {
                    conditions = JSON.parse(wrapper.dataset.visibleWhen || '{}');
                } catch {
                    conditions = {};
                }

                const isVisible = Object.entries(conditions).every(([field, expected]) => {
                    const control = form.querySelector(`[name="credentials[${field}]"]`);
                    const expectedValues = Array.isArray(expected) ? expected : [expected];

                    return control && expectedValues.includes(control.value);
                });

                wrapper.classList.toggle('hidden', !isVisible);
                wrapper.classList.toggle('block', isVisible);
            });
        };

        form.addEventListener('change', (event) => {
            if (event.target.matches('[name^="credentials["]')) {
                updateFieldVisibility();
            }
        });

        form.dataset.integrationFormReady = 'true';
        updateFieldVisibility();
    });
}

function initSmsSendingSettings(root = document) {
    root.querySelectorAll('[data-sms-sending-settings]').forEach((form) => {
        if (form.dataset.smsSendingSettingsReady === 'true') {
            return;
        }

        const updateMode = () => {
            const mode = form.querySelector('input[name="sms_sending_mode"]:checked')?.value || 'disabled';

            form.querySelectorAll('[data-sms-mode-panel]').forEach((panel) => {
                const isVisible = panel.dataset.smsModePanel === mode;

                panel.classList.toggle('hidden', !isVisible);
                panel.setAttribute('aria-hidden', String(!isVisible));
            });

            const provider = form.querySelector('select[name="sms_provider"]');
            const ownGatewaySelected = mode === 'own_gateway';

            if (provider) {
                provider.disabled = !ownGatewaySelected;
                provider.required = ownGatewaySelected;
            }

            document.querySelectorAll('[data-sms-own-gateway-settings]').forEach((settings) => {
                settings.classList.toggle('hidden', !ownGatewaySelected);
                settings.setAttribute('aria-hidden', String(!ownGatewaySelected));
            });
        };

        form.addEventListener('change', (event) => {
            if (event.target.matches('input[name="sms_sending_mode"]')) {
                updateMode();
            }
        });

        form.dataset.smsSendingSettingsReady = 'true';
        updateMode();
    });
}

function setEventVenueGroupEnabled(group, enabled) {
    if (!group) {
        return;
    }

    group.classList.toggle('hidden', !enabled);
    group.setAttribute('aria-hidden', String(!enabled));
    group.querySelectorAll('input, select, textarea').forEach((field) => {
        field.disabled = !enabled;
    });
}

function updateEventVenueForm(form) {
    const venueKind = form.querySelector('input[name="venue_kind"]:checked')?.value || 'studio';
    const studioFields = form.querySelector('[data-event-venue-fields="studio"]');
    const externalFields = form.querySelector('[data-event-venue-fields="external"]');
    const location = form.querySelector('[data-event-location]');
    const externalVenueName = form.querySelector('input[name="external_venue_name"]');
    const externalAddress = form.querySelector('input[name="external_address"]');
    const studioSelected = venueKind === 'studio';

    setEventVenueGroupEnabled(studioFields, studioSelected);
    setEventVenueGroupEnabled(externalFields, !studioSelected);

    if (location) {
        location.required = studioSelected;
    }

    if (externalVenueName) {
        externalVenueName.required = !studioSelected;
    }

    if (externalAddress) {
        externalAddress.required = !studioSelected;
    }

    if (!studioSelected || !studioFields) {
        return;
    }

    const selectedLocationId = location?.value || '';
    let visibleRoomCount = 0;

    studioFields.querySelectorAll('[data-event-room-card]').forEach((card) => {
        const visible = selectedLocationId !== '' && card.dataset.locationId === selectedLocationId;
        const checkbox = card.querySelector('input[type="checkbox"]');

        card.classList.toggle('hidden', !visible);
        card.classList.toggle('flex', visible);

        if (checkbox) {
            if (!visible && selectedLocationId !== '') {
                checkbox.checked = false;
            }

            checkbox.disabled = !visible;
        }

        if (visible) {
            visibleRoomCount += 1;
        }
    });

    const empty = studioFields.querySelector('[data-event-room-empty]');

    if (empty) {
        empty.classList.toggle('hidden', visibleRoomCount > 0);
        empty.textContent = selectedLocationId === ''
            ? empty.dataset.chooseLocation
            : empty.dataset.noRooms;
    }
}

function initEventForms(root = document) {
    root.querySelectorAll('[data-event-form]').forEach((form) => {
        if (form.dataset.eventFormReady === 'true') {
            return;
        }

        form.addEventListener('change', (event) => {
            if (event.target.matches('input[name="venue_kind"], [data-event-location]')) {
                updateEventVenueForm(form);
            }
        });

        form.dataset.eventFormReady = 'true';
        updateEventVenueForm(form);
    });
}

function updateTrialClassPassOverrideForm(form) {
    const planSelect = form.querySelector('[data-trial-plan-select]');
    const overrideFields = form.querySelector('[data-trial-override-fields]');
    const overrideToggle = form.querySelector('[data-trial-override-toggle]');
    const comment = form.querySelector('[data-trial-override-comment]');
    const reason = form.querySelector('[data-trial-override-reason]');

    if (!planSelect || !overrideFields || !overrideToggle || !comment || !reason) {
        return;
    }

    const selectedPlanIsTrial = planSelect.selectedOptions[0]?.dataset.isTrial === 'true';

    overrideFields.classList.toggle('hidden', !selectedPlanIsTrial);
    overrideToggle.disabled = !selectedPlanIsTrial;

    if (!selectedPlanIsTrial) {
        overrideToggle.checked = false;
    }

    const overrideEnabled = selectedPlanIsTrial && overrideToggle.checked;

    comment.classList.toggle('hidden', !overrideEnabled);
    reason.disabled = !overrideEnabled;
    reason.required = overrideEnabled;
}

function initTrialClassPassOverrideForms(root = document) {
    root.querySelectorAll('[data-trial-override-form]').forEach((form) => {
        form.addEventListener('change', (event) => {
            if (event.target.matches('[data-trial-plan-select], [data-trial-override-toggle]')) {
                updateTrialClassPassOverrideForm(form);
            }
        });

        updateTrialClassPassOverrideForm(form);
    });
}

function renameFestivalRubricStructure(form) {
    const sections = Array.from(form.querySelectorAll('[data-festival-rubric-section]'));
    const sectionLabelTemplate = form.dataset.sectionLabelTemplate || 'Section __NUMBER__';

    sections.forEach((section, sectionIndex) => {
        const sectionLabel = section.querySelector('[data-festival-rubric-section-label]');

        if (sectionLabel) {
            sectionLabel.textContent = sectionLabelTemplate.replace('__NUMBER__', String(sectionIndex + 1));
        }

        section.querySelectorAll('[data-section-field]').forEach((field) => {
            field.name = `sections[${sectionIndex}][${field.dataset.sectionField}]`;
        });

        const criteria = Array.from(section.querySelectorAll('[data-festival-rubric-criterion]'));

        criteria.forEach((criterion, criterionIndex) => {
            criterion.querySelectorAll('[data-criterion-field]').forEach((field) => {
                field.name = `sections[${sectionIndex}][criteria][${criterionIndex}][${field.dataset.criterionField}]`;
            });

            const removeCriterionButton = criterion.querySelector('[data-remove-rubric-criterion]');

            if (removeCriterionButton) {
                removeCriterionButton.disabled = criteria.length === 1;
            }
        });

        const removeSectionButton = section.querySelector('[data-remove-rubric-section]');

        if (removeSectionButton) {
            removeSectionButton.disabled = sections.length === 1;
        }
    });
}

function initFestivalRubricEditors(root = document) {
    root.querySelectorAll('[data-festival-rubric-editor]').forEach((form) => {
        const sectionsContainer = form.querySelector('[data-festival-rubric-sections]');
        const sectionTemplate = form.querySelector('[data-festival-rubric-section-template]');
        const criterionTemplate = form.querySelector('[data-festival-rubric-criterion-template]');

        if (!sectionsContainer || !sectionTemplate || !criterionTemplate) {
            return;
        }

        form.addEventListener('click', (event) => {
            const addSectionButton = event.target.closest('[data-add-rubric-section]');

            if (addSectionButton) {
                const section = sectionTemplate.content.cloneNode(true);

                sectionsContainer.append(section);
                renameFestivalRubricStructure(form);
                createIcons({ icons });
                sectionsContainer.lastElementChild?.querySelector('[data-section-field="name"]')?.focus();

                return;
            }

            const addCriterionButton = event.target.closest('[data-add-rubric-criterion]');

            if (addCriterionButton) {
                const section = addCriterionButton.closest('[data-festival-rubric-section]');
                const criteriaContainer = section?.querySelector('[data-festival-rubric-criteria]');

                if (!criteriaContainer) {
                    return;
                }

                criteriaContainer.append(criterionTemplate.content.cloneNode(true));
                renameFestivalRubricStructure(form);
                createIcons({ icons });
                criteriaContainer.lastElementChild?.querySelector('[data-criterion-field="name"]')?.focus();

                return;
            }

            const removeSectionButton = event.target.closest('[data-remove-rubric-section]');

            if (removeSectionButton && sectionsContainer.querySelectorAll('[data-festival-rubric-section]').length > 1) {
                removeSectionButton.closest('[data-festival-rubric-section]')?.remove();
                renameFestivalRubricStructure(form);

                return;
            }

            const removeCriterionButton = event.target.closest('[data-remove-rubric-criterion]');
            const criteriaContainer = removeCriterionButton?.closest('[data-festival-rubric-criteria]');

            if (removeCriterionButton && criteriaContainer?.querySelectorAll('[data-festival-rubric-criterion]').length > 1) {
                removeCriterionButton.closest('[data-festival-rubric-criterion]')?.remove();
                renameFestivalRubricStructure(form);
            }
        });

        renameFestivalRubricStructure(form);
    });
}

function initFestivalAnnouncementModal() {
    const modal = document.querySelector('[data-festival-announcement-modal]');

    if (!modal) {
        return;
    }

    const open = (opener = null) => {
        festivalAnnouncementModalOpener = opener;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
        window.requestAnimationFrame(() => modal.querySelector('input:not([type="hidden"]), textarea, button')?.focus());
    };
    const close = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
        festivalAnnouncementModalOpener?.focus();
        festivalAnnouncementModalOpener = null;
    };

    document.querySelectorAll('[data-festival-announcement-open]').forEach((button) => {
        button.addEventListener('click', () => open(button));
    });
    modal.querySelectorAll('[data-festival-announcement-close]').forEach((button) => {
        button.addEventListener('click', close);
    });
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            close();
        }
    });

    if (modal.dataset.open === 'true') {
        open();
    }
}

function initFestivalProgram() {
    const program = document.querySelector('[data-festival-program]');
    const modal = document.querySelector('[data-festival-program-modal]');

    if (!program || !modal) {
        return;
    }

    const form = modal.querySelector('[data-festival-program-form]');
    const title = modal.querySelector('[data-festival-program-modal-title]');
    const methodInput = form?.querySelector('[data-festival-program-method]');
    const editingIdInput = form?.querySelector('[data-festival-program-editing-id]');
    const typeInput = form?.querySelector('[data-festival-program-type]');
    const rootList = program.querySelector('[data-festival-program-list][data-parent-id=""]');
    const status = program.querySelector('[data-festival-program-status]');
    let draggedItem = null;
    let treeBeforeDrag = null;
    let savingOrder = false;

    if (!form || !title || !methodInput || !editingIdInput || !typeInput || !rootList) {
        return;
    }

    const fieldsForType = {
        performance: ['entry', 'times', 'publish'],
        rehearsal: ['entry', 'times', 'publish'],
        custom: ['name', 'times'],
        free_header: ['name'],
        category_header: ['category'],
    };

    const setFieldValue = (name, value) => {
        const field = form.elements.namedItem(name);

        if (!field) {
            return;
        }

        if (field instanceof RadioNodeList) {
            field.value = value ?? '';
            return;
        }

        if (field instanceof HTMLInputElement && field.type === 'checkbox') {
            field.checked = Boolean(value);
            return;
        }

        field.value = value ?? '';
    };

    const syncTypeFields = () => {
        const visibleFields = fieldsForType[typeInput.value] ?? [];

        form.querySelectorAll('[data-festival-program-field]').forEach((container) => {
            const fieldName = container.dataset.festivalProgramField;
            const visible = visibleFields.includes(fieldName) || (fieldName === 'reschedule' && editingIdInput.value !== '');

            container.classList.toggle('hidden', !visible);
            container.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach((field) => {
                field.disabled = !visible;
                field.required = visible && ['entry', 'category', 'name', 'times'].includes(fieldName);
            });
        });
    };

    const focusableElements = () => [...modal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
        .filter((element) => !element.closest('.hidden'));

    const openModal = (opener = null) => {
        festivalProgramModalOpener = opener;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
        syncTypeFields();
        window.requestAnimationFrame(() => focusableElements()[0]?.focus());
    };

    const closeModal = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
        festivalProgramModalOpener?.focus();
        festivalProgramModalOpener = null;
    };

    const resetAddForm = () => {
        form.reset();
        form.action = modal.dataset.storeAction;
        methodInput.disabled = true;
        editingIdInput.value = '';
        title.textContent = modal.dataset.addTitle;
        setFieldValue('type', 'performance');
        syncTypeFields();
    };

    const openEditForm = (button) => {
        const item = JSON.parse(button.dataset.programItem ?? '{}');

        form.reset();
        form.action = button.dataset.updateAction;
        methodInput.disabled = false;
        editingIdInput.value = item.id ?? '';
        title.textContent = modal.dataset.editTitle;
        ['type', 'festival_entry_id', 'festival_category_id', 'parent_id', 'name', 'starts_at', 'ends_at', 'notes', 'is_published'].forEach((name) => {
            setFieldValue(name, item[name]);
        });
        setFieldValue('reschedule_reason', '');
        syncTypeFields();
        openModal(button);
    };

    const directItems = (list) => [...list.children].filter((child) => child.matches('[data-festival-program-item]'));
    const childList = (item) => [...item.children].find((child) => child.matches('[data-festival-program-list]'));

    const syncTreePresentation = () => {
        program.querySelectorAll('[data-festival-program-list]').forEach((list) => {
            if (list !== rootList) {
                list.classList.toggle('hidden', directItems(list).length === 0);
            }
        });

        program.querySelectorAll('[data-festival-program-item]').forEach((item) => {
            const siblings = directItems(item.parentElement);
            const index = siblings.indexOf(item);
            const previousHeader = siblings.slice(0, index).reverse().find((sibling) => ['free_header', 'category_header'].includes(sibling.dataset.itemType));
            item.querySelector('[data-festival-program-up]')?.toggleAttribute('disabled', index <= 0 || savingOrder);
            item.querySelector('[data-festival-program-down]')?.toggleAttribute('disabled', index === -1 || index >= siblings.length - 1 || savingOrder);
            item.querySelector('[data-festival-program-indent]')?.toggleAttribute('disabled', !previousHeader || savingOrder);
            item.querySelector('[data-festival-program-outdent]')?.toggleAttribute('disabled', item.parentElement === rootList || savingOrder);
        });

        program.querySelectorAll('[data-festival-program-drag]').forEach((row) => {
            row.draggable = !savingOrder;
        });

        program.querySelectorAll('[data-festival-program-sort-control]').forEach((control) => {
            if (control.matches('[data-festival-program-drag-affordance]')) {
                control.toggleAttribute('disabled', savingOrder);
            } else if (savingOrder) {
                control.setAttribute('disabled', 'disabled');
            }
        });
    };

    const serializeTree = () => {
        const items = [];
        const visit = (list, parentId = null) => {
            directItems(list).forEach((item) => {
                const id = Number(item.dataset.itemId);
                items.push({ id, parent_id: parentId });
                const nestedList = childList(item);

                if (nestedList) {
                    visit(nestedList, id);
                }
            });
        };

        visit(rootList);

        return items;
    };

    const showOrderStatus = (message, isError = false) => {
        if (!status) {
            return;
        }

        status.textContent = message;
        status.classList.remove('hidden', 'bg-emerald-50', 'text-emerald-800', 'bg-rose-50', 'text-rose-800');
        status.classList.add(isError ? 'bg-rose-50' : 'bg-emerald-50', isError ? 'text-rose-800' : 'text-emerald-800');
    };

    const restoreTree = (html) => {
        rootList.innerHTML = html;
        createIcons({ icons });
        syncTreePresentation();
    };

    const saveOrder = async (previousTree) => {
        savingOrder = true;
        syncTreePresentation();

        try {
            const response = await fetch(program.dataset.orderUrl, {
                method: 'PATCH',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ items: serializeTree() }),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message ?? program.dataset.orderError);
            }

            showOrderStatus(payload.message);
        } catch (error) {
            restoreTree(previousTree);
            showOrderStatus(error instanceof Error ? error.message : program.dataset.orderError, true);
        } finally {
            savingOrder = false;
            syncTreePresentation();
        }
    };

    const mutateTree = (callback) => {
        if (savingOrder) {
            return;
        }

        const previousTree = rootList.innerHTML;
        callback();
        syncTreePresentation();

        if (previousTree !== rootList.innerHTML) {
            saveOrder(previousTree);
        }
    };

    document.querySelectorAll('[data-festival-program-add]').forEach((button) => {
        button.addEventListener('click', () => {
            resetAddForm();
            openModal(button);
        });
    });
    modal.querySelectorAll('[data-festival-program-close]').forEach((button) => button.addEventListener('click', closeModal));
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });
    modal.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = focusableElements();
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
    typeInput.addEventListener('change', syncTypeFields);

    program.addEventListener('click', (event) => {
        const editButton = event.target.closest('[data-festival-program-edit]');

        if (editButton) {
            openEditForm(editButton);
            return;
        }

        const item = event.target.closest('[data-festival-program-item]');

        if (!item) {
            return;
        }

        if (event.target.closest('[data-festival-program-up]')) {
            mutateTree(() => item.previousElementSibling?.before(item));
        } else if (event.target.closest('[data-festival-program-down]')) {
            mutateTree(() => item.nextElementSibling?.after(item));
        } else if (event.target.closest('[data-festival-program-indent]')) {
            mutateTree(() => {
                const siblings = directItems(item.parentElement);
                const previousHeader = siblings.slice(0, siblings.indexOf(item)).reverse().find((sibling) => ['free_header', 'category_header'].includes(sibling.dataset.itemType));

                if (previousHeader) {
                    childList(previousHeader)?.append(item);
                }
            });
        } else if (event.target.closest('[data-festival-program-outdent]')) {
            mutateTree(() => {
                const parentList = item.parentElement;
                const parentItem = parentList.closest('[data-festival-program-item]');
                parentItem?.after(item);
            });
        }
    });

    program.addEventListener('dragstart', (event) => {
        const row = event.target.closest('[data-festival-program-drag]');

        if (!row || savingOrder) {
            return;
        }

        draggedItem = row.closest('[data-festival-program-item]');
        treeBeforeDrag = rootList.innerHTML;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', draggedItem.dataset.itemId);
        window.requestAnimationFrame(() => draggedItem?.classList.add('opacity-50'));
    });
    program.addEventListener('dragover', (event) => {
        const targetItem = event.target.closest('[data-festival-program-item]');

        if (!draggedItem || !targetItem || targetItem === draggedItem || draggedItem.contains(targetItem)) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    });
    program.addEventListener('drop', (event) => {
        const targetItem = event.target.closest('[data-festival-program-item]');

        if (!draggedItem || !targetItem || targetItem === draggedItem || draggedItem.contains(targetItem)) {
            return;
        }

        event.preventDefault();
        const row = targetItem.querySelector(':scope > [data-festival-program-row]');
        const rectangle = row?.getBoundingClientRect();
        const relativeY = rectangle ? (event.clientY - rectangle.top) / rectangle.height : 0;
        const targetIsHeader = ['free_header', 'category_header'].includes(targetItem.dataset.itemType);

        if (targetIsHeader && relativeY >= 0.25 && relativeY <= 0.75) {
            childList(targetItem)?.append(draggedItem);
        } else if (relativeY < 0.5) {
            targetItem.before(draggedItem);
        } else {
            targetItem.after(draggedItem);
        }

        draggedItem.classList.remove('opacity-50');
        draggedItem = null;
        syncTreePresentation();

        if (treeBeforeDrag !== rootList.innerHTML) {
            saveOrder(treeBeforeDrag);
        }

        treeBeforeDrag = null;
    });
    program.addEventListener('dragend', () => {
        draggedItem?.classList.remove('opacity-50');
        draggedItem = null;
        treeBeforeDrag = null;
    });

    if (modal.dataset.autoOpen === 'true') {
        openModal();
    }

    syncTypeFields();
    syncTreePresentation();
}

function initFestivalSceneTabs() {
    document.querySelectorAll('[data-festival-scene-tabs]').forEach((tabContainer) => {
        const tabs = [...tabContainer.querySelectorAll('[role="tab"]')];

        tabContainer.addEventListener('keydown', (event) => {
            const currentIndex = tabs.indexOf(document.activeElement);

            if (currentIndex === -1 || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                return;
            }

            event.preventDefault();
            const nextIndex = event.key === 'Home'
                ? 0
                : event.key === 'End'
                    ? tabs.length - 1
                    : (currentIndex + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            tabs[nextIndex]?.click();
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initFestivalAnnouncementModal();
    initFestivalSceneTabs();
    initFestivalProgram();
    initEventScanner();
    initEventForms();
    createIcons({ icons });
    initSlugAutofill();
    initColorPickers();
    initStudioRulesEditors();
    initCustomerAutocomplete();
    initTrainerMultiSelect();
    initStudioLoginPickers();
    initClassPassPreviews();
    initCustomerAuthTabs();
    initTrainerFormTabs();
    initPublicPriceTabs();
    initClassPassPlanSorting();
    initPlatformSettingsTabs();
    initOwnerVoiceSettings();
    initAiProviderModels();
    initPlatformTelegramWebhook();
    initPhoneMasks();
    initOtpCountdowns();
    initFestivalRegistrantProfiles();
    initPrintButtons();
    initPeopleCounterMaskEditors();
    initScheduleSeriesForms();
    initManualClassModals();
    initTrainerSubstitutionModals();
    initScheduledClassTrainerModals();
    initTrainerIssueModals();
    initTrainerPermissionDetailsModal();
    initTrainerPrivateLessonsModal();
    initPaymentRefundModal();
    initTrainerPrivateTimeframes();
    initQuickBookingModals();
    initCustomerTransferModals();
    initCopyButtons();
    initOnboardingLogoPreviews();
    initOnboardingShareActions();
    initActiveScrollTargets();
    initVelvetScrollTop();
    initProfilePhoneMergeScroll();
    initAssistantChat();
    initAppUpdatePrompt();
    initPwaInstallPrompt();
    initPeopleCounterScreenshotViewer();
    initPublicPricingCalculators();
    initPublicCalendarSwipe();
    initPublicBookingModal();
    initSalaryModelForms();
    initIntegrationForms();
    initSmsSendingSettings();
    initTrialClassPassOverrideForms();
    initFestivalRubricEditors();
    syncPublicLegalReturnUrls();

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
        const bookingLink = event.target.closest('a[data-public-booking-open]');

        if (!bookingLink || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || bookingLink.target) {
            return;
        }

        event.preventDefault();
        fetchPublicBookingModal(bookingLink);
    });

    document.addEventListener('click', (event) => {
        const continueButton = event.target.closest('[data-public-booking-continue]');

        if (!continueButton) {
            return;
        }

        const continueUrl = continueButton.dataset.continueUrl;
        const modal = continueButton.closest('[data-public-booking-modal]');

        closePublicBookingModal(modal, false);

        if (continueUrl) {
            loadPublicScheduleUrl(continueUrl);
        }
    });

    document.addEventListener('submit', (event) => {
        const publicBookingForm = event.target.closest('form[data-public-booking-form]');

        if (!publicBookingForm) {
            return;
        }

        event.preventDefault();
        submitPublicBookingForm(publicBookingForm);
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
        const classTypeListToggle = event.target.closest('[data-class-type-list-toggle]');

        if (classTypeListToggle) {
            toggleClassTypeList(classTypeListToggle);
        }
    });

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-public-schedule-link]');

        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target) {
            return;
        }

        if (link.closest('[data-public-calendar]')?.dataset.swiping === 'true') {
            event.preventDefault();

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
    const asyncSuccessModal = document.querySelector('[data-async-success-modal]');
    const asyncSuccessClose = asyncSuccessModal?.querySelector('[data-async-success-close]');
    const cancelButton = modal?.querySelector('[data-confirm-cancel]');
    const acceptButton = modal?.querySelector('[data-confirm-accept]');
    const confirmTitle = modal?.querySelector('[data-confirm-title]');
    const confirmBody = modal?.querySelector('[data-confirm-body]');
    const confirmIcon = modal?.querySelector('[data-confirm-icon]');
    const confirmationPhraseContainer = modal?.querySelector('[data-confirm-phrase-container]');
    const confirmationPhraseLabel = modal?.querySelector('[data-confirm-phrase-label]');
    const confirmationPhraseInput = modal?.querySelector('[data-confirm-phrase-input]');
    const confirmationPhraseHelp = modal?.querySelector('[data-confirm-phrase-help]');

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

        pendingConfirmationPhrase = source.dataset.confirmPhrase || form.dataset.confirmPhrase || null;

        if (confirmationPhraseContainer && confirmationPhraseInput) {
            confirmationPhraseContainer.classList.toggle('hidden', !pendingConfirmationPhrase);
            confirmationPhraseInput.value = '';
            confirmationPhraseInput.placeholder = source.dataset.confirmPhrasePlaceholder
                || form.dataset.confirmPhrasePlaceholder
                || '';
            acceptButton.disabled = Boolean(pendingConfirmationPhrase);
        }

        if (confirmationPhraseLabel) {
            confirmationPhraseLabel.textContent = source.dataset.confirmPhraseLabel
                || form.dataset.confirmPhraseLabel
                || confirmationPhraseLabel.dataset.defaultText
                || confirmationPhraseLabel.textContent;
        }

        if (confirmationPhraseHelp) {
            confirmationPhraseHelp.textContent = source.dataset.confirmPhraseHelp
                || form.dataset.confirmPhraseHelp
                || confirmationPhraseHelp.dataset.defaultText
                || confirmationPhraseHelp.textContent;
        }
    };

    if (!modal || !cancelButton || !acceptButton) {
        return;
    }

    if (asyncSuccessModal && asyncSuccessClose) {
        asyncSuccessClose.addEventListener('click', () => closeAsyncSuccessModal(asyncSuccessModal));
        asyncSuccessModal.addEventListener('click', (event) => {
            if (event.target === asyncSuccessModal) {
                closeAsyncSuccessModal(asyncSuccessModal);
            }
        });
    }

    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented) {
            return;
        }

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

        if (pendingConfirmationPhrase && confirmationPhraseInput) {
            confirmationPhraseInput.focus();
        } else {
            acceptButton.focus();
        }
    });

    confirmationPhraseInput?.addEventListener('input', () => {
        acceptButton.disabled = Boolean(pendingConfirmationPhrase)
            && confirmationPhraseInput.value !== pendingConfirmationPhrase;
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

        if (event.key === 'Escape') {
            closeTrainerSubstitutionModal(document.querySelector('[data-trainer-substitution-modal]:not(.hidden)'));
        }

        if (event.key === 'Escape') {
            closeScheduledClassTrainerModal(document.querySelector('[data-scheduled-class-trainer-modal]:not(.hidden)'));
        }

        if (event.key === 'Escape') {
            closeTrainerIssuesModal(document.querySelector('[data-trainer-issues-modal]:not(.hidden)'));
        }

        if (event.key === 'Escape') {
            closeCustomerTransferModal(document.querySelector('[data-customer-transfer-modal]:not(.hidden)'));
        }

        if (event.key === 'Escape') {
            document.querySelectorAll('[data-assistant-clear-modal]:not(.hidden)').forEach((modal) => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            });
        }

        if (event.key === 'Escape' && pendingDeleteForm) {
            closeDeleteConfirmation(modal);
        }

        if (event.key === 'Escape' && asyncSuccessModal && !asyncSuccessModal.classList.contains('hidden')) {
            closeAsyncSuccessModal(asyncSuccessModal);
        }

        if (event.key === 'Escape') {
            const announcementModal = document.querySelector('[data-festival-announcement-modal]:not(.hidden)');
            announcementModal?.querySelector('[data-festival-announcement-close]')?.click();
        }
    });

    acceptButton.addEventListener('click', () => {
        if (!pendingDeleteForm) {
            return;
        }

        if (pendingConfirmationPhrase && confirmationPhraseInput?.value !== pendingConfirmationPhrase) {
            confirmationPhraseInput?.focus();

            return;
        }

        const approvalOutput = pendingDeleteForm.querySelector('[data-confirm-approval-output]');

        if (approvalOutput && confirmationPhraseInput) {
            approvalOutput.value = confirmationPhraseInput.value;
        }

        pendingDeleteForm.dataset.confirmed = 'true';

        if (pendingConfirmationSubmitter && document.body.contains(pendingConfirmationSubmitter)) {
            pendingDeleteForm.requestSubmit(pendingConfirmationSubmitter);
            return;
        }

        pendingDeleteForm.requestSubmit();
    });
});
