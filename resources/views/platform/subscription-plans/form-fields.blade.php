<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.name') }}</span>
        <input name="name" value="{{ old('name', $plan->name) }}" required class="crm-field">
        @error('name') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.slug') }}</span>
        <input name="slug" value="{{ old('slug', $plan->slug) }}" class="crm-field">
        @error('slug') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

@php
    $packageRows = old('festival_packages', $festivalPackages->map(fn ($package) => [
        'id' => data_get($package, 'id'),
        'name' => data_get($package, 'name'),
        'price_uah' => number_format((int) data_get($package, 'price_cents', 0) / 100, 2, '.', ''),
        'max_participants' => data_get($package, 'max_participants'),
        'max_tickets' => data_get($package, 'max_tickets'),
        'is_active' => (bool) data_get($package, 'is_active', true),
    ])->all());
@endphp

<section class="rounded-xl border border-indigo-200 bg-indigo-50/40 p-4">
    <div>
        <h2 class="text-base font-semibold text-slate-950">{{ __('app.festival_tariff_packages') }}</h2>
        <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.festival_tariff_packages_help') }}</p>
    </div>
    <div class="mt-4 space-y-3">
        @foreach ($packageRows as $index => $package)
            <div class="grid gap-3 rounded-lg border border-white bg-white p-3 shadow-xs sm:grid-cols-2 xl:grid-cols-[100px_150px_1fr_1fr_auto]">
                @if (filled($package['id'] ?? null))
                    <input type="hidden" name="festival_packages[{{ $index }}][id]" value="{{ $package['id'] }}">
                @endif
                <label>
                    <span class="crm-label">{{ __('app.package') }}</span>
                    <input name="festival_packages[{{ $index }}][name]" value="{{ $package['name'] }}" required class="crm-field">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.festival_package_price_uah') }}</span>
                    <input name="festival_packages[{{ $index }}][price_uah]" type="number" min="0" step="0.01" value="{{ $package['price_uah'] }}" required class="crm-field">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.festival_participant_limit') }}</span>
                    <input name="festival_packages[{{ $index }}][max_participants]" type="number" min="1" value="{{ $package['max_participants'] }}" required class="crm-field">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.festival_ticket_limit') }}</span>
                    <input name="festival_packages[{{ $index }}][max_tickets]" type="number" min="1" value="{{ $package['max_tickets'] }}" required class="crm-field">
                </label>
                <label class="flex items-center gap-2 self-end pb-2 text-sm font-medium text-slate-700">
                    <input type="hidden" name="festival_packages[{{ $index }}][is_active]" value="0">
                    <input name="festival_packages[{{ $index }}][is_active]" type="checkbox" value="1" @checked($package['is_active']) class="crm-checkbox">
                    {{ __('app.active') }}
                </label>
            </div>
        @endforeach
    </div>
    @error('festival_packages') <span class="crm-help">{{ $message }}</span> @enderror
</section>

<label class="block">
    <span class="crm-label">{{ __('app.description') }}</span>
    <textarea name="description" rows="3" class="crm-field">{{ old('description', $plan->description) }}</textarea>
    @error('description') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <label class="block">
        <span class="crm-label">{{ __('app.price_uah') }}</span>
        <input name="price_uah" type="number" min="0" step="0.01" value="{{ old('price_uah', isset($plan->price_cents) ? number_format($plan->price_cents / 100, 2, '.', '') : '0.00') }}" required class="crm-field">
        @error('price_uah') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.sms_segment_price_uah') }}</span>
        <input name="sms_segment_price_uah" type="number" min="0" step="0.01" value="{{ old('sms_segment_price_uah', isset($plan->sms_segment_price_cents) ? number_format($plan->sms_segment_price_cents / 100, 2, '.', '') : '') }}" class="crm-field">
        <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.sms_segment_price_uah_hint') }}</span>
        @error('sms_segment_price_uah') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.currency') }}</span>
        <select name="currency" class="crm-field">
            @foreach (config('ladna.currencies') as $currency)
                <option value="{{ $currency }}" @selected(old('currency', $plan->currency) === $currency)>{{ $currency }}</option>
            @endforeach
        </select>
        @error('currency') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.billing_interval') }}</span>
        <select name="billing_interval" class="crm-field">
            @foreach (['monthly', 'yearly'] as $interval)
                <option value="{{ $interval }}" @selected(old('billing_interval', $plan->billing_interval) === $interval)>{{ __('app.'.$interval) }}</option>
            @endforeach
        </select>
        @error('billing_interval') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-3">
    <label class="block">
        <span class="crm-label">{{ __('app.subscription_plan_type') }}</span>
        <select name="plan_type" class="crm-field">
            @foreach (\App\Enums\SubscriptionPlanType::cases() as $type)
                <option value="{{ $type->value }}" @selected(old('plan_type', $plan->plan_type?->value ?? $plan->plan_type ?? 'standard') === $type->value)>{{ __('app.subscription_plan_type_'.$type->value) }}</option>
            @endforeach
        </select>
        @error('plan_type') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.access_days') }}</span>
        <input name="access_days" type="number" min="1" value="{{ old('access_days', $plan->access_days) }}" class="crm-field">
        @error('access_days') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.renewal_lead_days') }}</span>
        <input name="renewal_lead_days" type="number" min="0" max="30" value="{{ old('renewal_lead_days', $plan->renewal_lead_days ?? 2) }}" required class="crm-field">
        @error('renewal_lead_days') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.sort_order') }}</span>
        <input name="sort_order" type="number" min="0" value="{{ old('sort_order', $plan->sort_order ?? 0) }}" required class="crm-field">
        @error('sort_order') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <div class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
        <label class="flex items-center gap-3 text-sm font-medium text-slate-700">
            <input type="hidden" name="is_active" value="0">
            <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $plan->is_active)) class="crm-checkbox">
            {{ __('app.active') }}
        </label>
        <label class="flex items-center gap-3 text-sm font-medium text-slate-700">
            <input type="hidden" name="public_signup_enabled" value="0">
            <input name="public_signup_enabled" type="checkbox" value="1" @checked(old('public_signup_enabled', $plan->public_signup_enabled)) class="crm-checkbox">
            {{ __('app.public_signup_enabled') }}
        </label>
        <label class="flex items-center gap-3 text-sm font-medium text-slate-700">
            <input type="hidden" name="requires_recurring_payment" value="0">
            <input name="requires_recurring_payment" type="checkbox" value="1" @checked(old('requires_recurring_payment', $plan->requires_recurring_payment)) class="crm-checkbox">
            {{ __('app.requires_recurring_payment') }}
        </label>
    </div>
</div>
