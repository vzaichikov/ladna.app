@extends('layouts.app')

@section('title', __('app.event_ticket_types').' - '.$event->title)

@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <x-ui.page-header :title="__('app.event_ticket_types')" :copy="__('app.event_tickets_help', ['currency' => $event->currency])">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.events.ticket-types.create', [$account, $event])">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.event_add_ticket_type') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.event-navigation :account="$account" :event="$event" active="ticket-types" />

    <x-ui.filter-bar
        :action="route('dashboard.accounts.events.ticket-types.index', [$account, $event])"
        :reset-href="route('dashboard.accounts.events.ticket-types.index', [$account, $event])"
        class="sm:grid-cols-[minmax(0,1fr)_14rem]"
    >
        <label class="block">
            <span class="crm-label">{{ __('app.search') }}</span>
            <input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.event_ticket_type_search_placeholder') }}">
        </label>
        <label class="block">
            <span class="crm-label">{{ __('app.status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                <option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option>
                <option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option>
            </select>
        </label>
    </x-ui.filter-bar>

    @if ($ticketTypes->isEmpty())
        <x-ui.empty-state :title="$hasFilters ? __('app.no_matching_results') : __('app.event_ticket_types_empty')" icon="ticket">
            <p>{{ $hasFilters ? __('app.change_or_reset_filters') : __('app.event_ticket_types_empty_help') }}</p>
            <div class="mt-4">
                <x-ui.button
                    :href="$hasFilters
                        ? route('dashboard.accounts.events.ticket-types.index', [$account, $event])
                        : route('dashboard.accounts.events.ticket-types.create', [$account, $event])"
                    variant="secondary"
                >
                    {{ $hasFilters ? __('app.reset_filters') : __('app.event_add_ticket_type') }}
                </x-ui.button>
            </div>
        </x-ui.empty-state>
    @else
        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-5 py-3">{{ __('app.event_ticket_type') }}</th>
                            <th class="px-5 py-3">{{ __('app.price') }}</th>
                            <th class="px-5 py-3">{{ __('app.event_inventory') }}</th>
                            <th class="px-5 py-3">{{ __('app.status') }}</th>
                            <th class="px-5 py-3 text-right">{{ __('app.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($ticketTypes as $ticketType)
                            @php
                                $cannotRemoveLastActive = $event->isPublished() && $ticketType->is_active && $activeTicketTypeCount === 1;
                                $canDelete = $ticketType->order_items_count === 0 && ! $cannotRemoveLastActive;
                            @endphp
                            <tr>
                                <td class="px-5 py-4">
                                    <strong class="text-slate-950">{{ $ticketType->name }}</strong>
                                    @if ($ticketType->description)
                                        <p class="mt-1 max-w-xl text-xs leading-5 text-slate-500">{{ $ticketType->description }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 font-semibold text-slate-950">{{ \App\Support\MoneyFormatter::format($ticketType->price_cents, $event->currency) }}</td>
                                <td class="whitespace-nowrap px-5 py-4">
                                    <span class="font-semibold text-slate-950">{{ $ticketType->remainingQuantity() }}</span>
                                    <span class="text-slate-500">/ {{ $ticketType->inventory }}</span>
                                    <p class="mt-1 text-xs text-slate-500">{{ __('app.event_ticket_reserved_count', ['count' => $ticketType->soldOrHeldQuantity()]) }}</p>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="{{ $ticketType->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                                        {{ $ticketType->is_active ? __('app.active') : __('app.inactive') }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex justify-end gap-2">
                                        <x-ui.button :href="route('dashboard.accounts.events.ticket-types.edit', [$account, $event, $ticketType])" variant="secondary" size="sm">
                                            {{ __('app.edit') }}
                                        </x-ui.button>
                                        @if ($canDelete)
                                            <form method="POST" action="{{ route('dashboard.accounts.events.ticket-types.destroy', [$account, $event, $ticketType]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <x-ui.button type="submit" variant="danger" size="sm">{{ __('app.delete') }}</x-ui.button>
                                            </form>
                                        @endif
                                    </div>
                                    @if ($ticketType->order_items_count > 0)
                                        <p class="mt-2 text-right text-xs text-slate-500">{{ __('app.event_ticket_type_used_help') }}</p>
                                    @elseif ($cannotRemoveLastActive)
                                        <p class="mt-2 text-right text-xs text-slate-500">{{ __('app.event_ticket_type_last_active_help') }}</p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{ $ticketTypes->links() }}
    @endif
</div>
@endsection
