@extends('layouts.app')

@section('title', __('app.trainer_private_timeframes').' - '.$trainer->name)

@section('content')
    @php
        $baseRoute = $adminMode
            ? 'dashboard.accounts.trainers.private-timeframes.edit'
            : 'dashboard.accounts.trainer-private-timeframes.mine';
        $baseParams = $adminMode ? [$account, $trainer] : [$account];
        $weekQuery = ['location_id' => $selectedLocation->id, 'week' => $weekStart->toDateString()];
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.trainer_private_timeframes') }}</h1>
            <p class="crm-page-copy">{{ $trainer->name }} · {{ $account->name }}</p>
        </div>
        @if ($adminMode)
            <x-ui.button :href="route('dashboard.accounts.trainers.edit', [$account, $trainer])" variant="secondary">
                <x-ui.icon name="arrow-left" class="h-4 w-4" />
                {{ __('app.back') }}
            </x-ui.button>
        @endif
    </div>

    <div class="mt-4 flex flex-wrap gap-2 rounded-lg border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 shadow-sm">
        <span class="inline-flex items-center gap-2">
            <span class="h-3 w-3 rounded border border-stone-200 bg-white"></span>
            {{ __('app.trainer_private_timeframe_legend_available') }}
        </span>
        <span class="inline-flex items-center gap-2">
            <span class="h-3 w-3 rounded border border-emerald-300 bg-emerald-50"></span>
            {{ __('app.trainer_private_timeframe_legend_selected') }}
        </span>
        <span class="inline-flex items-center gap-2">
            <span class="h-3 w-3 rounded border border-amber-200 bg-amber-50"></span>
            {{ __('app.trainer_private_timeframe_legend_own_class') }}
        </span>
        <span class="inline-flex items-center gap-2">
            <span class="h-3 w-3 rounded border border-slate-200 bg-slate-100"></span>
            {{ __('app.trainer_private_timeframe_legend_unavailable') }}
        </span>
    </div>

    <div class="mt-6 flex gap-2 overflow-x-auto pb-1">
        @foreach ($locations as $location)
            <a
                href="{{ route($baseRoute, [...$baseParams, 'location_id' => $location->id, 'week' => $weekStart->toDateString()]) }}"
                class="inline-flex shrink-0 items-center justify-center rounded-lg border px-4 py-2 text-sm font-semibold transition {{ $selectedLocation->id === $location->id ? 'border-violet-crm-300 bg-violet-crm-50 text-violet-crm-800' : 'border-stone-200 bg-white text-slate-600 hover:border-violet-crm-200 hover:text-violet-crm-700' }}"
            >
                {{ $location->name }}
            </a>
        @endforeach
    </div>

    <div class="mt-4 flex items-center justify-between gap-3 rounded-lg border border-stone-200 bg-white px-4 py-3">
        <a
            href="{{ $previousWeekStart ? route($baseRoute, [...$baseParams, 'location_id' => $selectedLocation->id, 'week' => $previousWeekStart->toDateString()]) : '#' }}"
            class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-stone-200 text-slate-600 transition hover:border-violet-crm-200 hover:text-violet-crm-700 {{ $previousWeekStart ? '' : 'pointer-events-none opacity-40' }}"
            aria-label="{{ __('app.previous_week') }}"
        >
            <x-ui.icon name="chevron-left" class="h-4 w-4" />
        </a>
        <div class="text-center">
            <div class="text-sm font-semibold text-slate-950">{{ $weekStart->translatedFormat('j F') }} - {{ $weekEnd->translatedFormat('j F') }}</div>
            <div class="mt-0.5 text-xs font-medium text-slate-500">{{ __('app.trainer_private_timeframe_weeks_window', ['weeks' => $account->trainerPrivateTimeframeWeeks()]) }}</div>
        </div>
        <a
            href="{{ $nextWeekStart ? route($baseRoute, [...$baseParams, 'location_id' => $selectedLocation->id, 'week' => $nextWeekStart->toDateString()]) : '#' }}"
            class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-stone-200 text-slate-600 transition hover:border-violet-crm-200 hover:text-violet-crm-700 {{ $nextWeekStart ? '' : 'pointer-events-none opacity-40' }}"
            aria-label="{{ __('app.next_week') }}"
        >
            <x-ui.icon name="chevron-right" class="h-4 w-4" />
        </a>
    </div>

    <div
        class="mt-5 space-y-4"
        data-trainer-private-timeframes
        data-toggle-url="{{ route('dashboard.accounts.trainers.private-timeframes.toggle', [$account, $trainer]) }}"
        data-location-id="{{ $selectedLocation->id }}"
        data-csrf-token="{{ csrf_token() }}"
    >
        @foreach ($timelineDays as $day)
            <section class="rounded-lg border border-stone-200 bg-white p-4 shadow-sm">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-slate-950">{{ $day['weekday'] }}</h2>
                        <p class="text-sm text-slate-500">{{ $day['label'] }}</p>
                    </div>
                    @if ($day['closed'])
                        <span class="crm-status-muted">{{ __('app.closed') }}</span>
                    @endif
                </div>

                @if (! $day['closed'])
                    <div class="grid grid-cols-4 gap-2 sm:grid-cols-6 md:grid-cols-8 xl:grid-cols-12">
                        @foreach ($day['cells'] as $cell)
                            @php
                                $cellClass = match (true) {
                                    $cell['selected'] => 'border-emerald-300 bg-emerald-50 text-emerald-800 shadow-sm',
                                    $cell['disabled'] && $cell['own_class'] => 'border-amber-200 bg-amber-50 text-amber-700 opacity-80',
                                    $cell['disabled'] => 'border-slate-200 bg-slate-100 text-slate-400',
                                    default => 'border-stone-200 bg-white text-slate-700 hover:border-violet-crm-300 hover:bg-violet-crm-50 hover:text-violet-crm-800',
                                };
                            @endphp
                            <button
                                type="button"
                                class="min-h-12 rounded-lg border px-2 py-2 text-center text-sm font-semibold transition {{ $cellClass }}"
                                data-timeframe-cell
                                data-starts-at="{{ $cell['starts_at'] }}"
                                data-selected="{{ $cell['selected'] ? '1' : '0' }}"
                                @disabled($cell['disabled'])
                                title="{{ $cell['own_class'] ? __('app.trainer_private_timeframe_own_class') : ($cell['fully_occupied'] ? __('app.trainer_private_timeframe_no_room') : '') }}"
                            >
                                {{ $cell['label'] }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach
    </div>
@endsection
