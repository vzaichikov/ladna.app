<section
    class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6"
    data-festival-application-fragment
    data-festival-application-fragment-key="entry-profile"
    data-festival-quick-profile-summary
    data-profile-first-name="{{ $portalUser->first_name }}"
    data-profile-last-name="{{ $portalUser->last_name }}"
    data-profile-city="{{ $portalUser->city }}"
    data-profile-studio-name="{{ $portalUser->studio_name }}"
>
    <div
        data-async-form-status
        data-error-message="{{ __('app.async_request_failed') }}"
        data-validation-message="{{ __('app.async_validation_failed') }}"
        class="hidden"
        role="status"
        aria-live="polite"
    ></div>
    <h2 class="text-xl font-semibold">{{ __('app.festival_profile_contacts') }}</h2>
    <p class="mt-1 text-sm text-slate-500">{{ $portalUser->displayName() }} · {{ $portalUser->email }}</p>
    <dl class="mt-4 grid gap-4 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-3">
        <div><dt class="font-semibold text-slate-500">{{ __('app.phone') }}</dt><dd class="mt-1 text-slate-950">{{ $portalUser->phone }}</dd></div>
        <div><dt class="font-semibold text-slate-500">{{ __('app.city') }}</dt><dd class="mt-1 text-slate-950">{{ $portalUser->city }}</dd></div>
        <div><dt class="font-semibold text-slate-500">{{ __('app.studio') }}</dt><dd class="mt-1 text-slate-950">{{ $portalUser->studio_name }}</dd></div>
    </dl>
    <button
        type="button"
        class="mt-3 inline-flex text-sm font-semibold text-brand-700 crm-focus"
        aria-haspopup="dialog"
        aria-controls="festival-quick-profile-modal"
        data-festival-quick-profile-open
    >{{ __('app.edit_profile') }}</button>
</section>
