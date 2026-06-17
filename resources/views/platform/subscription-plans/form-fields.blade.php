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
            @foreach (config('charm.currencies') as $currency)
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

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.sort_order') }}</span>
        <input name="sort_order" type="number" min="0" value="{{ old('sort_order', $plan->sort_order ?? 0) }}" required class="crm-field">
        @error('sort_order') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="mt-7 flex items-center gap-3 text-sm font-medium text-slate-700">
        <input type="hidden" name="is_active" value="0">
        <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $plan->is_active)) class="crm-checkbox">
        {{ __('app.active') }}
    </label>
</div>
