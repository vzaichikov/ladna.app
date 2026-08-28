<nav class="flex gap-1 overflow-x-auto rounded-2xl bg-slate-100 p-1" aria-label="{{ __('app.festival_participant_edit_tabs') }}" data-festival-participant-detail-nav>
    @foreach ([
        ['route' => 'dashboard.accounts.festivals.users.edit', 'key' => 'profile'],
        ['route' => 'dashboard.accounts.festivals.users.team', 'key' => 'team'],
        ['route' => 'dashboard.accounts.festivals.users.notifications', 'key' => 'notifications'],
    ] as $item)
        <a
            href="{{ route($item['route'], [$account, $edition, $portalUser]) }}"
            class="whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-semibold {{ $activeDetailPage === $item['key'] ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600 hover:text-slate-950' }}"
            @if ($activeDetailPage === $item['key']) aria-current="page" @endif
        >{{ __('app.festival_participant_edit_tab_'.$item['key']) }}</a>
    @endforeach
</nav>
