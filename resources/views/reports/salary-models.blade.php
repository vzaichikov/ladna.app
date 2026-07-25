@extends('layouts.app')

@section('title', __('app.salary_models').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.salary_models') }}</h1>
            <p class="crm-page-copy">{{ __('app.salary_models_copy') }}</p>
        </div>
        <div class="flex flex-wrap gap-2 sm:justify-end">
            <x-ui.button :href="route('dashboard.accounts.salary-models.create', $account)">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.create_salary_model') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.reports.trainers', $account)" variant="secondary">
                {{ __('app.trainer_report_title') }}
            </x-ui.button>
        </div>
    </div>

    @if ($unassignedTrainers->isNotEmpty())
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <div class="flex items-start gap-3">
                <x-ui.icon name="triangle-alert" class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <div class="font-semibold">{{ trans_choice('app.salary_unassigned_trainers', $unassignedTrainers->count(), ['count' => $unassignedTrainers->count()]) }}</div>
                    <p class="mt-1 leading-6">{{ __('app.salary_unassigned_trainers_copy') }}</p>
                </div>
            </div>
        </div>
    @endif

    <section class="mt-6">
        <div class="flex items-end justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.salary_models_active') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('app.salary_models_active_copy') }}</p>
            </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            @forelse ($modelCards->where('model.archived_at', null) as $card)
                @php
                    $model = $card['model'];
                    $version = $card['current_version'];
                @endphp
                <x-ui.panel class="flex flex-col">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="inline-flex rounded-full bg-violet-crm-100 px-3 py-1 text-xs font-semibold text-violet-crm-700">
                                {{ __($model->type->labelKey()) }}
                            </div>
                            <h3 class="mt-3 text-lg font-semibold text-slate-950">{{ $model->name }}</h3>
                            @if ($version)
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ __('app.active_from_date', ['date' => $version->effective_from->format('d.m.Y')]) }}
                                </p>
                            @else
                                <p class="mt-1 text-sm font-semibold text-amber-700">{{ __('app.salary_model_without_version') }}</p>
                            @endif
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                            {{ trans_choice('app.salary_assigned_trainers_count', $card['assigned_trainers'], ['count' => $card['assigned_trainers']]) }}
                        </span>
                    </div>

                    @if ($version)
                        <div class="mt-5 rounded-xl bg-slate-50 p-4 text-sm text-slate-700">
                            @if ($model->type === \App\Enums\SalaryModelType::FixedPeriod)
                                <div class="font-semibold text-slate-950">
                                    {{ \App\Support\MoneyFormatter::format($version->amount_cents ?? 0, $version->currency) }}
                                    / {{ __($version->period_unit?->labelKey() ?? 'app.salary_period_month') }}
                                </div>
                                <p class="mt-1 leading-6">{{ __('app.salary_fixed_calendar_proration_short') }}</p>
                            @else
                                <div class="font-semibold text-slate-950">
                                    {{ trans_choice('app.salary_rules_count', $version->classRules->count(), ['count' => $version->classRules->count()]) }}
                                </div>
                                <p class="mt-1 leading-6">
                                    {{ __('app.salary_counted_statuses_short', [
                                        'statuses' => collect($version->countedBookingStatusValues())->map(fn ($status) => __('app.'.$status))->join(', '),
                                    ]) }}
                                </p>
                            @endif
                        </div>
                    @endif

                    <div class="mt-auto flex flex-wrap gap-2 pt-5">
                        <x-ui.button :href="route('dashboard.accounts.salary-models.edit', [$account, $model])" variant="secondary" size="sm">
                            {{ __('app.edit_and_version') }}
                        </x-ui.button>
                        <form
                            method="POST"
                            action="{{ route('dashboard.accounts.salary-models.archive', [$account, $model]) }}"
                            data-confirm-action
                            data-confirm-title="{{ __('app.archive_salary_model') }}"
                            data-confirm-body="{{ __('app.archive_salary_model_confirm') }}"
                            data-confirm-accept="{{ __('app.archive') }}"
                            data-confirm-icon="archive"
                        >
                            @csrf
                            @method('PATCH')
                            <x-ui.button type="submit" variant="ghost" size="sm">{{ __('app.archive') }}</x-ui.button>
                        </form>
                    </div>
                </x-ui.panel>
            @empty
                <x-ui.panel class="lg:col-span-2">
                    <x-ui.empty-state :title="__('app.salary_models_empty')" icon="banknote">
                        {{ __('app.salary_models_empty_copy') }}
                    </x-ui.empty-state>
                </x-ui.panel>
            @endforelse
        </div>
    </section>

    @if ($activeModels->isNotEmpty() && $trainers->isNotEmpty())
        <x-ui.panel class="mt-6">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.assign_salary_model') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.assign_salary_model_copy') }}</p>
            </div>
            <form method="POST" action="{{ route('dashboard.accounts.salary-model-assignments.store', $account) }}" class="mt-5" data-salary-assignment-form>
                @csrf
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="block">
                        <span class="crm-label">{{ __('app.salary_model') }}</span>
                        <select name="salary_model_id" required class="crm-field">
                            <option value="">{{ __('app.choose_salary_model') }}</option>
                            @foreach ($activeModels as $model)
                                <option value="{{ $model->id }}" @selected(old('salary_model_id') == $model->id)>{{ $model->name }}</option>
                            @endforeach
                        </select>
                        @error('salary_model_id') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.effective_from') }}</span>
                        <input name="effective_from" type="date" required value="{{ old('effective_from', $assignmentDefaultDate) }}" class="crm-field">
                        @error('effective_from') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>

                <fieldset class="mt-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <legend class="crm-label">{{ __('app.trainers') }}</legend>
                        <div class="flex gap-2">
                            <button type="button" class="text-xs font-semibold text-brand-700 hover:text-brand-900" data-salary-select-unassigned>{{ __('app.select_unassigned') }}</button>
                            <button type="button" class="text-xs font-semibold text-slate-600 hover:text-slate-900" data-salary-clear-trainers>{{ __('app.clear_selection') }}</button>
                        </div>
                    </div>
                    <div class="mt-3 grid max-h-80 gap-2 overflow-y-auto rounded-xl border border-stone-200 p-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($trainers as $trainer)
                            @php($assignment = $currentAssignments->get($trainer->id))
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg p-3 hover:bg-brand-50">
                                <input
                                    type="checkbox"
                                    name="trainer_ids[]"
                                    value="{{ $trainer->id }}"
                                    class="mt-1 size-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500"
                                    data-salary-trainer
                                    data-unassigned="{{ $assignment ? 'false' : 'true' }}"
                                    @checked(in_array($trainer->id, old('trainer_ids', [])))
                                >
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-semibold text-slate-950">{{ $trainer->name }}</span>
                                    <span class="mt-1 block text-xs text-slate-500">
                                        {{ $assignment?->salaryModel?->name ?? __('app.salary_model_not_assigned') }}
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('trainer_ids') <span class="crm-help">{{ $message }}</span> @enderror
                    @error('trainer_ids.*') <span class="crm-help">{{ $message }}</span> @enderror
                </fieldset>

                <div class="mt-5 flex justify-end">
                    <x-ui.button type="submit">{{ __('app.assign_salary_model') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
    @endif

    @if ($modelCards->whereNotNull('model.archived_at')->isNotEmpty())
        <details class="mt-6 rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
            <summary class="cursor-pointer font-semibold text-slate-950">{{ __('app.archived_salary_models') }}</summary>
            <div class="mt-4 space-y-3">
                @foreach ($modelCards->whereNotNull('model.archived_at') as $card)
                    <div class="flex flex-col gap-2 rounded-xl bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="font-semibold text-slate-950">{{ $card['model']->name }}</div>
                            <div class="mt-1 text-xs text-slate-500">{{ __($card['model']->type->labelKey()) }}</div>
                        </div>
                        <span class="text-xs font-semibold text-slate-500">{{ __('app.archived') }}</span>
                    </div>
                @endforeach
            </div>
        </details>
    @endif
@endsection
