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

<label class="block">
    <span class="crm-label">{{ __('app.description') }}</span>
    <textarea name="description" rows="3" class="crm-field">{{ old('description', $plan->description) }}</textarea>
    @error('description') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<div class="grid gap-4 sm:grid-cols-3">
    <label class="block">
        <span class="crm-label">{{ __('app.price_cents') }}</span>
        <input name="price_cents" type="number" min="0" value="{{ old('price_cents', $plan->price_cents ?? 0) }}" required class="crm-field">
        @error('price_cents') <span class="crm-help">{{ $message }}</span> @enderror
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
