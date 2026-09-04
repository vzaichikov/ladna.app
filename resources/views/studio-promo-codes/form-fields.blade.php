@php
    $selectedPlanIds = old('class_pass_plan_ids');
    if ($selectedPlanIds === null) {
        $selectedPlanIds = $studioPromoCode->relationLoaded('classPassPlans')
            ? $studioPromoCode->classPassPlans->pluck('id')->all()
            : [];
    }
    $selectedPlanIds = collect($selectedPlanIds)->map(fn ($id) => (int) $id)->all();
    $discountType = old('discount_type', $studioPromoCode->discount_type?->value ?? 'percent');
    $discountAmount = old('discount_amount', $studioPromoCode->exists
        ? ($studioPromoCode->discount_type === \App\Enums\PromoCodeDiscountType::Fixed
            ? \App\Support\Payments\PaymentAmounts::centsToDecimalString($studioPromoCode->discount_value)
            : $studioPromoCode->discount_value)
        : 10);
    $startsAt = $studioPromoCode->starts_at?->copy()->timezone($account->timezone)->format('Y-m-d\TH:i');
    $endsAt = $studioPromoCode->ends_at?->copy()->timezone($account->timezone)->format('Y-m-d\TH:i');
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.name') }}</span>
        <input name="name" value="{{ old('name', $studioPromoCode->name) }}" required class="crm-field">
        @error('name') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.promo_code') }}</span>
        <input name="code" value="{{ old('code', $studioPromoCode->code) }}" required maxlength="64" class="crm-field font-mono uppercase" autocomplete="off">
        <span class="crm-help">{{ __('app.promo_code_format_help') }}</span>
        @error('code') <span class="crm-help text-rose-600">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.discount_type') }}</span>
        <select name="discount_type" required class="crm-field">
            <option value="percent" @selected($discountType === 'percent')>{{ __('app.discount_type_percent') }}</option>
            <option value="fixed" @selected($discountType === 'fixed')>{{ __('app.discount_type_fixed') }}</option>
        </select>
        @error('discount_type') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.discount_amount') }}</span>
        <input name="discount_amount" value="{{ $discountAmount }}" inputmode="decimal" required class="crm-field">
        <span class="crm-help">{{ __('app.discount_amount_help', ['currency' => $account->default_currency]) }}</span>
        @error('discount_amount') <span class="crm-help text-rose-600">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.starts_at') }}</span>
        <input name="starts_at" type="datetime-local" value="{{ old('starts_at', $startsAt) }}" required class="crm-field">
        @error('starts_at') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.ends_at') }}</span>
        <input name="ends_at" type="datetime-local" value="{{ old('ends_at', $endsAt) }}" required class="crm-field">
        @error('ends_at') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.promo_code_total_limit') }}</span>
        <input name="max_total_uses" type="number" min="1" value="{{ old('max_total_uses', $studioPromoCode->max_total_uses) }}" class="crm-field">
        <span class="crm-help">{{ __('app.promo_code_blank_unlimited') }}</span>
        @error('max_total_uses') <span class="crm-help text-rose-600">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.promo_code_identity_limit') }}</span>
        <input name="max_uses_per_identity" type="number" min="1" value="{{ old('max_uses_per_identity', $studioPromoCode->max_uses_per_identity) }}" class="crm-field">
        <span class="crm-help">{{ __('app.promo_code_blank_unlimited') }}</span>
        @error('max_uses_per_identity') <span class="crm-help text-rose-600">{{ $message }}</span> @enderror
    </label>
</div>

<fieldset>
    <legend class="crm-label">{{ __('app.promo_code_class_pass_plans') }}</legend>
    <p class="mt-1 text-sm text-slate-500">{{ __('app.promo_code_class_pass_plans_help') }}</p>
    <div class="mt-3 space-y-4">
        @forelse ($classPassPlans->groupBy(fn ($plan) => $plan->schedule_kind->value) as $scheduleKind => $plans)
            <section class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <h2 class="text-sm font-semibold text-slate-950">{{ __('app.'.(\App\Support\ScheduleKindRegistry::all()[$scheduleKind]['title_key'] ?? $scheduleKind)) }}</h2>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach ($plans as $plan)
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-700">
                            <input name="class_pass_plan_ids[]" type="checkbox" value="{{ $plan->id }}" @checked(in_array($plan->id, $selectedPlanIds, true)) class="crm-checkbox">
                            <span>{{ $plan->name }} · {{ \App\Support\MoneyFormatter::format($plan->price_cents, $plan->currency) }}</span>
                        </label>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('app.no_class_pass_plans') }}</div>
        @endforelse
    </div>
    @error('class_pass_plan_ids') <span class="crm-help text-rose-600">{{ $message }}</span> @enderror
    @error('class_pass_plan_ids.*') <span class="crm-help text-rose-600">{{ $message }}</span> @enderror
</fieldset>

<label class="flex items-center gap-3 text-sm font-medium text-slate-700">
    <input type="hidden" name="is_active" value="0">
    <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $studioPromoCode->is_active)) class="crm-checkbox">
    {{ __('app.active') }}
</label>
