<nav class="flex flex-wrap items-center gap-2 rounded-2xl border border-stone-200 bg-white p-3 shadow-crm" aria-label="{{ __('app.festival_portal') }}">
    <a href="{{ route('festival.portal.dashboard', $account->slug) }}" class="crm-tab">{{ __('app.festival_calendar') }}</a>
    <a href="{{ route('festival.portal.participants.index', $account->slug) }}" class="crm-tab">{{ __('app.festival_participants') }}</a>
    <a href="{{ route('festival.portal.profile.edit', $account->slug) }}" class="crm-tab">{{ __('app.profile') }}</a>
    <form method="POST" action="{{ route('festival.portal.logout', $account->slug) }}" class="ml-auto">@csrf<button type="submit" class="px-3 py-2 text-sm font-semibold text-slate-600 hover:text-slate-950">{{ __('app.logout') }}</button></form>
</nav>
