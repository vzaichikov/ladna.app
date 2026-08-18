@php($portalUser = $portalUser ?? request()->user('festival'))
@php($isJudge = $portalUser?->role === \App\Enums\FestivalPortalRole::Judge)
<x-ui.public-studio-header :account="$account" />
<nav class="mt-4 flex flex-wrap items-center gap-2 rounded-2xl border border-stone-200 bg-white p-3 shadow-crm" aria-label="{{ __('app.festival_portal') }}">
    @if ($isJudge)
        <a href="{{ route('festival.portal.judge.dashboard', $account->slug) }}" class="crm-tab" @if(request()->routeIs('festival.portal.judge.dashboard')) aria-current="page" @endif>{{ __('app.festival_judge_cabinet') }}</a>
        <a href="{{ route('festival.portal.judge.profile.edit', $account->slug) }}" class="crm-tab" @if(request()->routeIs('festival.portal.judge.profile.*')) aria-current="page" @endif>{{ __('app.profile') }}</a>
    @else
        <a href="{{ route('festival.portal.dashboard', $account->slug) }}" class="crm-tab" @if(request()->routeIs('festival.portal.dashboard')) aria-current="page" @endif>{{ __('app.festivals') }}</a>
        <a href="{{ route('festival.portal.entries.index', $account->slug) }}" class="crm-tab" @if(request()->routeIs('festival.portal.entries.*', 'festival.portal.entry-steps.*', 'festival.portal.entry-step-responses.*', 'festival.portal.submissions.*', 'festival.portal.charges.*')) aria-current="page" @endif>{{ __('app.festival_my_performances') }}</a>
        <a href="{{ route('festival.portal.participants.index', $account->slug) }}" class="crm-tab" @if(request()->routeIs('festival.portal.participants.*')) aria-current="page" @endif>{{ __('app.festival_portal_team') }}</a>
        <a href="{{ route('festival.portal.profile.edit', $account->slug) }}" class="crm-tab" @if(request()->routeIs('festival.portal.profile.*')) aria-current="page" @endif>{{ __('app.profile') }}</a>
    @endif
    @if($isJudge && isset($edition) && request()->routeIs('festival.portal.judging.*', 'festival.portal.battle-votes.*'))
        <a href="{{ route('festival.portal.judging.index', [$account->slug, $edition->slug]) }}" class="crm-tab" @if(request()->routeIs('festival.portal.judging.*')) aria-current="page" @endif>{{ __('app.festival_judging') }}</a>
        <a href="{{ route('festival.portal.battle-votes.index', [$account->slug, $edition->slug]) }}" class="crm-tab" @if(request()->routeIs('festival.portal.battle-votes.*')) aria-current="page" @endif>{{ __('app.festival_battle_voting') }}</a>
    @endif
    <form method="POST" action="{{ route('festival.portal.logout', $account->slug) }}" class="ml-auto">@csrf<button type="submit" class="px-3 py-2 text-sm font-semibold text-slate-600 hover:text-slate-950">{{ __('app.logout') }}</button></form>
</nav>
