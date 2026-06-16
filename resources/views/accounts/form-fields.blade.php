<label class="block">
    <span class="crm-label">{{ __('app.name') }}</span>
    <input name="name" value="{{ old('name', $account->name) }}" required class="crm-field">
    @error('name') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<label class="block">
    <span class="crm-label">{{ __('app.slug') }}</span>
    <input name="slug" value="{{ old('slug', $account->slug) }}" class="crm-field">
    @error('slug') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.default_language') }}</span>
        <select name="default_language" class="crm-field">
            @foreach (config('charm.locales') as $value => $label)
                <option value="{{ $value }}" @selected(old('default_language', $account->default_language) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('default_language') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.currency') }}</span>
        <select name="default_currency" class="crm-field">
            @foreach (config('charm.currencies') as $currency)
                <option value="{{ $currency }}" @selected(old('default_currency', $account->default_currency) === $currency)>{{ $currency }}</option>
            @endforeach
        </select>
        @error('default_currency') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.brand_color') }}</span>
        <input name="brand_color" value="{{ old('brand_color', $account->brand_color) }}" placeholder="#e11d48" class="crm-field">
        @error('brand_color') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.timezone') }}</span>
        <input name="timezone" value="{{ old('timezone', $account->timezone) }}" placeholder="Europe/Kyiv" class="crm-field">
        @error('timezone') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>
