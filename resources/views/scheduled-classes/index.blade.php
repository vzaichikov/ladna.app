@extends('layouts.app')

@section('title', __('app.generated_classes').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.generated_classes') }}</h1>
            <p class="crm-page-copy">{{ $account->name }}</p>
        </div>
        <a href="{{ route('dashboard.accounts.schedule-series.index', $account) }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 shadow-xs transition hover:border-slate-300 hover:bg-slate-50">{{ __('app.schedule_series') }}</a>
    </div>

    <div class="mt-8 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-crm">
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
                    <span class="text-sm font-semibold">{{ $scheduledClass->durationMinutes() }} {{ __('app.minutes') }}</span>
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
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-violet-crm-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-crm-700" @disabled($customers->isEmpty())>{{ __('app.add_booking') }}</button>
                    </form>
                @endcan

                @if ($scheduledClass->classBookings->isNotEmpty())
                    <div class="mt-4 space-y-2">
                        @foreach ($scheduledClass->classBookings as $booking)
                            <div class="grid gap-3 rounded-lg border border-slate-200 p-3 text-sm lg:grid-cols-[1fr_180px_auto] lg:items-center">
                                <div>
                                    <div class="font-semibold text-slate-950">{{ $booking->customer->name }}</div>
                                    <div class="text-slate-500">{{ $booking->customer->phone ?? $booking->customer->email ?? __('app.no_contact') }}</div>
                                </div>
                                <span class="font-semibold text-slate-600">{{ __('app.'.$booking->status->value) }}</span>
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
                                            <button type="submit" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-xs transition hover:bg-slate-50">{{ __('app.save') }}</button>
                                        </form>
                                    @endcan
                                    @can('manageBookings', $account)
                                        <form method="POST" action="{{ route('dashboard.accounts.bookings.destroy', [$account, $booking]) }}" data-confirm-delete>
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100">{{ __('app.delete') }}</button>
                                        </form>
                                    @endcan
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="p-8 text-center text-slate-500">{{ __('app.no_public_classes') }}</div>
        @endforelse
    </div>
@endsection
