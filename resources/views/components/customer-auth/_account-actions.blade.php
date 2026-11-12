@if ($showPortalLinks)
    <x-ui.button
        :href="route('customer.profile.edit', $account->slug)"
        variant="secondary"
        class="aria-[current=page]:border-brand-200 aria-[current=page]:bg-brand-50"
        :aria-current="request()->routeIs('customer.profile.*') ? 'page' : null"
    >
        <x-ui.icon name="user-round" class="h-4 w-4 shrink-0" />
        {{ __('app.profile') }}
    </x-ui.button>
@endif

<form method="POST" action="{{ route('customer.logout', $account->slug) }}">
    @csrf
    <x-ui.button type="submit" variant="ghost" class="w-full">{{ __('app.logout') }}</x-ui.button>
</form>
