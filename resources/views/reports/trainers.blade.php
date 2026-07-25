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
        <div class="flex flex-wrap gap-2 sm:justify-end">
            @if ($canManageStudioCashflow)
                <x-ui.button :href="route('dashboard.accounts.salary-models.index', $account)" variant="secondary">
                    {{ __('app.salary_models') }}
                </x-ui.button>
            @endif
            <x-ui.button :href="route('dashboard.accounts.reports.index', $account)" variant="secondary">
                {{ __('app.reports') }}
            </x-ui.button>
        </div>
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

    <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.metric :label="__('app.trainer_report_total_classes')" :value="$totals['classes_count']" icon="calendar" accent="brand" />
        <x-ui.metric :label="__('app.private_lessons')" :value="$totals['private_lessons_count']" icon="user" accent="violet" />
        <x-ui.metric :label="__('app.trainer_report_group_people_count')" :value="$totals['group_people_count']" icon="accounts" accent="emerald" />
        <x-ui.metric :label="__('app.trainer_report_private_people_count')" :value="$totals['private_people_count']" icon="user" accent="amber" />
    </section>

    @if ($canManageStudioCashflow && $salaryReport)
        @if ($salaryReport['incomplete'])
            <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <div class="flex items-start gap-3">
                    <x-ui.icon name="triangle-alert" class="mt-0.5 h-5 w-5 shrink-0" />
                    <div>
                        <div class="font-semibold">{{ __('app.salary_report_incomplete') }}</div>
                        <p class="mt-1 leading-6">{{ __('app.salary_report_incomplete_copy') }}</p>
                    </div>
                </div>
            </div>
        @endif
        @if ($salaryReport['fixed_ignores_location'])
            <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
                {{ __('app.fixed_salary_location_filter_notice') }}
            </div>
        @endif
        <x-ui.panel class="mt-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.salary_period_total') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ __('app.salary_period_total_copy') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @forelse ($salaryReport['totals'] as $currency => $amountCents)
                        <span class="rounded-full bg-emerald-100 px-4 py-2 text-lg font-semibold text-emerald-800">
                            {{ \App\Support\MoneyFormatter::format($amountCents, $currency) }}
                        </span>
                    @empty
                        <span class="text-sm font-semibold text-slate-500">{{ __('app.amount_not_specified') }}</span>
                    @endforelse
                </div>
            </div>
        </x-ui.panel>
    @endif

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div @class([
            'hidden gap-3 border-b border-stone-100 px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500 lg:grid',
            'lg:grid-cols-6' => $canManageStudioCashflow,
            'lg:grid-cols-5' => ! $canManageStudioCashflow,
        ])>
            <div>{{ __('app.trainer') }}</div>
            <div>{{ __('app.trainer_report_total_classes') }}</div>
            <div>{{ __('app.private_lessons') }}</div>
            <div>{{ __('app.trainer_report_group_people_count') }}</div>
            <div>{{ __('app.trainer_report_private_people_count') }}</div>
            @if ($canManageStudioCashflow)
                <div>{{ __('app.salary') }}</div>
            @endif
        </div>

        @forelse ($rows as $row)
            @php
                $trainer = $row['trainer'];
                $detailUrl = route('dashboard.accounts.reports.trainers.private-lessons', [$account, $trainer]).'?'.http_build_query($filters);
                $historyParameters = [
                    'account' => $account,
                    'date_from' => $filters['date_from'],
                    'date_to' => $filters['date_to'],
                    'trainers' => [$trainer->id],
                    'schedule_kinds' => [\App\Enums\ScheduleKind::PrivateLesson->value],
                ];
                if ($selectedLocationId !== null) {
                    $historyParameters['locations'] = [$selectedLocationId];
                }
                $salary = $row['salary'] ?? null;
                $salaryDetailParameters = [
                    'account' => $account,
                    'trainer' => $trainer,
                    ...$filters,
                ];
            @endphp
            <article
                @class([
                    'crm-row lg:items-center',
                    'lg:grid-cols-6' => $canManageStudioCashflow,
                    'lg:grid-cols-5' => ! $canManageStudioCashflow,
                ])
                data-trainer-report-row
                data-trainer-id="{{ $trainer->id }}"
                data-classes-count="{{ $row['classes_count'] }}"
                data-private-lessons-count="{{ $row['private_lessons_count'] }}"
                data-group-people-count="{{ $row['group_people_count'] }}"
                data-private-people-count="{{ $row['private_people_count'] }}"
                data-report-metrics="{{ $trainer->id }}:{{ $row['classes_count'] }}:{{ $row['private_lessons_count'] }}:{{ $row['group_people_count'] }}:{{ $row['private_people_count'] }}"
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
                    <div class="flex items-center gap-2" data-private-lesson-count-actions>
                        <button
                            type="button"
                            class="text-left text-2xl font-semibold text-brand-700 underline decoration-brand-200 underline-offset-4 transition hover:text-brand-900"
                            data-trainer-private-lessons-open
                            data-url="{{ $detailUrl }}"
                            data-trainer-name="{{ $trainer->name }}"
                        >{{ $row['private_lessons_count'] }}</button>
                        <x-ui.action-button
                            :href="route('dashboard.accounts.scheduled-classes-history.index', $historyParameters)"
                            icon="filter"
                            :label="__('app.open_filtered_class_history')"
                            data-filtered-history-link
                        />
                    </div>
                    <div class="mt-1 text-xs font-medium text-slate-500">{{ __('app.private_lessons') }}</div>
                </div>
                <div>
                    <div class="text-2xl font-semibold text-slate-950">{{ $row['group_people_count'] }}</div>
                    <div class="mt-1 text-xs font-medium text-slate-500">{{ __('app.trainer_report_group_people_count') }}</div>
                </div>
                <div>
                    <div class="text-2xl font-semibold text-slate-950">{{ $row['private_people_count'] }}</div>
                    <div class="mt-1 text-xs font-medium text-slate-500">{{ __('app.trainer_report_private_people_count') }}</div>
                </div>
                @if ($canManageStudioCashflow)
                    <div>
                        @if ($salary && $salary['amounts'] !== [])
                            <div class="flex flex-col items-start gap-1">
                                @foreach ($salary['amounts'] as $currency => $amountCents)
                                    <a href="{{ route('dashboard.accounts.reports.trainers.salary', $salaryDetailParameters) }}" class="text-lg font-semibold text-brand-700 underline decoration-brand-200 underline-offset-4 hover:text-brand-900">
                                        {{ \App\Support\MoneyFormatter::format($amountCents, $currency) }}
                                    </a>
                                @endforeach
                            </div>
                            <div class="mt-1 text-xs font-medium text-slate-500">
                                {{ implode(', ', $salary['model_names']) ?: __('app.salary_model_not_assigned') }}
                            </div>
                            @if ($salary['incomplete'])
                                <div class="mt-1 text-xs font-semibold text-amber-700">{{ __('app.salary_incomplete_short') }}</div>
                            @endif
                        @else
                            <a href="{{ route('dashboard.accounts.reports.trainers.salary', $salaryDetailParameters) }}" class="text-sm font-semibold text-amber-700 underline underline-offset-4">
                                {{ $salary && $salary['current_model'] ? __('app.salary_no_accruals') : __('app.salary_model_not_assigned') }}
                            </a>
                            @if ($salary && $salary['current_model'])
                                <div class="mt-1 text-xs font-medium text-slate-500">{{ $salary['current_model']->name }}</div>
                            @endif
                        @endif
                    </div>
                @endif
            </article>
        @empty
            <x-ui.empty-state :title="__('app.no_trainer_report_rows')" icon="trainers" class="m-5" />
        @endforelse
    </x-ui.panel>

    <div
        class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="trainer-private-lessons-title"
        data-trainer-private-lessons-modal
        data-title="{{ __('app.private_lesson_details') }}"
        data-loading="{{ __('app.loading') }}"
        data-error="{{ __('app.private_lesson_details_load_failed') }}"
    >
        <div class="flex max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-start justify-between gap-4 border-b border-stone-200 px-5 py-4">
                <div>
                    <h2 id="trainer-private-lessons-title" class="text-xl font-semibold text-slate-950" data-trainer-private-lessons-title>{{ __('app.private_lesson_details') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ __('app.private_lesson_details_copy') }}</p>
                </div>
                <x-ui.action-button type="button" icon="close" :label="__('app.cancel')" data-trainer-private-lessons-close />
            </div>
            <div class="min-h-48 overflow-y-auto p-5" data-trainer-private-lessons-content>
                <p class="text-sm text-slate-500">{{ __('app.loading') }}</p>
            </div>
        </div>
    </div>
@endsection
