@extends('layouts.app')

@section('title', __('app.trainer_salary_details').' - '.$trainer->name)

@section('content')
    @php
        $backParameters = ['account' => $account, ...$filters];
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.trainer_salary_details') }}</h1>
            <p class="crm-page-copy">{{ $trainer->name }} · {{ $filters['date_from'] }} — {{ $filters['date_to'] }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ui.button :href="route('dashboard.accounts.salary-models.index', $account)" variant="secondary">
                {{ __('app.salary_models') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.reports.trainers', $backParameters)" variant="secondary">
                {{ __('app.trainer_report_title') }}
            </x-ui.button>
        </div>
    </div>

    @if ($salary['incomplete'])
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <div class="flex items-start gap-3">
                <x-ui.icon name="triangle-alert" class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <div class="font-semibold">{{ __('app.salary_report_incomplete') }}</div>
                    <p class="mt-1 leading-6">{{ __('app.salary_trainer_incomplete_copy') }}</p>
                </div>
            </div>
        </div>
    @endif

    <div class="mt-6 grid gap-4 lg:grid-cols-[1.4fr_1fr]">
        <x-ui.panel>
            <div class="text-sm font-semibold text-slate-500">{{ __('app.salary_period_total') }}</div>
            <div class="mt-3 flex flex-wrap gap-2">
                @forelse ($salary['amounts'] as $currency => $amountCents)
                    <span class="rounded-full bg-emerald-100 px-4 py-2 text-2xl font-semibold text-emerald-800">
                        {{ \App\Support\MoneyFormatter::format($amountCents, $currency) }}
                    </span>
                @empty
                    <span class="text-lg font-semibold text-slate-500">{{ __('app.salary_no_accruals') }}</span>
                @endforelse
            </div>
        </x-ui.panel>
        <x-ui.panel>
            <div class="text-sm font-semibold text-slate-500">{{ __('app.current_salary_model') }}</div>
            <div class="mt-3 text-lg font-semibold text-slate-950">
                {{ $salary['current_model']?->name ?? __('app.salary_model_not_assigned') }}
            </div>
            @if ($salary['model_names'] !== [])
                <p class="mt-1 text-sm leading-6 text-slate-500">
                    {{ __('app.salary_models_used_in_period', ['models' => implode(', ', $salary['model_names'])]) }}
                </p>
            @endif
        </x-ui.panel>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="border-b border-stone-100 px-5 py-4">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.salary_calculation_breakdown') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.salary_calculation_breakdown_copy') }}</p>
        </div>

        <div class="divide-y divide-stone-100">
            @forelse ($entries as $entry)
                <article class="p-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span @class([
                                    'rounded-full px-3 py-1 text-xs font-semibold',
                                    'bg-brand-50 text-brand-700' => $entry['kind'] === 'class',
                                    'bg-violet-crm-100 text-violet-crm-700' => $entry['kind'] === 'fixed',
                                ])>
                                    {{ $entry['kind'] === 'class' ? __('app.salary_class_entry') : __('app.salary_fixed_entry') }}
                                </span>
                                @if ($entry['model_name'])
                                    <span class="text-xs font-semibold text-slate-500">{{ $entry['model_name'] }}</span>
                                @endif
                            </div>

                            @if ($entry['kind'] === 'class')
                                <h3 class="mt-3 font-semibold text-slate-950">{{ $entry['class_type'] }}</h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $entry['date'] }} · {{ $entry['time'] }}
                                    @if ($entry['location'])
                                        · {{ $entry['location'] }}
                                    @endif
                                </p>
                                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.duration') }}</dt>
                                        <dd class="mt-1 font-semibold text-slate-800">{{ $entry['duration_minutes'] }} {{ __('app.minutes_short') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.actual_bookings') }}</dt>
                                        <dd class="mt-1 font-semibold text-slate-800">{{ $entry['actual_bookings'] }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.salary_counted_people') }}</dt>
                                        <dd class="mt-1 font-semibold text-slate-800">{{ $entry['counted_people'] }}</dd>
                                    </div>
                                </dl>
                            @else
                                <h3 class="mt-3 font-semibold text-slate-950">
                                    {{ __('app.salary_fixed_period', ['from' => $entry['period_start'], 'to' => $entry['period_end']]) }}
                                </h3>
                                @if (isset($entry['covered_from'], $entry['covered_to']))
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ __('app.salary_fixed_covered_days', [
                                            'from' => $entry['covered_from'],
                                            'to' => $entry['covered_to'],
                                            'count' => $entry['covered_days'],
                                        ]) }}
                                    </p>
                                @endif
                            @endif

                            @if ($entry['formula'])
                                <div class="mt-4 rounded-lg bg-slate-50 px-3 py-2 font-mono text-sm text-slate-700">
                                    {{ $entry['formula'] }} {{ $entry['currency'] }}
                                </div>
                            @elseif ($entry['reason_key'])
                                <div class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">
                                    {{ __('app.'.$entry['reason_key']) }}
                                </div>
                            @endif
                        </div>

                        <div class="shrink-0 text-left lg:text-right">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.salary_accrued') }}</div>
                            <div @class([
                                'mt-1 text-xl font-semibold',
                                'text-slate-950' => $entry['amount_cents'] !== null,
                                'text-amber-700' => $entry['amount_cents'] === null,
                            ])>
                                {{ $entry['amount_cents'] === null
                                    ? __('app.not_calculated')
                                    : \App\Support\MoneyFormatter::format($entry['amount_cents'], $entry['currency']) }}
                            </div>
                        </div>
                    </div>
                </article>
            @empty
                <x-ui.empty-state :title="__('app.salary_no_entries')" icon="banknote" class="m-5">
                    {{ __('app.salary_no_entries_copy') }}
                </x-ui.empty-state>
            @endforelse
        </div>
    </x-ui.panel>

    @if ($entries->hasPages())
        <div class="mt-6">{{ $entries->links() }}</div>
    @endif
@endsection
