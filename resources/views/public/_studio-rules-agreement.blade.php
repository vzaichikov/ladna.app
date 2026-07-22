<label class="flex items-start gap-3 rounded-lg border border-stone-200 bg-slate-50 px-3 py-3 text-sm leading-6 text-slate-600">
    <input
        type="checkbox"
        name="studio_rules_accepted"
        value="1"
        checked
        required
        class="crm-checkbox mt-1"
    >
    <span>
        {{ __('app.accept_studio_rules_prefix') }}
        <a href="{{ route('public.studio-rules', ['accountSlug' => $account->slug, 'return_to' => request()->fullUrl()]) }}" target="_blank" rel="noopener" class="font-semibold text-brand-700 transition hover:text-brand-600" data-public-legal-link>
            {{ __('app.studio_rules_link_text') }}
        </a>.
    </span>
</label>
@error('studio_rules_accepted') <span class="crm-help">{{ $message }}</span> @enderror
