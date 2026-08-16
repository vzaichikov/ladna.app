@props([
    'account',
    'event',
    'active',
])

@php
    $canManage = auth()->user()?->can('manageEvents', $account) ?? false;
    $canLegacyScan = auth()->user()?->can('checkInEventTickets', $account) ?? false;
    $canDoor = auth()->user()?->can('doorStaff', $account) ?? false;
    $eventFestivalStaffAccess = app(\App\Support\EventFestivalStaffAccess::class);
    $isEventFestivalStaff = auth()->user() instanceof \App\Models\User
        && $eventFestivalStaffAccess->isStaff(auth()->user(), $account);
    $items = [];

    if ($canManage) {
        $items = [
            ['key' => 'details', 'label' => __('app.event_nav_details'), 'href' => route('dashboard.accounts.events.edit', [$account, $event])],
            ['key' => 'ticket-types', 'label' => __('app.event_ticket_types'), 'href' => route('dashboard.accounts.events.ticket-types.index', [$account, $event])],
            ['key' => 'tickets', 'label' => __('app.event_issued_tickets'), 'href' => route('dashboard.accounts.events.tickets.index', [$account, $event])],
            ['key' => 'orders', 'label' => __('app.event_orders'), 'href' => route('dashboard.accounts.events.orders.index', [$account, $event])],
        ];
    }

    if ($canLegacyScan || $canDoor || $isEventFestivalStaff) {
        $items[] = ['key' => 'scanner', 'label' => __('app.event_scanner'), 'href' => route('dashboard.accounts.events.scanner', [$account, $event])];
    }

    if ($canDoor || $isEventFestivalStaff) {
        $items[] = ['key' => 'attendance', 'label' => __('app.event_attendance'), 'href' => route('dashboard.accounts.events.attendance', [$account, $event])];
    }
@endphp

<nav aria-label="{{ __('app.event_navigation') }}" class="overflow-x-auto pb-1">
    <div class="flex min-w-max gap-2">
        @foreach ($items as $item)
            <a
                href="{{ $item['href'] }}"
                @class([
                    'whitespace-nowrap rounded-lg border px-4 py-2 text-sm font-semibold transition',
                    'border-brand-600 bg-brand-600 text-white shadow-sm shadow-brand-600/20' => $active === $item['key'],
                    'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' => $active !== $item['key'],
                ])
                @if ($active === $item['key']) aria-current="page" @endif
            >
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</nav>
