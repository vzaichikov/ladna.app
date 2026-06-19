@extends('layouts.app')

@section('title', __('app.generated_classes').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.generated_classes') }}</h1>
            <p class="crm-page-copy">{{ __('app.generated_classes_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.schedule-series.index', $account)" variant="secondary">{{ __('app.schedule_series') }}</x-ui.button>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($scheduledClasses as $scheduledClass)
            @php
                $timezone = $scheduledClass->displayTimezone();
                $startsAt = $scheduledClass->starts_at->copy()->timezone($timezone);
            @endphp
            <div class="border-b border-slate-100 px-5 py-4 last:border-b-0">
                <div class="grid gap-3 lg:grid-cols-[1.2fr_1fr_1fr_1fr_auto] lg:items-center">
                    <div>
                        <div class="font-semibold">{{ $scheduledClass->title }}</div>
                        <div class="mt-1 text-sm text-slate-500">{{ $scheduledClass->classType?->name ?? __('app.class_type') }}</div>
                    </div>
                    <div class="text-sm text-slate-500">{{ $startsAt->format('Y-m-d H:i') }}</div>
                    <div class="text-sm text-slate-500">{{ $scheduledClass->location->name }} · {{ $scheduledClass->room?->name ?? __('app.room') }}</div>
                    <div class="text-sm text-slate-500">{{ $scheduledClass->trainer?->name ?? 'TBA' }}</div>
                    <span class="{{ $scheduledClass->status->value === 'cancelled' ? 'crm-status-danger' : ($scheduledClass->status->value === 'draft' ? 'crm-status-muted' : 'crm-status-scheduled') }}">{{ __('app.'.$scheduledClass->status->value) }}</span>
                </div>

                @can('manageBookings', $account)
                    <form method="POST" action="{{ route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $scheduledClass]) }}" class="mt-4 grid gap-3 rounded-lg bg-slate-50 p-3 sm:grid-cols-[1fr_1fr_auto]">
                        @csrf
                        <select name="customer_id" class="crm-field">
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->phone ? ' · '.$customer->phone : '' }}</option>
                            @endforeach
                        </select>
                        <input name="notes" class="crm-field" placeholder="{{ __('app.notes') }}">
                        <x-ui.button type="submit" :disabled="$customers->isEmpty()">{{ __('app.add_booking') }}</x-ui.button>
                    </form>
                @endcan

                @if ($scheduledClass->classBookings->isNotEmpty())
                    <div class="mt-4 space-y-2">
                        @foreach ($scheduledClass->classBookings as $booking)
                            @php
                                $bookingStatusClass = match ($booking->status->value) {
                                    'attended' => 'crm-status-active',
                                    'cancelled', 'no_show' => 'crm-status-danger',
                                    default => 'crm-status-scheduled',
                                };
                            @endphp
                            <div class="grid gap-3 rounded-lg border border-slate-200 p-3 text-sm lg:grid-cols-[1fr_180px_auto] lg:items-center">
                                <div>
                                    <div class="font-semibold text-slate-950">{{ $booking->customer->name }}</div>
                                    <div class="text-slate-500">{{ $booking->customer->phone ?? $booking->customer->email ?? __('app.no_contact') }}</div>
                                </div>
                                <span class="{{ $bookingStatusClass }}">{{ __('app.'.$booking->status->value) }}</span>
                                <div class="flex flex-wrap gap-2 lg:justify-end">
                                    @can('markAttendance', $account)
                                        <form method="POST" action="{{ route('dashboard.accounts.bookings.update', [$account, $booking]) }}" class="flex gap-2">
                                            @csrf
                                            @method('PATCH')
                                            <select name="status" class="crm-field min-w-36">
                                                @foreach ($bookingStatuses as $status)
                                                    <option value="{{ $status->value }}" @selected($booking->status === $status)>{{ __('app.'.$status->value) }}</option>
                                                @endforeach
                                            </select>
                                            <x-ui.button type="submit" variant="secondary" size="sm">{{ __('app.save') }}</x-ui.button>
                                        </form>
                                    @endcan
                                    @can('manageBookings', $account)
                                        <form method="POST" action="{{ route('dashboard.accounts.bookings.destroy', [$account, $booking]) }}" data-confirm-delete>
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" variant="danger" size="sm">{{ __('app.delete') }}</x-ui.button>
                                        </form>
                                    @endcan
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_public_classes')" icon="calendar" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
