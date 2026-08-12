@props(['account', 'edition', 'requirement' => null])

@php
    $editing = $requirement?->exists;
    $pricing = $requirement?->pricing ?? ['mode' => 'none'];
    $options = collect(old('options', $requirement?->options ?? []))->pad(3, ['value' => '', 'label' => ''])->take(3);
@endphp

<form method="POST" action="{{ $editing ? route('dashboard.accounts.festivals.requirements.update', [$account, $edition, $requirement]) : route('dashboard.accounts.festivals.requirements.store', [$account, $edition]) }}" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    <label>
        <span class="crm-label">{{ __('app.name') }}</span>
        <input name="name" value="{{ old('name', $requirement?->name) }}" required class="crm-field">
        <x-ui.field-error name="name" />
        <x-ui.field-error name="code" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.type') }}</span>
        <select name="type" class="crm-field">
            @foreach (\App\Enums\FestivalRequirementType::cases() as $type)
                <option value="{{ $type->value }}" @selected(old('type', $requirement?->type?->value) === $type->value)>{{ __('app.festival_requirement_'.$type->value) }}</option>
            @endforeach
        </select>
        <x-ui.field-error name="type" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_registration_workflow_step') }}</span>
        <select name="festival_workflow_step_id" required class="crm-field">
            @foreach ($edition->workflows as $workflow)
                @foreach ($workflow->steps as $step)
                    <option value="{{ $step->id }}" @selected((int) old('festival_workflow_step_id', $requirement?->festival_workflow_step_id) === $step->id)>{{ $workflow->name }} · {{ $step->title }}</option>
                @endforeach
            @endforeach
        </select>
        <x-ui.field-error name="festival_workflow_step_id" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_category') }}</span>
        <select name="festival_category_id" class="crm-field">
            <option value="">{{ __('app.all') }}</option>
            @foreach ($edition->categories as $category)
                <option value="{{ $category->id }}" @selected((int) old('festival_category_id', $requirement?->festival_category_id) === $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        <x-ui.field-error name="festival_category_id" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_field_scope') }}</span>
        <select name="subject_scope" class="crm-field">
            @foreach (\App\Enums\FestivalFieldScope::cases() as $scope)
                <option value="{{ $scope->value }}" @selected(old('subject_scope', $requirement?->subject_scope?->value ?? 'entry') === $scope->value)>{{ __('app.festival_scope_'.$scope->value) }}</option>
            @endforeach
        </select>
        <x-ui.field-error name="subject_scope" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_input_type') }}</span>
        <select name="input_type" class="crm-field">
            @foreach (\App\Enums\FestivalRequirementInputType::cases() as $inputType)
                <option value="{{ $inputType->value }}" @selected(old('input_type', $requirement?->input_type?->value ?? 'file') === $inputType->value)>{{ __('app.festival_input_'.$inputType->value) }}</option>
            @endforeach
        </select>
        <x-ui.field-error name="input_type" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_requirement_stage') }}</span>
        <select name="stage" class="crm-field">
            <option value="qualification" @selected(old('stage', $requirement?->stage) === 'qualification')>{{ __('app.festival_stage_qualification') }}</option>
            <option value="final" @selected(old('stage', $requirement?->stage ?? 'final') === 'final')>{{ __('app.festival_stage_final') }}</option>
        </select>
        <x-ui.field-error name="stage" />
    </label>
    <label class="sm:col-span-2 xl:col-span-4">
        <span class="crm-label">{{ __('app.instructions') }}</span>
        <textarea name="instructions" rows="2" class="crm-field">{{ old('instructions', $requirement?->instructions) }}</textarea>
        <x-ui.field-error name="instructions" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_pricing_mode') }}</span>
        <select name="pricing_mode" class="crm-field">
            @foreach (['none', 'flat_when_true', 'per_unit', 'option_prices'] as $mode)
                <option value="{{ $mode }}" @selected(old('pricing_mode', $pricing['mode'] ?? 'none') === $mode)>{{ __('app.festival_pricing_'.$mode) }}</option>
            @endforeach
        </select>
        <x-ui.field-error name="pricing_mode" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_amount', ['currency' => $account->default_currency]) }}</span>
        <input type="number" name="price_amount" min="0" max="999999.99" step="0.01" inputmode="decimal" value="{{ old('price_amount', isset($pricing['amount_cents']) || isset($pricing['unit_amount_cents']) ? \App\Support\Payments\PaymentAmounts::centsToDecimalString((int) ($pricing['amount_cents'] ?? $pricing['unit_amount_cents'])) : null) }}" class="crm-field">
        <x-ui.field-error name="price_amount" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_max_file_kb') }}</span>
        <input type="number" name="max_size_kb" value="{{ old('max_size_kb', $requirement?->max_size_kb ?? 20480) }}" min="1" class="crm-field">
        <x-ui.field-error name="max_size_kb" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_due_at') }}</span>
        <input type="datetime-local" name="due_at" value="{{ old('due_at', $requirement?->due_at?->format('Y-m-d\TH:i')) }}" class="crm-field">
        <x-ui.field-error name="due_at" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_allowed_extensions') }}</span>
        <input name="allowed_extensions_text" value="{{ old('allowed_extensions_text', collect($requirement?->allowed_extensions ?? [])->join(', ')) }}" placeholder="mp3, mp4, pdf" class="crm-field">
        <x-ui.field-error name="allowed_extensions" />
        <x-ui.field-error name="allowed_extensions.*" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_allowed_mime_types') }}</span>
        <input name="allowed_mime_types_text" value="{{ old('allowed_mime_types_text', collect($requirement?->allowed_mime_types ?? [])->join(', ')) }}" placeholder="audio/mpeg, video/mp4" class="crm-field">
        <x-ui.field-error name="allowed_mime_types" />
        <x-ui.field-error name="allowed_mime_types.*" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_allowed_url_hosts') }}</span>
        <input name="allowed_hosts_text" value="{{ old('allowed_hosts_text', collect(data_get($requirement?->validation, 'allowed_hosts', []))->join(', ')) }}" placeholder="youtube.com, instagram.com" class="crm-field">
        <x-ui.field-error name="allowed_hosts" />
        <x-ui.field-error name="allowed_hosts.*" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.minimum_duration_seconds') }}</span>
        <input type="number" min="1" name="min_duration_seconds" value="{{ old('min_duration_seconds', $requirement?->min_duration_seconds) }}" class="crm-field">
        <x-ui.field-error name="min_duration_seconds" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.maximum_duration_seconds') }}</span>
        <input type="number" min="1" name="max_duration_seconds" value="{{ old('max_duration_seconds', $requirement?->max_duration_seconds) }}" class="crm-field">
        <x-ui.field-error name="max_duration_seconds" />
    </label>

    <div class="sm:col-span-2 xl:col-span-4">
        <span class="crm-label">{{ __('app.festival_select_options') }}</span>
        <div class="mt-2 space-y-2">
            @foreach ($options as $index => $option)
                <div class="grid gap-2 sm:grid-cols-[2fr_1fr]">
                    @if (filled($option['value'] ?? null))
                        <input type="hidden" name="options[{{ $index }}][original_value]" value="{{ $option['value'] }}">
                    @endif
                    <div>
                        <input name="options[{{ $index }}][label]" value="{{ $option['label'] ?? '' }}" placeholder="{{ __('app.festival_option_label') }}" class="crm-field">
                        <x-ui.field-error :name="'options.'.$index.'.label'" />
                    </div>
                    <div>
                        <input type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" name="options[{{ $index }}][price]" value="{{ old('options.'.$index.'.price', data_get($pricing, 'prices.'.($option['value'] ?? '')) === null ? null : \App\Support\Payments\PaymentAmounts::centsToDecimalString((int) data_get($pricing, 'prices.'.($option['value'] ?? '')))) }}" placeholder="{{ __('app.festival_amount', ['currency' => $account->default_currency]) }}" class="crm-field">
                        <x-ui.field-error :name="'options.'.$index.'.price'" />
                    </div>
                </div>
            @endforeach
        </div>
        <x-ui.field-error name="options" />
    </div>

    <div class="sm:col-span-2 xl:col-span-4">
        <div class="flex flex-wrap items-center gap-5">
            <input type="hidden" name="is_required" value="0">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_required" value="1" @checked(old('is_required', $requirement?->is_required ?? true))>{{ __('app.required') }}</label>
            <input type="hidden" name="is_active" value="0">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $requirement?->is_active ?? true))>{{ __('app.active') }}</label>
        </div>
        <x-ui.field-error name="is_required" />
        <x-ui.field-error name="is_active" />
    </div>
    <div class="flex flex-wrap gap-2 sm:col-span-2 xl:col-span-4">
        <x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button>
        <x-ui.button :href="route('dashboard.accounts.festivals.settings.requirements', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
    </div>
</form>
