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

<div class="grid gap-4 sm:grid-cols-[auto_1fr] sm:items-center">
    <div class="flex h-20 w-20 items-center justify-center rounded-xl border border-stone-200 bg-brand-50">
        @if ($account->exists || $account->logo_path)
            <img src="{{ $account->logoUrl() }}" alt="" class="max-h-14 max-w-14 object-contain">
        @else
            <x-ui.icon name="accounts" class="h-7 w-7 text-brand-600" />
        @endif
    </div>
    <label class="block">
        <span class="crm-label">{{ __('app.studio_logo') }}</span>
        <input name="logo" type="file" accept="image/png,image/jpeg,image/webp" class="crm-field">
        <span class="mt-1 block text-xs font-medium text-slate-500">{{ __('app.logo_help') }}</span>
        @error('logo') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.default_language') }}</span>
        <select name="default_language" class="crm-field">
            @foreach (config('ladna.locales') as $value => $label)
                <option value="{{ $value }}" @selected(old('default_language', $account->default_language) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('default_language') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.country') }}</span>
        <select name="country_code" class="crm-field">
            @foreach (config('ladna.countries') as $value => $label)
                <option value="{{ $value }}" @selected(old('country_code', $account->country_code ?? 'UA') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('country_code') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.currency') }}</span>
        <select name="default_currency" class="crm-field">
            @foreach (config('ladna.currencies') as $currency)
                <option value="{{ $currency }}" @selected(old('default_currency', $account->default_currency) === $currency)>{{ $currency }}</option>
            @endforeach
        </select>
        @error('default_currency') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.legal_entity_name') }}</span>
        <input name="legal_entity_name" value="{{ old('legal_entity_name', $account->legal_entity_name) }}" class="crm-field">
        @error('legal_entity_name') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.tax_id') }}</span>
        <input name="tax_id" value="{{ old('tax_id', $account->tax_id) }}" class="crm-field">
        @error('tax_id') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.brand_color') }}</span>
        <input name="brand_color" value="{{ old('brand_color', $account->brand_color) }}" placeholder="#3B223F" class="crm-field">
        @error('brand_color') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.timezone') }}</span>
        <input name="timezone" value="{{ old('timezone', $account->timezone) }}" placeholder="Europe/Kyiv" class="crm-field">
        @error('timezone') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>
