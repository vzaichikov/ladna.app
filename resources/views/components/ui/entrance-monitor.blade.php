@props([
    'overview' => [],
    'cashBalances' => [],
    'cashHistory' => [],
])

@php
    $isFestivalMonitor = array_key_exists('participants', $overview) || array_key_exists('helpers', $overview);
    $tickets = collect(data_get($overview, $isFestivalMonitor ? 'credentials' : 'tickets', []));
    $total = (int) data_get($overview, 'total', $tickets->count());
    $passed = (int) data_get($overview, 'passed', $tickets->where('passed', true)->count());
    $waiting = (int) data_get($overview, 'waiting', data_get($overview, 'unpassed', max(0, $total - $passed)));
    $cashBalances = collect(data_get($overview, 'cash_balances', $cashBalances));
    $cashFormatted = data_get($overview, 'cash.formatted');
    $cashHistory = collect(data_get($overview, 'cash.history', data_get($overview, 'cash_history', $cashHistory)));
@endphp

<div
    {{ $attributes->class(['space-y-4']) }}
    data-entrance-monitor
    data-admit-label="{{ __('app.entrance_admit') }}"
    data-undo-label="{{ __('app.entrance_undo_short') }}"
    data-ticket-fallback="{{ __('app.entrance_ticket') }}"
    data-waiting-label="{{ __('app.entrance_waiting') }}"
    data-passed-label="{{ __('app.entrance_passed') }}"
