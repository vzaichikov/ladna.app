@php
    $communicationPages = [
        [
            'route' => 'dashboard.accounts.festivals.communication.history',
            'icon' => 'history',
            'label' => __('app.festival_communication_tab_history'),
        ],
        [
            'route' => 'dashboard.accounts.festivals.communication.announcements',
            'icon' => 'megaphone',
            'label' => __('app.festival_communication_tab_announcements'),
        ],
        [
            'route' => 'dashboard.accounts.festivals.communication.settings',
            'icon' => 'settings',
            'label' => __('app.festival_communication_tab_settings'),
        ],
    ];
@endphp

<nav class="mb-6 flex flex-wrap gap-3" aria-label="{{ __('app.festival_communication_pages') }}" data-communication-navigation>
    @foreach ($communicationPages as $communicationPage)
        @php($isCurrent = request()->routeIs($communicationPage['route']))
        <x-ui.button
            :href="route($communicationPage['route'], [$account, $edition])"
            :variant="$isCurrent ? 'primary' : 'secondary'"
            aria-current="{{ $isCurrent ? 'page' : 'false' }}"
        >
            <x-ui.icon :name="$communicationPage['icon']" class="h-4 w-4" />
            {{ $communicationPage['label'] }}
        </x-ui.button>
    @endforeach
</nav>
