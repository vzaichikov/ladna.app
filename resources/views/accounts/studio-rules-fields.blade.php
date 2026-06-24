<input type="hidden" name="brand_tab" value="rules">
<input type="hidden" name="name" value="{{ $account->name }}">
<input type="hidden" name="slug" value="{{ $account->slug }}">
<input type="hidden" name="default_language" value="{{ $account->default_language }}">
<input type="hidden" name="country_code" value="{{ $account->country_code ?? 'UA' }}">
<input type="hidden" name="default_currency" value="{{ $account->default_currency }}">
<input type="hidden" name="brand_color" value="{{ $account->brand_color }}">
<input type="hidden" name="timezone" value="{{ $account->timezone }}">

<label class="block">
    <span class="crm-label">{{ __('app.studio_rules') }}</span>
    <span class="mt-1 block text-sm leading-6 text-slate-500">{{ __('app.studio_rules_help') }}</span>
    <textarea
        name="studio_rules_html"
        rows="18"
        class="crm-field min-h-96"
        data-studio-rules-editor
        data-placeholder="{{ __('app.studio_rules_editor_placeholder') }}"
    >{{ old('studio_rules_html', $account->studio_rules_html) }}</textarea>
    @error('studio_rules_html') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<div class="rounded-lg border border-stone-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
    {{ __('app.studio_rules_public_help') }}
    <a href="{{ route('public.studio-rules', $account->slug) }}" target="_blank" rel="noopener" class="font-semibold text-brand-700 transition hover:text-brand-600">
        {{ __('app.open_public_studio_rules') }}
    </a>
</div>
