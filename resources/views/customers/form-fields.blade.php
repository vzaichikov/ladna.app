<label class="block">
    <span class="crm-label">{{ __('app.person_name') }}</span>
    <input name="name" value="{{ old('name', $customer->name) }}" required class="crm-field">
    @error('name') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.phone') }}</span>
        <input name="phone" type="tel" value="{{ old('phone', $customer->phone) }}" class="crm-field" data-phone-mask data-country-code="{{ $account->country_code ?? 'UA' }}">
        @error('phone') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.email') }}</span>
        <input name="email" type="email" value="{{ old('email', $customer->email) }}" class="crm-field">
        @error('email') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.default_language') }}</span>
        <select name="default_language" class="crm-field">
            <option value="">{{ __('app.not_set') }}</option>
            @foreach (config('charm.locales') as $locale => $label)
                <option value="{{ $locale }}" @selected(old('default_language', $customer->default_language) === $locale)>{{ $label }}</option>
            @endforeach
        </select>
        @error('default_language') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.password') }}</span>
        <input name="password" type="password" class="crm-field">
        @error('password') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>
