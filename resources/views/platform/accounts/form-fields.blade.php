@include('accounts.form-fields')

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.status') }}</span>
        <select name="status" class="crm-field">
            @foreach ($accountStatuses as $status)
                <option value="{{ $status->value }}" @selected(old('status', $account->status?->value ?? $account->status) === $status->value)>{{ __('app.'.$status->value) }}</option>
            @endforeach
        </select>
        @error('status') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    @if ($account->subscription?->usesLocationBilling())
        <div class="rounded-lg border border-violet-crm-100 bg-brand-50 p-4">
            <div class="crm-label">{{ __('app.subscription_plan') }}</div>
            <div class="mt-1 font-semibold text-slate-950">{{ $account->subscription->plan?->name ?? __('app.not_set') }}</div>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('app.billing_v2_managed_separately') }}</p>
            <input type="hidden" name="subscription_plan_id" value="{{ $account->subscription->subscription_plan_id }}">
            <input type="hidden" name="subscription_status" value="{{ $account->subscription->status->value }}">
            <input type="hidden" name="subscription_ends_at" value="{{ $account->subscription->ends_at?->timezone($account->timezone ?? config('app.timezone'))->toDateString() }}">
        </div>
    @else
        <label class="block">
            <span class="crm-label">{{ __('app.subscription_plan') }}</span>
            <select name="subscription_plan_id" class="crm-field">
                <option value="">-</option>
                @foreach ($plans as $plan)
                    <option value="{{ $plan->id }}" @selected((int) old('subscription_plan_id', $account->subscription?->subscription_plan_id) === $plan->id)>{{ $plan->name }}</option>
                @endforeach
            </select>
            @error('subscription_plan_id') <span class="crm-help">{{ $message }}</span> @enderror
        </label>
    @endif
</div>

@unless ($account->subscription?->usesLocationBilling())
<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.subscription_status') }}</span>
        <select name="subscription_status" class="crm-field">
            @foreach ($subscriptionStatuses as $status)
                <option value="{{ $status->value }}" @selected(old('subscription_status', $account->subscription?->status?->value ?? 'trialing') === $status->value)>{{ __('app.'.$status->value) }}</option>
            @endforeach
        </select>
        @error('subscription_status') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.subscription_ends_at') }}</span>
        <input name="subscription_ends_at" type="date" value="{{ old('subscription_ends_at', $account->subscription?->ends_at?->timezone($account->timezone ?? config('app.timezone'))->toDateString()) }}" class="crm-field">
        @error('subscription_ends_at') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>
@endunless

@unless ($account->exists)
    <section class="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <div class="text-sm font-semibold text-slate-950">{{ __('app.initial_owner') }}</div>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="crm-label">{{ __('app.name') }}</span>
                <input name="owner_name" value="{{ old('owner_name') }}" required class="crm-field">
                @error('owner_name') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.email') }}</span>
                <input name="owner_email" type="email" value="{{ old('owner_email') }}" required class="crm-field">
                @error('owner_email') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>
        <label class="mt-4 block">
            <span class="crm-label">{{ __('app.password') }}</span>
            <input name="owner_password" type="password" required class="crm-field">
            @error('owner_password') <span class="crm-help">{{ $message }}</span> @enderror
        </label>
    </section>
@endunless
