@extends('layouts.app')

@section('title', __('app.events').' - '.$account->name)

@section('content')
<div class="w-full min-w-0 space-y-6" data-event-admin-page>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="crm-page-title mt-1">{{ __('app.events') }}</h1>
            <p class="crm-page-copy">{{ __('app.events_intro') }}</p>
        </div>
        @if ($canManage)
            <x-ui.button :href="route('dashboard.accounts.events.create', $account)">
                <x-ui.icon name="plus" class="h-4 w-4" /> {{ __('app.event_create') }}
            </x-ui.button>
        @endif
    </div>

    @unless ($isEventFestivalStaff)
        <nav class="flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('app.events') }}">
            @foreach (['upcoming', 'draft', 'past', 'cancelled'] as $value)
                <a href="{{ route('dashboard.accounts.events.index', ['account' => $account, 'tab' => $value, ...$locationQuery]) }}"
                   @class([
                        'whitespace-nowrap rounded-lg border px-4 py-2 text-sm font-semibold transition',
                        'border-brand-600 bg-brand-600 text-white shadow-sm shadow-brand-600/20' => $tab === $value,
                        'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' => $tab !== $value,
                   ])
                   @if ($tab === $value) aria-current="page" @endif>
                    {{ __('app.event_tab_'.$value) }}
                </a>
            @endforeach
        </nav>
    @endunless

    @include('locations._working-filter', ['preserveQuery' => ['tab' => $tab]])

    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($events as $event)
            <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <span class="{{ $event->status === \App\Enums\EventStatus::Published ? 'crm-status-active' : ($event->status === \App\Enums\EventStatus::Cancelled ? 'crm-status-danger' : 'crm-status-muted') }}">{{ __('app.event_status_'.$event->status->value) }}</span>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">{{ $event->title }}</h2>
                        <p class="mt-2 text-sm text-slate-500">{{ $event->starts_at->timezone($event->timezone)->format('d.m.Y H:i') }} · {{ $event->timezone }}</p>
                        <p class="mt-1 text-sm font-medium text-slate-600">{{ $event->location?->name ?? $event->external_venue_name ?? __('app.location_unassigned') }}</p>
                    </div>
                    <x-ui.icon name="calendar-days" class="h-6 w-6 text-brand-600" />
                </div>
                <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-xl bg-slate-50 p-3"><span class="block text-xs text-slate-500">{{ __('app.event_tickets') }}</span><strong>{{ $event->tickets_count }}</strong></div>
                    <div class="rounded-xl bg-slate-50 p-3"><span class="block text-xs text-slate-500">{{ __('app.event_remaining') }}</span><strong>{{ $event->remainingAdmissionInventory() }}</strong></div>
                    <div class="rounded-xl bg-slate-50 p-3"><span class="block text-xs text-slate-500">{{ __('app.event_checked_in') }}</span><strong>{{ $event->checked_in_tickets_count }}</strong></div>
                    @if ($canManage)
                        <div class="rounded-xl bg-slate-50 p-3"><span class="block text-xs text-slate-500">{{ __('app.event_revenue') }}</span><strong>{{ number_format(($event->revenue_cents ?? 0) / 100, 2) }} {{ $event->currency }}</strong></div>
                    @endif
                </div>
                <div class="mt-5 flex flex-wrap gap-2">
                    @if ($canManage)
                        <x-ui.button :href="route('dashboard.accounts.events.edit', [$account, $event])" variant="secondary">{{ __('app.edit') }}</x-ui.button>
                        @if (in_array($event->status, [\App\Enums\EventStatus::Published, \App\Enums\EventStatus::Cancelled], true))
                            <x-ui.button
                                type="button"
                                variant="secondary"
                                data-copy-button
                                data-copy-value="{{ route('public.events.show', [$account->slug, $event->slug]) }}"
                                data-copy-success-label="{{ __('app.copied') }}"
                            >
                                <x-ui.icon name="copy" class="h-4 w-4" />
                                <span data-copy-label>{{ __('app.copy_link') }}</span>
                            </x-ui.button>
                        @endif
                    @endif
                    <x-ui.button :href="route('dashboard.accounts.events.scanner', [$account, $event])" variant="secondary">{{ __('app.event_scanner') }}</x-ui.button>
                    @if ($canDoorStaff)
                        <x-ui.button :href="route('dashboard.accounts.events.attendance', [$account, $event])" variant="secondary">{{ __('app.event_attendance') }}</x-ui.button>
                    @endif
                </div>
            </article>
        @empty
            <div class="lg:col-span-2"><x-ui.empty-state icon="calendar-days">{{ __('app.events_empty') }}</x-ui.empty-state></div>
        @endforelse
    </div>
    {{ $events->links() }}
</div>
@endsection
