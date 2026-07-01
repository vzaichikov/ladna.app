@extends('layouts.app')

@section('title', __('app.trainer_report_title').' - '.$account->name)

@section('content')
    @php
        $selectedStatuses = $filters['booking_statuses'];
        $selectedLocationId = $filters['location_id'];
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.trainer_report_title') }}</h1>
            <p class="crm-page-copy">{{ __('app.trainer_report_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.reports.index', $account)" variant="secondary">
            {{ __('app.reports') }}
        </x-ui.button>
    </div>

    <form method="GET" action="{{ route('dashboard.accounts.reports.trainers', $account) }}" class="mt-6 rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-[1fr_1fr_1.2fr]">
            <label class="block">
                <span class="crm-label">{{ __('app.date_from') }}</span>
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="crm-field">
                @error('date_from') <span class="crm-help">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="crm-label">{{ __('app.date_to') }}</span>
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="crm-field">
                @error('date_to') <span class="crm-help">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="crm-label">{{ __('app.location') }}</span>
                <select name="location_id" class="crm-field">
                    <option value="">{{ __('app.all_locations') }}</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected($selectedLocationId === $location->id)>{{ $location->name }}</option>
                    @endforeach
                </select>
                @error('location_id') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>

        <fieldset class="mt-4">
            <legend class="crm-label">{{ __('app.booking_statuses') }}</legend>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($statuses as $status)
                    <label @class([
                        'inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold transition',
                        'border-brand-200 bg-brand-50 text-brand-700' => in_array($status->value, $selectedStatuses, true),
                        'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' => ! in_array($status->value, $selectedStatuses, true),
                    ])>
                        <input type="checkbox" name="booking_statuses[]" value="{{ $status->value }}" class="size-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500" @checked(in_array($status->value, $selectedStatuses, true))>
                        <span>{{ __('app.'.$status->value) }}</span>
                    </label>
                @endforeach
            </div>
            @error('booking_statuses') <span class="crm-help">{{ $message }}</span> @enderror
            @error('booking_statuses.*') <span class="crm-help">{{ $message }}</span> @enderror
        </fieldset>

        <div class="mt-4 flex flex-wrap gap-2">
            <x-ui.button type="submit" size="sm">
                <x-ui.icon name="search" class="h-4 w-4" />
                {{ __('app.apply_filters') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.reports.trainers', $account)" variant="secondary" size="sm">{{ __('app.reset_filters') }}</x-ui.button>
        </div>
    </form>

    <section class="mt-6 grid gap-4 sm:grid-cols-2">
        <x-ui.metric :label="__('app.trainer_report_total_classes')" :value="$totals['classes_count']" icon="calendar" accent="brand" />
        <x-ui.metric :label="__('app.trainer_report_people_count')" :value="$totals['people_count']" icon="accounts" accent="emerald" />
    </section>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="hidden gap-3 border-b border-stone-100 px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500 lg:flex">
            <div class="flex-1">{{ __('app.trainer') }}</div>
            <div class="flex-1">{{ __('app.trainer_report_total_classes') }}</div>
            <div class="flex-1">{{ __('app.trainer_report_people_count') }}</div>
        </div>

        @forelse ($rows as $row)
            @php
                $trainer = $row['trainer'];
            @endphp
            <article
                class="crm-row lg:grid-cols-3 lg:items-center"
                data-trainer-report-row
                data-trainer-id="{{ $trainer->id }}"
                data-classes-count="{{ $row['classes_count'] }}"
                data-people-count="{{ $row['people_count'] }}"
                data-report-metrics="{{ $trainer->id }}:{{ $row['classes_count'] }}:{{ $row['people_count'] }}"
            >
                <div class="flex min-w-0 items-center gap-4">
                    @if ($trainer->photoUrl())
                        <img src="{{ $trainer->photoUrl() }}" alt="" class="h-11 w-11 rounded-full object-cover">
                    @else
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-violet-crm-100 text-violet-crm-700">
                            <x-ui.icon name="trainers" class="h-5 w-5" />
                        </span>
                    @endif
                    <div class="min-w-0">
                        <h2 class="truncate font-semibold text-slate-950">{{ $trainer->name }}</h2>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <x-ui.trainer-type-badge :trainer-type="$trainer->trainerType" />
                            <span class="{{ $trainer->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                                {{ $trainer->is_active ? __('app.active') : __('app.inactive') }}
                            </span>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="text-2xl font-semibold text-slate-950">{{ $row['classes_count'] }}</div>
                    <div class="mt-1 text-xs font-medium text-slate-500">{{ __('app.trainer_report_total_classes') }}</div>
                </div>
                <div>
                    <div class="text-2xl font-semibold text-slate-950">{{ $row['people_count'] }}</div>
                    <div class="mt-1 text-xs font-medium text-slate-500">{{ __('app.trainer_report_people_count') }}</div>
                </div>
            </article>
        @empty
            <x-ui.empty-state :title="__('app.no_trainer_report_rows')" icon="trainers" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
