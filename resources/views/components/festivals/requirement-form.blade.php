@props([
    'account',
    'edition',
    'requirement' => null,
    'deadlineReferences' => [],
    'resolvedDueAt' => null,
    'resolvedEditableUntil' => null,
])

@php
    $editing = $requirement?->exists;
    $pricing = $requirement?->pricing ?? ['mode' => 'none'];
    $options = collect(old('options', $requirement?->options ?? []))->pad(3, ['value' => '', 'label' => ''])->take(3);
    $dueRule = data_get($requirement?->validation, 'due_rule', []);
    $editableUntilRule = data_get($requirement?->validation, 'editable_until_rule', []);
    $dueReference = old('due_reference', data_get($dueRule, 'reference'));
    $dueOffsetDays = old('due_offset_days', data_get($dueRule, 'offset_days'));
    $allowPostConfirmationEdits = (bool) old('allow_post_confirmation_edits', data_get($requirement?->validation, 'allow_post_confirmation_edits', false));
    $editableUntilReference = old('editable_until_reference', data_get($editableUntilRule, 'reference'));
    $editableUntilOffsetDays = old('editable_until_offset_days', data_get($editableUntilRule, 'offset_days'));
@endphp

<form method="POST" action="{{ $editing ? route('dashboard.accounts.festivals.requirements.update', [$account, $edition, $requirement]) : route('dashboard.accounts.festivals.requirements.store', [$account, $edition]) }}" class="space-y-4">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    <section class="rounded-xl border border-stone-200 bg-slate-50/60 p-4 sm:p-5" data-requirement-section>
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.festival_requirement_section_definition') }}</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <x-ui.field-label for="requirement-name" :label="__('app.name')" :help="__('app.festival_registration_field_name_help')" />
                <input id="requirement-name" name="name" value="{{ old('name', $requirement?->name) }}" required class="crm-field">
                <x-ui.field-error name="name" />
                <x-ui.field-error name="code" />
            </div>
            <div>
                <x-ui.field-label for="requirement-type" :label="__('app.type')" :help="__('app.festival_registration_field_type_help')" />
                <select id="requirement-type" name="type" class="crm-field">
                    @foreach (\App\Enums\FestivalRequirementType::cases() as $type)
                        <option value="{{ $type->value }}" @selected(old('type', $requirement?->type?->value) === $type->value)>{{ __('app.festival_requirement_'.$type->value) }}</option>
                    @endforeach
                </select>
                <x-ui.field-error name="type" />
            </div>
            <div class="sm:col-span-2">
                <x-ui.field-label for="requirement-instructions" :label="__('app.instructions')" :help="__('app.festival_registration_field_instructions_help')" />
                <textarea id="requirement-instructions" name="instructions" rows="2" class="crm-field">{{ old('instructions', $requirement?->instructions) }}</textarea>
                <x-ui.field-error name="instructions" />
            </div>
        </div>
    </section>

    <section class="rounded-xl border border-stone-200 bg-slate-50/60 p-4 sm:p-5" data-requirement-section>
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.festival_requirement_section_placement') }}</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div>
                <x-ui.field-label for="requirement-workflow-step" :label="__('app.festival_registration_workflow_step')" :help="__('app.festival_registration_field_workflow_step_help')" />
                <select id="requirement-workflow-step" name="festival_workflow_step_id" required class="crm-field">
                    @foreach ($edition->workflows as $workflow)
                        @foreach ($workflow->steps as $step)
                            <option value="{{ $step->id }}" @selected((int) old('festival_workflow_step_id', $requirement?->festival_workflow_step_id) === $step->id)>{{ $workflow->name }} · {{ $step->title }}</option>
                        @endforeach
                    @endforeach
                </select>
                <x-ui.field-error name="festival_workflow_step_id" />
            </div>
            <div>
                <x-ui.field-label for="requirement-category" :label="__('app.festival_category')" :help="__('app.festival_registration_field_category_help')" />
                <select id="requirement-category" name="festival_category_id" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($edition->categories as $category)
                        <option value="{{ $category->id }}" @selected((int) old('festival_category_id', $requirement?->festival_category_id) === $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                <x-ui.field-error name="festival_category_id" />
            </div>
            <div>
                <x-ui.field-label for="requirement-answer-scope" :label="__('app.festival_field_scope')" :help="__('app.festival_registration_field_scope_help')" />
                <select id="requirement-answer-scope" name="subject_scope" class="crm-field">
                    @foreach (\App\Enums\FestivalFieldScope::cases() as $scope)
                        <option value="{{ $scope->value }}" @selected(old('subject_scope', $requirement?->subject_scope?->value ?? 'entry') === $scope->value)>{{ __('app.festival_scope_'.$scope->value) }}</option>
                    @endforeach
                </select>
                <x-ui.field-error name="subject_scope" />
            </div>
        </div>
    </section>

    <section class="rounded-xl border border-stone-200 bg-slate-50/60 p-4 sm:p-5" data-requirement-section>
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.festival_requirement_section_response') }}</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div>
                <x-ui.field-label for="requirement-input-type" :label="__('app.festival_input_type')" :help="__('app.festival_registration_field_input_type_help')" />
                <select id="requirement-input-type" name="input_type" class="crm-field">
                    @foreach (\App\Enums\FestivalRequirementInputType::cases() as $inputType)
                        <option value="{{ $inputType->value }}" @selected(old('input_type', $requirement?->input_type?->value ?? 'file') === $inputType->value)>{{ __('app.festival_input_'.$inputType->value) }}</option>
                    @endforeach
                </select>
                <x-ui.field-error name="input_type" />
            </div>
            <div>
                <x-ui.field-label for="requirement-max-file-size" :label="__('app.festival_max_file_kb')" :help="__('app.festival_registration_field_max_file_size_help')" />
                <input id="requirement-max-file-size" type="number" name="max_size_kb" value="{{ old('max_size_kb', $requirement?->max_size_kb ?? 20480) }}" min="1" class="crm-field">
                <x-ui.field-error name="max_size_kb" />
            </div>
            <div>
                <x-ui.field-label for="requirement-min-duration" :label="__('app.minimum_duration_seconds')" :help="__('app.festival_registration_field_min_duration_help')" />
                <input id="requirement-min-duration" type="number" min="1" name="min_duration_seconds" value="{{ old('min_duration_seconds', $requirement?->min_duration_seconds) }}" class="crm-field">
                <x-ui.field-error name="min_duration_seconds" />
            </div>
            <div>
                <x-ui.field-label for="requirement-max-duration" :label="__('app.maximum_duration_seconds')" :help="__('app.festival_registration_field_max_duration_help')" />
                <input id="requirement-max-duration" type="number" min="1" name="max_duration_seconds" value="{{ old('max_duration_seconds', $requirement?->max_duration_seconds) }}" class="crm-field">
                <x-ui.field-error name="max_duration_seconds" />
            </div>
            <div class="sm:col-span-2 xl:col-span-4">
                <x-ui.field-label for="requirement-option-label-0" :label="__('app.festival_select_options')" :help="__('app.festival_registration_field_options_help')" />
                <div class="mt-2 space-y-2">
                    @foreach ($options as $index => $option)
                        <div class="grid gap-2 sm:grid-cols-[2fr_1fr]">
                            @if (filled($option['value'] ?? null))
                                <input type="hidden" name="options[{{ $index }}][original_value]" value="{{ $option['value'] }}">
                            @endif
                            <div>
                                <label for="requirement-option-label-{{ $index }}" class="sr-only">{{ __('app.festival_option_label') }}</label>
                                <input id="requirement-option-label-{{ $index }}" name="options[{{ $index }}][label]" value="{{ $option['label'] ?? '' }}" placeholder="{{ __('app.festival_option_label') }}" class="crm-field">
                                <x-ui.field-error :name="'options.'.$index.'.label'" />
                            </div>
                            <div>
                                <label for="requirement-option-price-{{ $index }}" class="sr-only">{{ __('app.festival_amount', ['currency' => $account->default_currency]) }}</label>
                                <input id="requirement-option-price-{{ $index }}" type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" name="options[{{ $index }}][price]" value="{{ old('options.'.$index.'.price', data_get($pricing, 'prices.'.($option['value'] ?? '')) === null ? null : \App\Support\Payments\PaymentAmounts::centsToDecimalString((int) data_get($pricing, 'prices.'.($option['value'] ?? '')))) }}" placeholder="{{ __('app.festival_amount', ['currency' => $account->default_currency]) }}" class="crm-field">
                                <x-ui.field-error :name="'options.'.$index.'.price'" />
                            </div>
                        </div>
                    @endforeach
                </div>
                <x-ui.field-error name="options" />
            </div>
            <div>
                <x-ui.field-label for="requirement-allowed-extensions" :label="__('app.festival_allowed_extensions')" :help="__('app.festival_registration_field_extensions_help')" />
                <input id="requirement-allowed-extensions" name="allowed_extensions_text" value="{{ old('allowed_extensions_text', collect($requirement?->allowed_extensions ?? [])->join(', ')) }}" placeholder="mp3, mp4, pdf" class="crm-field">
                <x-ui.field-error name="allowed_extensions" />
                <x-ui.field-error name="allowed_extensions.*" />
            </div>
            <div>
                <x-ui.field-label for="requirement-allowed-mime-types" :label="__('app.festival_allowed_mime_types')" :help="__('app.festival_registration_field_mime_types_help')" />
                <input id="requirement-allowed-mime-types" name="allowed_mime_types_text" value="{{ old('allowed_mime_types_text', collect($requirement?->allowed_mime_types ?? [])->join(', ')) }}" placeholder="audio/mpeg, video/mp4" class="crm-field">
                <x-ui.field-error name="allowed_mime_types" />
                <x-ui.field-error name="allowed_mime_types.*" />
            </div>
            <div class="sm:col-span-2">
                <x-ui.field-label for="requirement-allowed-hosts" :label="__('app.festival_allowed_url_hosts')" :help="__('app.festival_registration_field_url_hosts_help')" />
                <input id="requirement-allowed-hosts" name="allowed_hosts_text" value="{{ old('allowed_hosts_text', collect(data_get($requirement?->validation, 'allowed_hosts', []))->join(', ')) }}" placeholder="youtube.com, instagram.com" class="crm-field">
                <x-ui.field-error name="allowed_hosts" />
                <x-ui.field-error name="allowed_hosts.*" />
            </div>
        </div>
    </section>

    <section class="rounded-xl border border-stone-200 bg-slate-50/60 p-4 sm:p-5" data-requirement-section>
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.festival_requirement_section_commercial') }}</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div>
                <x-ui.field-label for="requirement-pricing-mode" :label="__('app.festival_pricing_mode')" :help="__('app.festival_registration_field_pricing_mode_help')" />
                <select id="requirement-pricing-mode" name="pricing_mode" class="crm-field">
                    @foreach (['none', 'flat_when_true', 'per_unit', 'option_prices'] as $mode)
                        <option value="{{ $mode }}" @selected(old('pricing_mode', $pricing['mode'] ?? 'none') === $mode)>{{ __('app.festival_pricing_'.$mode) }}</option>
                    @endforeach
                </select>
                <x-ui.field-error name="pricing_mode" />
            </div>
            <div>
                <x-ui.field-label for="requirement-price-amount" :label="__('app.festival_amount', ['currency' => $account->default_currency])" :help="__('app.festival_registration_field_amount_help')" />
                <input id="requirement-price-amount" type="number" name="price_amount" min="0" max="999999.99" step="0.01" inputmode="decimal" value="{{ old('price_amount', isset($pricing['amount_cents']) || isset($pricing['unit_amount_cents']) ? \App\Support\Payments\PaymentAmounts::centsToDecimalString((int) ($pricing['amount_cents'] ?? $pricing['unit_amount_cents'])) : null) }}" class="crm-field">
                <x-ui.field-error name="price_amount" />
            </div>
            <div class="sm:col-span-2 xl:col-span-1">
                <x-ui.field-label for="requirement-due-reference" :label="__('app.festival_due_at')" :help="__('app.festival_registration_field_due_at_help')" />
                <select id="requirement-due-reference" name="due_reference" class="crm-field">
                    <option value="">{{ __('app.festival_deadline_none') }}</option>
                    @foreach ($deadlineReferences as $reference)
                        <option value="{{ $reference }}" @selected($dueReference === $reference)>{{ __('app.festival_deadline_reference_'.$reference) }}</option>
                    @endforeach
                </select>
                <x-ui.field-error name="due_reference" />
            </div>
            <div>
                <x-ui.field-label for="requirement-due-offset" :label="__('app.festival_deadline_offset_days')" :help="__('app.festival_deadline_offset_help')" />
                <input id="requirement-due-offset" type="number" min="-366" max="366" name="due_offset_days" value="{{ $dueOffsetDays }}" placeholder="-5" class="crm-field">
                <x-ui.field-error name="due_offset_days" />
            </div>
            @if ($resolvedDueAt)
                <div class="rounded-xl bg-white px-4 py-3 text-sm text-slate-700">
                    <span class="block text-xs font-semibold text-slate-500">{{ __('app.festival_resolved_deadline') }}</span>
                    <strong>{{ $resolvedDueAt->timezone($edition->timezone)->format('d.m.Y H:i') }}</strong>
                    @if ($requirement?->due_at && blank(data_get($dueRule, 'reference')))
                        <span class="mt-1 block text-xs text-amber-700">{{ __('app.festival_legacy_fixed_deadline_help') }}</span>
                    @endif
                </div>
            @endif
        </div>
    </section>

    <section class="rounded-xl border border-stone-200 bg-slate-50/60 p-4 sm:p-5" data-requirement-section>
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.festival_requirement_section_availability') }}</h2>
        <div class="mt-4 flex flex-wrap items-center gap-5">
            <input type="hidden" name="is_required" value="0">
            <div class="flex min-w-52 items-center gap-2">
                <input id="requirement-is-required" type="checkbox" name="is_required" value="1" @checked(old('is_required', $requirement?->is_required ?? true))>
                <x-ui.field-label for="requirement-is-required" :label="__('app.required')" :help="__('app.festival_registration_field_required_help')" />
            </div>
            <input type="hidden" name="is_active" value="0">
            <div class="flex min-w-52 items-center gap-2">
                <input id="requirement-is-active" type="checkbox" name="is_active" value="1" @checked(old('is_active', $requirement?->is_active ?? true))>
                <x-ui.field-label for="requirement-is-active" :label="__('app.active')" :help="__('app.festival_registration_field_active_help')" />
            </div>
            <input type="hidden" name="allow_post_confirmation_edits" value="0">
            <div class="flex min-w-52 items-center gap-2">
                <input id="requirement-allow-post-confirmation-edits" type="checkbox" name="allow_post_confirmation_edits" value="1" @checked($allowPostConfirmationEdits)>
                <x-ui.field-label for="requirement-allow-post-confirmation-edits" :label="__('app.festival_allow_post_confirmation_edits')" :help="__('app.festival_allow_post_confirmation_edits_help')" />
            </div>
        </div>
        <x-ui.field-error name="is_required" />
        <x-ui.field-error name="is_active" />
        <x-ui.field-error name="allow_post_confirmation_edits" />

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <x-ui.field-label for="requirement-editable-until-reference" :label="__('app.festival_editable_until')" :help="__('app.festival_editable_until_help')" />
                <select id="requirement-editable-until-reference" name="editable_until_reference" class="crm-field">
                    <option value="">{{ __('app.festival_deadline_none') }}</option>
                    @foreach ($deadlineReferences as $reference)
                        <option value="{{ $reference }}" @selected($editableUntilReference === $reference)>{{ __('app.festival_deadline_reference_'.$reference) }}</option>
                    @endforeach
                </select>
                <x-ui.field-error name="editable_until_reference" />
            </div>
            <div>
                <x-ui.field-label for="requirement-editable-until-offset" :label="__('app.festival_deadline_offset_days')" :help="__('app.festival_deadline_offset_help')" />
                <input id="requirement-editable-until-offset" type="number" min="-366" max="366" name="editable_until_offset_days" value="{{ $editableUntilOffsetDays }}" placeholder="10" class="crm-field">
                <x-ui.field-error name="editable_until_offset_days" />
            </div>
        </div>
        @if ($resolvedEditableUntil)
            <p class="mt-3 rounded-xl bg-white px-4 py-3 text-sm text-slate-700">{{ __('app.festival_resolved_editable_until', ['date' => $resolvedEditableUntil->timezone($edition->timezone)->format('d.m.Y H:i')]) }}</p>
        @endif
    </section>

    <div class="flex flex-wrap gap-2">
        <x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button>
        <x-ui.button :href="route('dashboard.accounts.festivals.settings.requirements', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
    </div>
</form>