>
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4" data-attendance-stats>
        @if ($isFestivalMonitor)
            <div class="rounded-xl border border-sky-200 bg-sky-50 px-3 py-3 shadow-xs">
                <span class="text-xs font-semibold text-sky-700">{{ __('app.festival_guest_tickets') }}</span>
                <strong class="mt-1 block text-2xl leading-none tabular-nums text-sky-950" data-attendance-guests>{{ data_get($overview, 'guest_tickets.passed', 0) }}/{{ data_get($overview, 'guest_tickets.total', 0) }}</strong>
            </div>
            <div class="rounded-xl border border-violet-200 bg-violet-50 px-3 py-3 shadow-xs">
                <span class="text-xs font-semibold text-violet-700">{{ __('app.festival_participants') }}</span>
                <strong class="mt-1 block text-2xl leading-none tabular-nums text-violet-950" data-attendance-participants>{{ data_get($overview, 'participants.passed', 0) }}/{{ data_get($overview, 'participants.total', 0) }}</strong>
            </div>
            <div class="rounded-xl border border-teal-200 bg-teal-50 px-3 py-3 shadow-xs">
                <span class="text-xs font-semibold text-teal-700">{{ __('app.festival_helpers') }}</span>
                <strong class="mt-1 block text-2xl leading-none tabular-nums text-teal-950" data-attendance-helpers>{{ data_get($overview, 'helpers.passed', 0) }}/{{ data_get($overview, 'helpers.total', 0) }}</strong>
            </div>
        @else
        <div class="rounded-xl border border-stone-200 bg-white px-3 py-3 shadow-xs">
            <span class="text-xs font-semibold text-slate-500">{{ __('app.entrance_total_tickets') }}</span>
            <strong class="mt-1 block text-2xl leading-none tabular-nums text-slate-950" data-attendance-total>{{ $total }}</strong>
        </div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-3 shadow-xs">
            <span class="text-xs font-semibold text-emerald-700">{{ __('app.entrance_passed') }}</span>
            <strong class="mt-1 block text-2xl leading-none tabular-nums text-emerald-950" data-attendance-passed>{{ $passed }}</strong>
        </div>
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-3 shadow-xs">
            <span class="text-xs font-semibold text-rose-700">{{ __('app.entrance_waiting') }}</span>
            <strong class="mt-1 block text-2xl leading-none tabular-nums text-rose-950" data-attendance-waiting>{{ $waiting }}</strong>
        </div>
        @endif
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 shadow-xs">
            <span class="text-xs font-semibold text-amber-800">{{ __('app.entrance_cash_at_door') }}</span>
            <strong class="mt-1 block truncate text-lg leading-none tabular-nums text-amber-950" data-attendance-cash>
                @if ($cashFormatted)
                    {{ $cashFormatted }}
                @else
                    @forelse ($cashBalances as $balance)
                        @if (! $loop->first) · @endif{{ data_get($balance, 'label', data_get($balance, 'amount_label', '—')) }}
                    @empty
                        —
                    @endforelse
                @endif
            </strong>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500" data-attendance-updated aria-live="polite">{{ __('app.entrance_monitor_live') }}</p>
        @if ($cashHistory->isNotEmpty())
            <details class="relative">
                <summary class="cursor-pointer list-none rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-white hover:text-slate-950 crm-focus">{{ __('app.entrance_cash_history') }}</summary>
                <div class="absolute right-0 z-20 mt-2 max-h-72 w-[min(22rem,calc(100vw-2rem))] overflow-y-auto rounded-xl border border-stone-200 bg-white p-3 shadow-xl">
                    <div class="space-y-2">
                        @foreach ($cashHistory as $entry)
                            <div class="flex items-start justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-xs">
                                <div class="min-w-0"><strong class="block truncate text-slate-900">{{ data_get($entry, 'guest_name', data_get($entry, 'actor', data_get($entry, 'label', __('app.entrance_cash_ticket')))) }}</strong><span class="mt-0.5 block text-slate-500">{{ data_get($entry, 'occurred_at_label', data_get($entry, 'occurred_at')) }}</span></div>
                                <span class="shrink-0 font-semibold tabular-nums {{ data_get($entry, 'direction') === 'cash_out' ? 'text-rose-700' : 'text-emerald-700' }}">{{ data_get($entry, 'amount_label', data_get($entry, 'formatted')) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </details>
        @endif
    </div>

    <div class="grid grid-cols-[repeat(auto-fill,minmax(11rem,1fr))] gap-2" data-attendance-tickets>
        @foreach ($tickets as $ticket)
            @php
                $isPassed = (bool) data_get($ticket, 'passed', data_get($ticket, 'is_checked_in', false));
                $customerName = data_get($ticket, 'customer_name', data_get($ticket, 'customer', __('app.entrance_guest')));
                $ticketCode = data_get($ticket, 'code');
                $ticketType = collect([data_get($ticket, 'kind_label'), data_get($ticket, 'type', data_get($ticket, 'ticket_type'))])->filter()->unique()->join(' · ');
                $undoUrl = data_get($ticket, 'undo_url');
            @endphp
            <article
                @class([
                    'min-w-0 rounded-xl border p-2.5 shadow-xs',
                    'border-emerald-200 bg-emerald-50 text-emerald-950' => $isPassed,
                    'border-rose-200 bg-rose-50 text-rose-950' => ! $isPassed,
                ])
                data-attendance-ticket="{{ data_get($ticket, 'key', data_get($ticket, 'id')) }}"
                data-passed="{{ $isPassed ? 'true' : 'false' }}"
            >
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold" data-attendance-customer>{{ $customerName }}</div>
                    <div class="mt-0.5 truncate text-[11px] opacity-75" data-attendance-type>{{ $ticketType }}</div>
                    <div class="truncate font-mono text-[10px] leading-tight opacity-70" data-attendance-code>{{ $ticketCode }}</div>
                </div>
                <div class="mt-2" data-attendance-action>
                    @if ($isPassed)
                        <button type="button" class="flex min-h-9 w-full items-center justify-center gap-1.5 rounded-lg border border-emerald-300 bg-white/80 px-2 text-xs font-semibold text-emerald-800 transition hover:bg-white crm-focus" data-entrance-undo="{{ $undoUrl }}" data-customer="{{ $customerName }}" data-code="{{ $ticketCode }}" @disabled(! $undoUrl)><x-ui.icon name="undo-2" class="h-3.5 w-3.5" />{{ __('app.entrance_undo_short') }}</button>
                    @else
                        <button type="button" class="flex min-h-9 w-full items-center justify-center gap-1.5 rounded-lg bg-rose-700 px-2 text-xs font-semibold text-white transition hover:bg-rose-800 crm-focus" data-door-checkin data-ticket-code="{{ $ticketCode }}" data-scan-source="monitor"><x-ui.icon name="ticket-check" class="h-3.5 w-3.5" />{{ __('app.entrance_admit') }}</button>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
</div>
