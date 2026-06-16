@extends('layouts.app')

@section('title', __('app.schedule_series').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.schedule_series') }}</h1>
            <p class="crm-page-copy">{{ $account->name }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('dashboard.accounts.scheduled-classes.index', $account) }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 shadow-xs transition hover:border-slate-300 hover:bg-slate-50">{{ __('app.generated_classes') }}</a>
            <a href="{{ route('dashboard.accounts.schedule-series.create', $account) }}" class="inline-flex items-center justify-center rounded-lg bg-violet-crm-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-crm-700">{{ __('app.create_schedule_series') }}</a>
        </div>
    </div>

    <div class="mt-8 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-crm">
        @forelse ($series as $scheduleSeries)
            <div class="grid gap-3 border-b border-slate-100 px-5 py-4 last:border-b-0 lg:grid-cols-[1.1fr_1fr_1fr_1fr_auto] lg:items-center">
                <div>
                    <div class="font-semibold">{{ $scheduleSeries->effectiveTitle() }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $scheduleSeries->classType->activityDirection?->name ?? __('app.direction') }}</div>
                </div>
                <div class="text-sm text-slate-500">{{ $weekdays[$scheduleSeries->weekday] }} · {{ substr((string) $scheduleSeries->start_time, 0, 5) }}</div>
                <div class="text-sm text-slate-500">{{ $scheduleSeries->location->name }} · {{ $scheduleSeries->room->name }}</div>
                <div class="text-sm text-slate-500">{{ $scheduleSeries->instructor?->name ?? 'TBA' }}</div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold">{{ __('app.'.$scheduleSeries->status->value) }}</span>
                    <a href="{{ route('dashboard.accounts.schedule-series.edit', [$account, $scheduleSeries]) }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-xs transition hover:border-slate-300 hover:bg-slate-50">{{ __('app.edit') }}</a>
                    <form method="POST" action="{{ route('dashboard.accounts.schedule-series.destroy', [$account, $scheduleSeries]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-100">{{ __('app.delete') }}</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="p-8 text-center text-slate-500">{{ __('app.no_schedule_series') }}</div>
        @endforelse
    </div>
@endsection
