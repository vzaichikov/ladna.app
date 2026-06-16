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
            <div class="grid gap-3 border-b border-slate-100 px-5 py-4 last:border-b-0 lg:grid-cols-[1.2fr_1fr_1fr_1fr_auto] lg:items-center">
                <div>
                    <div class="font-semibold">{{ $scheduledClass->title }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $scheduledClass->classType?->name ?? __('app.class_type') }}</div>
                </div>
                <div class="text-sm text-slate-500">{{ $startsAt->format('Y-m-d H:i') }}</div>
                <div class="text-sm text-slate-500">{{ $scheduledClass->location->name }} · {{ $scheduledClass->room?->name ?? __('app.room') }}</div>
                <div class="text-sm text-slate-500">{{ $scheduledClass->instructor?->name ?? 'TBA' }}</div>
                <span class="text-sm font-semibold">{{ $scheduledClass->durationMinutes() }} {{ __('app.minutes') }}</span>
            </div>
        @empty
            <div class="p-8 text-center text-slate-500">{{ __('app.no_public_classes') }}</div>
        @endforelse
    </div>
@endsection
