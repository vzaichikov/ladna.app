@props(['account', 'edition', 'fee' => null])

@php($editing = $fee?->exists)

<form method="POST" action="{{ $editing ? route('dashboard.accounts.festivals.charge-definitions.update', [$account, $edition, $fee]) : route('dashboard.accounts.festivals.charge-definitions.store', [$account, $edition]) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    <label>
        <span class="crm-label">{{ __('app.name') }}</span>
        <input name="name" value="{{ old('name', $fee?->name) }}" required class="crm-field">
        <x-ui.field-error name="name" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.type') }}</span>
        <select name="kind" class="crm-field">
            @foreach (['qualification', 'participation', 'late', 'custom'] as $kind)
                <option value="{{ $kind }}" @selected(old('kind', $fee?->kind) === $kind)>{{ __('app.festival_charge_kind_'.$kind) }}</option>
            @endforeach
        </select>
        <x-ui.field-error name="kind" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_amount', ['currency' => $account->default_currency]) }}</span>
        <input type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" name="amount" value="{{ old('amount', $fee?->amount_cents === null ? null : \App\Support\Payments\PaymentAmounts::centsToDecimalString($fee->amount_cents)) }}" required class="crm-field">
        <x-ui.field-error name="amount" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_charge_pricing_mode') }}</span>
        <select name="pricing_mode" class="crm-field">
            @foreach (\App\Enums\FestivalChargePricingMode::cases() as $mode)
                <option value="{{ $mode->value }}" @selected(old('pricing_mode', $fee?->pricing_mode?->value ?? 'fixed') === $mode->value)>{{ __('app.festival_charge_pricing_mode_'.$mode->value) }}</option>
            @endforeach
        </select>
        <x-ui.field-error name="pricing_mode" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_charge_included_members') }}</span>
        <input type="number" min="1" max="100" name="included_members" value="{{ old('included_members', $fee?->included_members) }}" class="crm-field">
        <x-ui.field-error name="included_members" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_charge_additional_member_amount', ['currency' => $account->default_currency]) }}</span>
        <input type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" name="additional_member_amount" value="{{ old('additional_member_amount', $fee?->additional_member_amount_cents === null ? null : \App\Support\Payments\PaymentAmounts::centsToDecimalString($fee->additional_member_amount_cents)) }}" class="crm-field">
        <x-ui.field-error name="additional_member_amount" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_category') }}</span>
        <select name="festival_category_id" class="crm-field">
            <option value="">{{ __('app.all') }}</option>
            @foreach ($edition->categories as $category)
                <option value="{{ $category->id }}" @selected((int) old('festival_category_id', $fee?->festival_category_id) === $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        <x-ui.field-error name="festival_category_id" />
    </label>
    <label class="sm:col-span-2">
        <span class="crm-label">{{ __('app.festival_registration_workflow_step') }}</span>
        <select name="festival_workflow_step_id" required class="crm-field">
            @foreach ($edition->workflows as $workflow)
                @foreach ($workflow->steps as $step)
                    <option value="{{ $step->id }}" @selected((int) old('festival_workflow_step_id', $fee?->festival_workflow_step_id) === $step->id)>{{ $workflow->name }} · {{ $step->title }}</option>
                @endforeach
            @endforeach
        </select>
        <x-ui.field-error name="festival_workflow_step_id" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_due_at') }}</span>
        <input type="datetime-local" name="due_at" value="{{ old('due_at', $fee?->due_at?->format('Y-m-d\TH:i')) }}" class="crm-field">
        <x-ui.field-error name="due_at" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_charge_due_policy') }}</span>
        <select name="due_policy" class="crm-field">
            @foreach (\App\Enums\FestivalChargeDuePolicy::cases() as $policy)
                <option value="{{ $policy->value }}" @selected(old('due_policy', $fee?->due_policy?->value ?? 'fixed') === $policy->value)>{{ __('app.festival_charge_due_policy_'.$policy->value) }}</option>
            @endforeach
        </select>
        <x-ui.field-error name="due_policy" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_charge_due_days_after_approval') }}</span>
        <input type="number" min="0" max="365" name="due_days_after_approval" value="{{ old('due_days_after_approval', $fee?->due_days_after_approval) }}" class="crm-field">
        <x-ui.field-error name="due_days_after_approval" />
    </label>
    <label>
        <span class="crm-label">{{ __('app.festival_charge_due_hard_cap') }}</span>
        <input type="datetime-local" name="due_hard_cap_at" value="{{ old('due_hard_cap_at', $fee?->due_hard_cap_at?->format('Y-m-d\TH:i')) }}" class="crm-field">
        <x-ui.field-error name="due_hard_cap_at" />
    </label>
    <div class="pb-3">
        <label class="flex items-center gap-2 pt-8 text-sm">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $fee?->is_active ?? true))>
            {{ __('app.active') }}
        </label>
        <x-ui.field-error name="is_active" />
    </div>
    <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-4">
        <x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button>
        <x-ui.button :href="route('dashboard.accounts.festivals.settings.fees', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
    </div>
</form>
