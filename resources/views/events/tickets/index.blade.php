@extends('layouts.app')

@section('title', __('app.event_issued_tickets').' - '.$event->title)

@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <x-ui.page-header :title="__('app.event_issued_tickets')" :copy="__('app.event_issued_tickets_help')">
        @if ($canIssue)
            <x-slot:actions>
                <x-ui.button :href="route('dashboard.accounts.events.tickets.issue.create', [$account, $event])">
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('app.event_issue_tickets') }}
                </x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.event-navigation :account="$account" :event="$event" active="tickets" />

    @if (session('issued_ticket_codes'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" role="status">
            <p class="font-semibold">{{ session('status') }}</p>
            <p class="mt-2 break-words font-mono text-xs">{{ implode(', ', session('issued_ticket_codes')) }}</p>
        </div>
    @endif

    <x-ui.filter-bar
        :action="route('dashboard.accounts.events.tickets.index', [$account, $event])"
        :reset-href="route('dashboard.accounts.events.tickets.index', [$account, $event])"
        class="sm:grid-cols-2 xl:grid-cols-[minmax(16rem,1.6fr)_repeat(4,minmax(9rem,1fr))]"
    >
        <label class="block">
            <span class="crm-label">{{ __('app.search') }}</span>
            <input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.event_ticket_search_placeholder') }}">
        </label>
        <label class="block">
            <span class="crm-label">{{ __('app.event_ticket_type') }}</span>
            <select name="ticket_type" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                @foreach ($ticketTypes as $ticketType)
                    <option value="{{ $ticketType->id }}" @selected($filters['ticket_type'] === $ticketType->id)>{{ $ticketType->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">
            <span class="crm-label">{{ __('app.status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                @foreach ($statuses as $ticketStatus)
                    <option value="{{ $ticketStatus->value }}" @selected($filters['status'] === $ticketStatus->value)>{{ __('app.event_ticket_status_'.$ticketStatus->value) }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">
            <span class="crm-label">{{ __('app.event_check_in_state') }}</span>
            <select name="check_in" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                <option value="checked_in" @selected($filters['check_in'] === 'checked_in')>{{ __('app.event_checked_in') }}</option>
                <option value="not_checked_in" @selected($filters['check_in'] === 'not_checked_in')>{{ __('app.event_not_checked_in') }}</option>
            </select>
        </label>
        <label class="block">
            <span class="crm-label">{{ __('app.event_ticket_source') }}</span>
            <select name="source" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                <option value="manual" @selected($filters['source'] === 'manual')>{{ __('app.event_ticket_source_manual') }}</option>
                <option value="online" @selected($filters['source'] === 'online')>{{ __('app.event_ticket_source_online') }}</option>
            </select>
        </label>
    </x-ui.filter-bar>

    @if ($tickets->isEmpty())
        <x-ui.empty-state :title="$hasFilters ? __('app.no_matching_results') : __('app.event_issued_tickets_empty')" icon="ticket">
            <p>{{ $hasFilters ? __('app.change_or_reset_filters') : __('app.event_issued_tickets_empty_help') }}</p>
            @if ($hasFilters || $canIssue)
                <div class="mt-4">
                    <x-ui.button
                        :href="$hasFilters
                            ? route('dashboard.accounts.events.tickets.index', [$account, $event])
                            : route('dashboard.accounts.events.tickets.issue.create', [$account, $event])"
                        variant="secondary"
                    >
                        {{ $hasFilters ? __('app.reset_filters') : __('app.event_issue_tickets') }}
                    </x-ui.button>
                </div>
            @endif
        </x-ui.empty-state>
    @else
        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-5 py-3">{{ __('app.event_ticket') }}</th>
                            <th class="px-5 py-3">{{ __('app.event_buyer') }}</th>
                            <th class="px-5 py-3">{{ __('app.event_ticket_source') }}</th>
                            <th class="px-5 py-3">{{ __('app.status') }}</th>
                            <th class="px-5 py-3">{{ __('app.event_check_in_state') }}</th>
                            <th class="px-5 py-3">{{ __('app.issued_at') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($tickets as $ticket)
                            @php($order = $ticket->order)
                            <tr>
                                <td class="px-5 py-4">
                                    <strong class="block text-slate-950">{{ $ticket->ticketType?->name }}</strong>
                                    <span class="mt-1 block font-mono text-xs text-slate-500">{{ $ticket->code }}</span>
                                    <span class="mt-1 block font-mono text-xs text-slate-400">{{ $order?->order_id }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <strong class="block text-slate-950">{{ $order?->buyer_name }}</strong>
                                    @if (filled($order?->buyer_email))
                                        <span class="mt-1 block text-xs text-slate-500">{{ $order->buyer_email }}</span>
                                    @endif
                                    @if (filled($order?->buyer_phone))
                                        <span class="mt-1 block text-xs text-slate-500">{{ $order->buyer_phone }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    @if ($order?->isManuallyIssued())
                                        <span class="crm-status-muted">{{ __('app.event_ticket_source_manual') }}</span>
                                        <p class="mt-2 text-xs text-slate-500">
                                            {{ $order->amount_cents > 0
                                                ? __('app.event_manual_method_'.$order->manualPaymentMethod())
                                                : __('app.event_manual_payment_complimentary') }}
                                        </p>
                                        @if ($order->issuedBy)
                                            <p class="mt-1 text-xs text-slate-500">{{ __('app.event_issued_by', ['name' => $order->issuedBy->name]) }}</p>
                                        @endif
                                    @else
                                        <span class="crm-status-active">{{ __('app.event_ticket_source_online') }}</span>
                                    @endif
                                    <p class="mt-2 whitespace-nowrap text-xs font-semibold text-slate-700">{{ \App\Support\MoneyFormatter::format($order?->amount_cents, $order?->currency ?? $event->currency) }}</p>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="{{ $ticket->status === \App\Enums\EventTicketStatus::Valid ? 'crm-status-active' : 'crm-status-danger' }}">
                                        {{ __('app.event_ticket_status_'.$ticket->status->value) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="{{ $ticket->is_checked_in ? 'crm-status-active' : 'crm-status-muted' }}">
                                        {{ $ticket->is_checked_in ? __('app.event_checked_in') : __('app.event_not_checked_in') }}
                                    </span>
                                    @if ($ticket->checked_in_at)
                                        <p class="mt-2 whitespace-nowrap text-xs text-slate-500">{{ $ticket->checked_in_at->timezone($event->timezone)->format('d.m.Y H:i') }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-500">{{ $ticket->created_at->timezone($event->timezone)->format('d.m.Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{ $tickets->links() }}
    @endif
</div>
@endsection
