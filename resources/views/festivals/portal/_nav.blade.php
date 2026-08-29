@php($portalUser = $portalUser ?? request()->user('festival'))
@php($isJudge = $portalUser?->role === \App\Enums\FestivalPortalRole::Judge)
@php($isRegistrant = $portalUser?->role === \App\Enums\FestivalPortalRole::Registrant)
@php($festivalTelegramBotLinks = $festivalTelegramBotLinks ?? collect())
@if($isRegistrant)
    <x-ui.public-studio-header :account="$account">
        <x-slot:actions>
            <form method="POST" action="{{ route('festival.portal.logout', $account->slug) }}" data-festival-header-logout>
                @csrf
                <x-ui.button type="submit" variant="ghost" size="sm">{{ __('app.logout') }}</x-ui.button>
            </form>
        </x-slot:actions>
    </x-ui.public-studio-header>
@else
    <x-ui.public-studio-header :account="$account" />
@endif
<nav class="mt-4 flex flex-wrap items-center gap-2 rounded-2xl border border-stone-200 bg-white p-3 shadow-crm" aria-label="{{ __('app.festival_portal') }}">
    @if ($isJudge)
        <a href="{{ route('festival.portal.judge.dashboard', $account->slug) }}" class="festival-portal-nav-link" data-festival-portal-nav-link @if(request()->routeIs('festival.portal.judge.dashboard')) aria-current="page" @endif>{{ __('app.festival_judge_cabinet') }}</a>
        <a href="{{ route('festival.portal.judge.profile.edit', $account->slug) }}" class="festival-portal-nav-link" data-festival-portal-nav-link @if(request()->routeIs('festival.portal.judge.profile.*')) aria-current="page" @endif>{{ __('app.profile') }}</a>
    @else
        <a href="{{ route('festival.portal.dashboard', $account->slug) }}" class="festival-portal-nav-link" data-festival-portal-nav-link @if(request()->routeIs('festival.portal.dashboard')) aria-current="page" @endif>{{ __('app.festivals') }}</a>
        <a href="{{ route('festival.portal.entries.index', $account->slug) }}" class="festival-portal-nav-link gap-2" data-festival-portal-nav-link @if(request()->routeIs('festival.portal.entries.*', 'festival.portal.entry-steps.*', 'festival.portal.entry-step-responses.*', 'festival.portal.submissions.*', 'festival.portal.charges.*')) aria-current="page" @endif>
            <span>{{ __('app.festival_my_performances') }}</span>
            <span
                data-festival-entry-count="{{ $portalEntryCount }}"
                @class([
                    'inline-flex min-w-6 items-center justify-center rounded-full px-1.5 py-1 text-xs font-bold leading-none',
                    'bg-violet-crm-100 text-violet-crm-700' => $portalEntryCount > 0,
                    'bg-stone-100 text-slate-400' => $portalEntryCount === 0,
                ])
            >{{ $portalEntryCount }}</span>
        </a>
        <a href="{{ route('festival.portal.participants.index', $account->slug) }}" class="festival-portal-nav-link" data-festival-portal-nav-link @if(request()->routeIs('festival.portal.participants.*')) aria-current="page" @endif>{{ __('app.festival_portal_team') }}</a>
        <a href="{{ route('festival.portal.tickets.index', $account->slug) }}" class="festival-portal-nav-link" data-festival-portal-nav-link @if(request()->routeIs('festival.portal.tickets.*')) aria-current="page" @endif>{{ __('app.festival_tickets_and_passes') }}</a>
        <a href="{{ route('festival.portal.profile.edit', $account->slug) }}" class="festival-portal-nav-link" data-festival-portal-nav-link @if(request()->routeIs('festival.portal.profile.*')) aria-current="page" @endif>{{ __('app.profile') }}</a>
    @endif
    @if($isJudge && isset($edition) && request()->routeIs('festival.portal.judging.*', 'festival.portal.battle-votes.*'))
        <a href="{{ route('festival.portal.judging.index', [$account->slug, $edition]) }}" class="festival-portal-nav-link" data-festival-portal-nav-link @if(request()->routeIs('festival.portal.judging.*')) aria-current="page" @endif>{{ __('app.festival_judging') }}</a>
        <a href="{{ route('festival.portal.battle-votes.index', [$account->slug, $edition->slug]) }}" class="festival-portal-nav-link" data-festival-portal-nav-link @if(request()->routeIs('festival.portal.battle-votes.*')) aria-current="page" @endif>{{ __('app.festival_battle_voting') }}</a>
    @endif
    @if($isRegistrant)
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @foreach ($festivalTelegramBotLinks as $telegramBotLink)
                <a
                    href="{{ $telegramBotLink['url'] }}"
                    target="_blank"
                    rel="noopener"
                    class="festival-portal-nav-link gap-2"
                    data-festival-telegram-bot-link
                    aria-label="{{ __('app.telegram_bot') }}: {{ $telegramBotLink['series_name'] }}"
                >
                    <x-ui.icon name="telegram" class="h-4 w-4" />
                    <span>
                        {{ __('app.telegram_bot') }}
                        @if ($festivalTelegramBotLinks->count() > 1)
                            <span aria-hidden="true">· {{ $telegramBotLink['series_name'] }}</span>
                        @endif
                    </span>
                </a>
            @endforeach
            <a
                href="{{ route('help.show', 'festival-participants') }}"
                target="_blank"
                rel="noopener"
                class="festival-portal-nav-link gap-2"
                data-festival-participant-help-link
            >
                <x-ui.icon name="circle-help" class="h-4 w-4" />
                {{ __('app.help') }}
            </a>
        </div>
    @else
        <form method="POST" action="{{ route('festival.portal.logout', $account->slug) }}" class="ml-auto" data-festival-nav-logout>@csrf<button type="submit" class="px-3 py-2 text-sm font-semibold text-slate-600 hover:text-slate-950">{{ __('app.logout') }}</button></form>
    @endif
</nav>
