@php($portalUser = $portalUser ?? request()->user('festival'))
@php($isJudge = $portalUser?->role === \App\Enums\FestivalPortalRole::Judge)
<nav class="flex flex-wrap items-center gap-2 rounded-2xl border border-stone-200 bg-white p-3 shadow-crm" aria-label="{{ __('app.festival_portal') }}">
    @if ($isJudge)
        <a href="{{ route('festival.portal.judge.dashboard', $account->slug) }}" class="crm-tab">{{ __('app.festival_judge_cabinet') }}</a>
        <a href="{{ route('festival.portal.judge.profile.edit', $account->slug) }}" class="crm-tab">{{ __('app.profile') }}</a>
    @else
        <a href="{{ route('festival.portal.dashboard', $account->slug) }}" class="crm-tab">{{ __('app.festival_calendar') }}</a>
        <a href="{{ route('festival.portal.participants.index', $account->slug) }}" class="crm-tab">{{ __('app.festival_participants') }}</a>
        <a href="{{ route('festival.portal.profile.edit', $account->slug) }}" class="crm-tab">{{ __('app.profile') }}</a>
    @endif
    @if($isJudge && isset($edition) && request()->routeIs('festival.portal.judging.*', 'festival.portal.battle-votes.*'))
        <a href="{{ route('festival.portal.judging.index', [$account->slug, $edition->slug]) }}" class="crm-tab">{{ __('app.festival_judging') }}</a>
        <a href="{{ route('festival.portal.battle-votes.index', [$account->slug, $edition->slug]) }}" class="crm-tab">{{ __('app.festival_battle_voting') }}</a>
    @endif
    <form method="POST" action="{{ route('festival.portal.logout', $account->slug) }}" class="ml-auto">@csrf<button type="submit" class="px-3 py-2 text-sm font-semibold text-slate-600 hover:text-slate-950">{{ __('app.logout') }}</button></form>
</nav>
