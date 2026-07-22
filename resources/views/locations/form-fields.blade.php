<label class="block">
    <span class="crm-label">{{ __('app.name') }}</span>
    <input name="name" value="{{ old('name', $location->name) }}" required class="crm-field">
    @error('name') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<label class="block">
    <span class="crm-label">{{ __('app.slug') }}</span>
    <input name="slug" value="{{ old('slug', $location->slug) }}" class="crm-field">
    @error('slug') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<label class="block">
    <span class="crm-label">{{ __('app.address') }}</span>
    <textarea name="address" rows="3" class="crm-field">{{ old('address', $location->address) }}</textarea>
    @error('address') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<div class="block">
    <label for="google-maps-embed-url" class="crm-label">{{ __('app.google_maps_embed_url') }}</label>
    <textarea id="google-maps-embed-url" name="google_maps_embed_url" rows="3" class="crm-field" placeholder="{{ __('app.google_maps_embed_url_placeholder') }}">{{ old('google_maps_embed_url', $location->google_maps_embed_url) }}</textarea>
    <span class="mt-1 block text-sm text-slate-500">
        {{ __('app.google_maps_embed_url_help') }}
        <a href="{{ route('help.show', 'public-pages') }}#help-section-public-pages-google-maps-code" target="_blank" rel="noopener" class="font-semibold text-brand-700 transition hover:text-brand-600">{{ __('app.google_maps_embed_url_help_link') }}</a>
    </span>
    @error('google_maps_embed_url') <span class="crm-help">{{ $message }}</span> @enderror
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.phone') }}</span>
        <input name="phone" type="tel" value="{{ old('phone', $location->phone) }}" class="crm-field" data-phone-mask data-country-code="{{ $account->country_code ?? 'UA' }}">
        @error('phone') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.email') }}</span>
        <input name="email" type="email" value="{{ old('email', $location->email) }}" class="crm-field">
        @error('email') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.timezone') }}</span>
        <input name="timezone" value="{{ old('timezone', $location->timezone) }}" placeholder="Europe/Kyiv" class="crm-field">
        @error('timezone') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="flex items-center gap-3 pt-8 text-sm font-medium text-slate-700">
        <input type="hidden" name="is_active" value="0">
        <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $location->is_active)) class="crm-checkbox">
        {{ __('app.active') }}
    </label>
</div>
