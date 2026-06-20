@extends('layouts.app')

@section('title', __('app.schedule_series').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.schedule_series') }}</h1>
            <p class="crm-page-copy">{{ __('app.schedule_series_copy') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ui.button :href="route('dashboard.accounts.scheduled-classes.index', $account)" variant="secondary">{{ __('app.generated_classes') }}</x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.schedule-series.create', $account)">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.create_schedule_series') }}
            </x-ui.button>
        </div>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($series as $scheduleSeries)
            <div class="crm-row lg:grid-cols-[1.1fr_1fr_1fr_1fr_auto] lg:items-center">
                <div>
                    <div class="font-semibold text-slate-950">{{ $scheduleSeries->effectiveTitle() }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $scheduleSeries->classType->activityDirection?->name ?? __('app.direction') }}</div>
                </div>
                <div class="text-sm text-slate-500">{{ $weekdays[$scheduleSeries->weekday] }} · {{ substr((string) $scheduleSeries->start_time, 0, 5) }}</div>
                <div class="text-sm text-slate-500">{{ $scheduleSeries->location->name }} · {{ $scheduleSeries->room->name }}</div>
                <div class="text-sm text-slate-500">{{ $scheduleSeries->trainer?->name ?? __('app.trainer_not_assigned') }}</div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="{{ $scheduleSeries->status->value === 'active' ? 'crm-status-active' : 'crm-status-muted' }}">{{ __('app.'.$scheduleSeries->status->value) }}</span>
                    <x-ui.button :href="route('dashboard.accounts.schedule-series.edit', [$account, $scheduleSeries])" variant="secondary" size="sm">{{ __('app.edit') }}</x-ui.button>
                    <form method="POST" action="{{ route('dashboard.accounts.schedule-series.destroy', [$account, $scheduleSeries]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="danger" size="sm">{{ __('app.delete') }}</x-ui.button>
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_schedule_series')" icon="schedule" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
