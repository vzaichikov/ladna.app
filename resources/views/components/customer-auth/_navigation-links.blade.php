<x-ui.button :href="route('public.studio', $account->slug)" variant="secondary">
    <x-ui.icon name="globe" class="h-4 w-4 shrink-0" />
    {{ __('app.customer_studio_homepage') }}
</x-ui.button>

@if ($showPortalLinks && ! $hasDashboardLink && ! request()->routeIs('customer.dashboard'))
    <x-ui.button :href="route('customer.dashboard', $account->slug)" variant="secondary">
        <x-ui.icon name="house" class="h-4 w-4 shrink-0" />
        {{ __('app.customer_portal') }}
    </x-ui.button>
@endif

@foreach ($publicLocations as $publicLocation)
    @php
        $locationLabel = $publicLocations->count() > 1 ? ' · '.$publicLocation->name : '';
        $isCurrentLocation = request()->route('locationSlug') === $publicLocation->slug;
    @endphp
    <x-ui.button
        :href="route('public.price', [$account->slug, $publicLocation->slug])"
        variant="secondary"
        class="aria-[current=page]:border-brand-200 aria-[current=page]:bg-brand-50"
        :aria-current="$isCurrentLocation && request()->routeIs('public.price') ? 'page' : null"
    >
        <x-ui.icon name="class-pass-plans" class="h-4 w-4 shrink-0" />
        {{ __('app.customer_dashboard_buy_class_pass').$locationLabel }}
    </x-ui.button>
    <x-ui.button
        :href="route('public.schedule', [$account->slug, $publicLocation->slug])"
        variant="secondary"
        class="aria-[current=page]:border-brand-200 aria-[current=page]:bg-brand-50"
        :aria-current="$isCurrentLocation && request()->routeIs('public.schedule') ? 'page' : null"
    >
        <x-ui.icon name="schedule" class="h-4 w-4 shrink-0" />
        {{ __('app.customer_dashboard_book_class').$locationLabel }}
    </x-ui.button>
@endforeach

@if ($customerTelegramBotLink)
    <x-ui.button
        :href="$customerTelegramBotLink"
        variant="secondary"
        target="_blank"
        rel="noopener"
        data-customer-telegram-bot-link="customer-dashboard"
    >
        <img src="{{ asset('assets/social/telegram.svg') }}" alt="" class="h-4 w-4 shrink-0">
        {{ __('app.customer_telegram_booking_bot') }}
    </x-ui.button>
@endif

@if ($showPortalLinks)
    <x-ui.button
        :href="route('customer.events.index', $account->slug)"
        variant="secondary"
        class="aria-[current=page]:border-brand-200 aria-[current=page]:bg-brand-50"
        :aria-current="request()->routeIs('customer.events.index') ? 'page' : null"
    >
        <x-ui.icon name="ticket" class="h-4 w-4 shrink-0" />
        {{ __('app.customer_events_and_festivals') }}
    </x-ui.button>
@endif
