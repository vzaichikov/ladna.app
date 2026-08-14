@props([
    'account',
    'event',
    'active',
])

@php
    $canManage = auth()->user()?->can('manageEvents', $account) ?? false;
    $canScan = auth()->user()?->can('checkInEventTickets', $account) ?? false;
    $items = [];

    if ($canManage) {
        $items = [
            ['key' => 'details', 'label' => __('app.event_nav_details'), 'href' => route('dashboard.accounts.events.edit', [$account, $event])],
            ['key' => 'ticket-types', 'label' => __('app.event_ticket_types'), 'href' => route('dashboard.accounts.events.ticket-types.index', [$account, $event])],
            ['key' => 'tickets', 'label' => __('app.event_issued_tickets'), 'href' => route('dashboard.accounts.events.tickets.index', [$account, $event])],
            ['key' => 'orders', 'label' => __('app.event_orders'), 'href' => route('dashboard.accounts.events.orders.index', [$account, $event])],
        ];
    }

    if ($canScan) {
        $items[] = ['key' => 'scanner', 'label' => __('app.event_scanner'), 'href' => route('dashboard.accounts.events.scanner', [$account, $event])];
    }
@endphp

<nav aria-label="{{ __('app.event_navigation') }}" class="overflow-x-auto rounded-xl border border-stone-200 bg-white p-1 shadow-xs">
    <div class="flex min-w-max gap-1">
        @foreach ($items as $item)
            <a
                href="{{ $item['href'] }}"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-semibold transition',
                    'bg-brand-600 text-white shadow-sm' => $active === $item['key'],
                    'text-slate-600 hover:bg-brand-50 hover:text-slate-950' => $active !== $item['key'],
                ])
                @if ($active === $item['key']) aria-current="page" @endif
            >
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</nav>
