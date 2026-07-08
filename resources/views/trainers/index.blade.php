@extends('layouts.app')

@section('title', __('app.trainers').' - '.$account->name)

@section('content')
    @php
        $formatDateTime = static fn ($date): string => \App\Support\DateTimePresenter::format($date, $account) ?? __('app.not_set');
        $unreservedClassPassIssueCounts ??= collect();
        $unreservedClassPassIssueBookings ??= collect();
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.trainers') }}</h1>
            <p class="crm-page-copy">{{ __('app.trainers_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.trainers.create', $account)">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_trainer') }}
        </x-ui.button>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($trainers as $trainer)
            @php
                $issueCount = (int) ($unreservedClassPassIssueCounts->get($trainer->id) ?? 0);
            @endphp
            <div class="crm-row lg:grid-cols-[1fr_180px_150px_auto] lg:items-center">
                <div class="flex items-center gap-4">
                    @if ($trainer->photoUrl())
                        <img src="{{ $trainer->photoUrl() }}" alt="" class="h-11 w-11 rounded-full object-cover">
                    @else
                        <span class="flex h-11 w-11 items-center justify-center rounded-full bg-violet-crm-100 text-violet-crm-700">
                            <x-ui.icon name="trainers" class="h-5 w-5" />
                        </span>
                    @endif
                    <div>
                        <h2 class="font-semibold text-slate-950">{{ $trainer->name }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $trainer->email ?? $trainer->phone ?? $trainer->slug }}</p>
                    </div>
                </div>
                <x-ui.trainer-type-badge :trainer-type="$trainer->trainerType" />
                <span class="{{ $trainer->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                    {{ $trainer->is_active ? __('app.active') : __('app.inactive') }}
                </span>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    @if ($issueCount > 0)
                        <button
                            type="button"
                            class="inline-flex items-center justify-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-950 transition hover:bg-amber-100"
                            data-trainer-issues-open="{{ $trainer->id }}"
                            title="{{ __('app.show_unreserved_bookings') }}"
                        >
                            <x-ui.icon name="bell" class="h-4 w-4" />
                            {{ __('app.trainer_unreserved_bookings', ['count' => $issueCount]) }}
                        </button>
                    @endif
                    @if ($account->trainerPrivateTimeframesEnabled())
                        <x-ui.action-button :href="route('dashboard.accounts.trainers.private-timeframes.edit', [$account, $trainer])" icon="schedule" :label="__('app.trainer_private_timeframes')" />
                    @endif
                    <x-ui.action-button :href="route('dashboard.accounts.trainers.edit', [$account, $trainer])" icon="edit" :label="__('app.edit')" />
                    <form method="POST" action="{{ route('dashboard.accounts.trainers.destroy', [$account, $trainer]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_trainers')" icon="trainers" class="m-5" />
        @endforelse
    </x-ui.panel>

    @foreach ($trainers as $trainer)
        @php
            $issueBookings = $unreservedClassPassIssueBookings->get($trainer->id, collect());
        @endphp
        @if ($issueBookings->isNotEmpty())
            <div
                class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm"
                data-trainer-issues-modal="{{ $trainer->id }}"
                role="dialog"
                aria-modal="true"
                aria-label="{{ __('app.unreserved_booking_issues_for_trainer', ['trainer' => $trainer->name]) }}"
            >
                <div class="max-h-[88vh] w-full max-w-3xl overflow-hidden rounded-xl border border-stone-200 bg-white shadow-2xl">
                    <div class="flex items-start justify-between gap-4 border-b border-stone-100 px-5 py-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.unreserved_booking_issues_for_trainer', ['trainer' => $trainer->name]) }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('app.unreserved_booking_issues') }}</p>
                        </div>
                        <button type="button" data-trainer-issues-close class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-50 hover:text-slate-700" aria-label="{{ __('app.close') }}">
                            <x-ui.icon name="close" class="h-5 w-5" />
                        </button>
                    </div>
                    <div class="max-h-[70vh] overflow-y-auto divide-y divide-stone-100">
                        @forelse ($issueBookings as $booking)
                            <div class="grid gap-3 px-5 py-4 text-sm md:grid-cols-[1fr_1fr_auto] md:items-center">
                                <div>
                                    <div class="font-semibold text-slate-950">{{ $formatDateTime($booking->scheduledClass?->starts_at) }}</div>
                                    <div class="mt-1 text-slate-500">{{ $booking->scheduledClass?->title ?? __('app.generated_classes') }}</div>
                                    <div class="mt-1 text-xs font-medium text-slate-500">{{ $booking->scheduledClass?->location?->name }}@if ($booking->scheduledClass?->room) · {{ $booking->scheduledClass->room->name }}@endif</div>
                                </div>
                                <div>
                                    <div class="font-semibold text-slate-950">{{ $booking->customer?->name ?? __('app.no_name') }}</div>
                                    <div class="mt-1 text-slate-500">{{ $booking->customer?->phone ?? $booking->customer?->email ?? __('app.no_contact') }}</div>
                                    <div class="mt-1">
                                        <span class="crm-status-muted">{{ __('app.'.$booking->status->value) }}</span>
                                    </div>
                                </div>
                                <div class="md:text-right">
                                    @if ($booking->customer)
                                        <x-ui.button :href="route('dashboard.accounts.customers.edit', [$account, $booking->customer])" variant="secondary" size="sm">
                                            {{ __('app.open_customer') }}
                                        </x-ui.button>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="m-5 rounded-lg border border-stone-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">{{ __('app.no_unreserved_booking_issues') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    @endforeach
@endsection
