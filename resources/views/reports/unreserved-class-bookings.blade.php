@extends('layouts.app')

@section('title', __('app.unreserved_class_bookings_report_title').' - '.$account->name)

@section('content')
    <div>
        <h1 class="crm-page-title">{{ __('app.unreserved_class_bookings_report_title') }}</h1>
        <p class="crm-page-copy">{{ __('app.unreserved_class_bookings_report_copy') }}</p>
    </div>

    <div class="mt-6 flex flex-col gap-4 rounded-xl border border-stone-200 bg-white p-4 shadow-xs sm:flex-row sm:items-end sm:justify-between">
        <form method="GET" action="{{ route('dashboard.accounts.reports.unreserved-class-bookings', $account) }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <label class="block min-w-64">
                <span class="crm-label">{{ __('app.filter_locations') }}</span>
                <select name="location_id" class="crm-field">
                    <option value="">{{ __('app.all_locations') }}</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected($selectedLocationId === $location->id)>
                            {{ $location->name }}@unless ($location->is_active) · {{ __('app.inactive') }}@endunless
                        </option>
                    @endforeach
                </select>
            </label>
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" size="sm">{{ __('app.apply_filters') }}</x-ui.button>
                <x-ui.button :href="route('dashboard.accounts.reports.unreserved-class-bookings', $account)" variant="secondary" size="sm">
                    {{ __('app.reset_filters') }}
                </x-ui.button>
            </div>
        </form>

        <div class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <span class="rounded-full border border-violet-crm-100 bg-violet-crm-50 px-3 py-1 font-semibold text-brand-700">
                {{ $selectedLocationId ? $locations->firstWhere('id', $selectedLocationId)?->name : __('app.account_wide') }}
            </span>
            <span>{{ trans_choice('app.unreserved_class_bookings_count', $bookings->total(), ['count' => $bookings->total()]) }}</span>
        </div>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="hidden gap-4 border-b border-stone-100 px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500 xl:grid xl:grid-cols-[1.15fr_1.1fr_1fr_1fr_1fr_auto]">
            <div>{{ __('app.booking_section') }}</div>
            <div>{{ __('app.class_type') }}</div>
            <div>{{ __('app.location') }}</div>
            <div>{{ __('app.customer') }}</div>
            <div>{{ __('app.trainers') }}</div>
            <div class="sr-only">{{ __('app.open') }}</div>
        </div>

        @forelse ($bookings as $row)
            @php
                $booking = $row['booking'];
                $scheduledClass = $booking->scheduledClass;
                $startsAt = $scheduledClass->starts_at->copy()->timezone($scheduledClass->displayTimezone());
                $endsAt = $scheduledClass->ends_at->copy()->timezone($scheduledClass->displayTimezone());
            @endphp
            <a
                href="{{ $row['scheduled_class_url'] }}"
                class="group grid gap-4 border-b border-stone-100 px-5 py-4 transition last:border-b-0 hover:bg-brand-50/50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand-500 xl:grid-cols-[1.15fr_1.1fr_1fr_1fr_1fr_auto] xl:items-center"
            >
                <div class="min-w-0">
                    <div class="text-sm font-semibold text-brand-700">{{ $startsAt->format('Y-m-d H:i') }} - {{ $endsAt->format('H:i') }}</div>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <span class="font-semibold text-slate-950">{{ $scheduledClass->displayTitle() }}</span>
                        <span class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-600">
                            {{ __('app.'.$booking->status->value) }}
                        </span>
                    </div>
                </div>

                <div class="min-w-0">
                    <div class="font-semibold text-slate-950">{{ $scheduledClass->classType?->name ?? __('app.class_type') }}</div>
                    @if ($scheduledClass->classType?->schedule_kind)
                        <div class="mt-1 text-sm text-slate-500">{{ __('app.'.$scheduledClass->classType->schedule_kind->value) }}</div>
                    @endif
                </div>

                <div class="min-w-0 text-sm">
                    <div class="font-semibold text-slate-950">{{ $scheduledClass->location?->name ?? __('app.location') }}</div>
                    <div class="mt-1 text-slate-500">{{ $scheduledClass->room?->name ?? __('app.room') }}</div>
                </div>

                <div class="min-w-0">
                    <div class="font-semibold text-slate-950">{{ $booking->customer?->name }}</div>
                    <div class="mt-1 truncate text-sm text-slate-500">{{ $booking->customer?->phone ?? $booking->customer?->email ?? __('app.no_contact') }}</div>
                </div>

                <div class="min-w-0 text-sm font-semibold text-slate-700">
                    {{ $row['trainer_names']->isNotEmpty() ? $row['trainer_names']->join(', ') : __('app.trainer_not_assigned') }}
                </div>

                <x-ui.icon name="external-link" class="h-5 w-5 text-slate-400 transition group-hover:text-brand-600" />
            </a>
        @empty
            <x-ui.empty-state :title="__('app.no_unreserved_class_bookings')" icon="calendar" class="m-5" />
        @endforelse
    </x-ui.panel>

    @if ($bookings->hasPages())
        <div class="mt-6">
            {{ $bookings->links() }}
        </div>
    @endif
@endsection
