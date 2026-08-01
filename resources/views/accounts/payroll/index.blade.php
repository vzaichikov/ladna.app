@extends('layouts.app')

@section('title', __('app.payroll_runs').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.payroll_runs') }}</h1>
            <p class="crm-page-copy">{{ __('app.payroll_runs_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.salary-models.index', $account)" variant="secondary">
            {{ __('app.salary_models') }}
        </x-ui.button>
    </div>

    @include('accounts.finance._nav', ['financeSection' => 'payroll'])

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,0.7fr)]">
        <x-ui.panel>
            <div class="flex items-start gap-3">
                <div class="rounded-xl bg-violet-crm-100 p-3 text-violet-crm-700">
                    <x-ui.icon name="calendar" class="h-5 w-5" />
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.payroll_current_cadence') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">
                        {{ __($account->payroll_cadence->labelKey()) }}
                        @if ($account->payroll_cadence === \App\Enums\PayrollCadence::Biweekly && $account->payroll_anchor_date)
                            · {{ __('app.payroll_anchor_date_value', ['date' => $account->payroll_anchor_date->format('d.m.Y')]) }}
                        @endif
                    </p>
                </div>
            </div>
            <p class="mt-4 rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                {{ __('app.payroll_cadence_explanation') }}
            </p>
            <div class="mt-3 rounded-xl border border-violet-crm-100 bg-violet-crm-50 p-4 text-sm text-slate-700">
                <div class="font-semibold text-slate-950">{{ __('app.cash_control_operations_help_title') }}</div>
                <p class="mt-1 leading-6">{{ __('app.payroll_cadence_change_help') }}</p>
            </div>

            <form
                method="POST"
                action="{{ route('dashboard.accounts.payroll.cadence.update', $account) }}"
                class="mt-5 space-y-4"
                data-confirm-action
                data-confirm-title="{{ __('app.confirm_payroll_cadence_title') }}"
                data-confirm-body="{{ __('app.confirm_payroll_cadence_body') }}"
                data-confirm-accept="{{ __('app.save_payroll_cadence') }}"
                data-confirm-variant="primary"
                data-confirm-icon="calendar-sync"
            >
                @csrf
                @method('PATCH')

                <label class="block">
                    <span class="crm-label">{{ __('app.payroll_current_cadence') }}</span>
                    <select name="cadence" class="crm-field">
                        @foreach ($cadences as $cadence)
                            <option value="{{ $cadence->value }}" @selected(old('cadence', $account->payroll_cadence->value) === $cadence->value)>
                                {{ __($cadence->labelKey()) }}
                            </option>
                        @endforeach
                    </select>
                    @error('cadence') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="crm-label">{{ __('app.payroll_anchor_date') }}</span>
                    <input
                        type="date"
                        name="payroll_anchor_date"
                        value="{{ old('payroll_anchor_date', $account->payroll_anchor_date?->toDateString()) }}"
                        class="crm-field"
                    >
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.payroll_anchor_date_copy') }}</span>
                    @error('payroll_anchor_date') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <x-ui.button type="submit" variant="secondary">{{ __('app.save_payroll_cadence') }}</x-ui.button>
            </form>
        </x-ui.panel>

        <x-ui.panel>
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.close_payroll_period') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.close_payroll_period_copy') }}</p>
            <div class="mt-4 rounded-xl border border-brand-100 bg-brand-50 p-4 text-sm text-slate-700">
                <div class="font-semibold text-slate-950">{{ __('app.cash_control_operations_help_title') }}</div>
                <p class="mt-1 leading-6">{{ __('app.payroll_close_operation_help') }}</p>
            </div>

            <form
                method="POST"
                action="{{ route('dashboard.accounts.payroll.runs.store', $account) }}"
                class="mt-5 space-y-4"
                data-confirm-action
                data-confirm-title="{{ __('app.confirm_payroll_close_title') }}"
                data-confirm-body="{{ __('app.confirm_payroll_close_body') }}"
                data-confirm-accept="{{ __('app.close_payroll_period') }}"
                data-confirm-variant="primary"
                data-confirm-icon="lock"
            >
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="crm-label">{{ __('app.date_from') }}</span>
                        <input
                            type="date"
                            name="period_starts_on"
                            value="{{ old('period_starts_on', $suggestedPeriod['starts_on']->toDateString()) }}"
                            class="crm-field"
                        >
                        @error('period_starts_on') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.date_to') }}</span>
                        <input
                            type="date"
                            name="period_ends_on"
                            value="{{ old('period_ends_on', $suggestedPeriod['ends_on']->toDateString()) }}"
                            class="crm-field"
                        >
                        @error('period_ends_on') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>

                <x-ui.button type="submit" class="w-full sm:w-auto">
                    <x-ui.icon name="lock" class="h-4 w-4" />
                    {{ __('app.close_payroll_period') }}
                </x-ui.button>
            </form>
        </x-ui.panel>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="border-b border-stone-100 px-5 py-4">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.payroll_run_history') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.payroll_run_history_copy') }}</p>
        </div>

        <div class="divide-y divide-stone-100">
            @forelse ($payrollRuns as $run)
                <article class="p-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span @class([
                                    'rounded-full px-3 py-1 text-xs font-semibold',
                                    'bg-emerald-100 text-emerald-800' => ! $run->isVoided(),
                                    'bg-rose-100 text-rose-800' => $run->isVoided(),
                                ])>
                                    {{ $run->isVoided() ? __('app.payroll_status_voided') : __('app.payroll_status_closed') }}
                                </span>
                                <span class="text-xs font-semibold text-slate-500">#{{ $run->id }}</span>
                                @if ($run->supersedes)
                                    <span class="rounded-full bg-violet-crm-100 px-3 py-1 text-xs font-semibold text-violet-crm-700">
                                        {{ __('app.payroll_replaces_run', ['id' => $run->supersedes->id]) }}
                                    </span>
                                @endif
                            </div>

                            <h3 class="mt-3 text-lg font-semibold text-slate-950">
                                {{ $run->period_starts_on->format('d.m.Y') }} — {{ $run->period_ends_on->format('d.m.Y') }}
                            </h3>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ __($run->cadence->labelKey()) }} · {{ trans_choice('app.payroll_trainers_count', $run->lines->count(), ['count' => $run->lines->count()]) }}
                            </p>

                            @if ($run->lines->isNotEmpty())
                                <div class="mt-4 flex flex-wrap gap-2">
                                    @foreach ($run->lines as $line)
                                        <span class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                            <span class="font-semibold">{{ $line->trainer->name }}</span>
                                            @foreach ($line->amounts as $currency => $amountCents)
                                                · {{ \App\Support\MoneyFormatter::format($amountCents, $currency) }}
                                            @endforeach
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            @if ($run->isVoided())
                                <div class="mt-4 rounded-xl border border-rose-100 bg-rose-50 p-4 text-sm text-rose-800">
                                    <div class="font-semibold">{{ __('app.payroll_void_reason') }}</div>
                                    <p class="mt-1 leading-6">{{ $run->void_reason }}</p>
                                </div>
                            @endif
                        </div>

                        <div class="shrink-0 lg:text-right">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.salary_accrued') }}</div>
                            <div class="mt-2 flex flex-wrap gap-2 lg:justify-end">
                                @forelse ($run->totals as $currency => $amountCents)
                                    <span class="rounded-full bg-emerald-100 px-3 py-1.5 font-semibold text-emerald-800">
                                        {{ \App\Support\MoneyFormatter::format($amountCents, $currency) }}
                                    </span>
                                @empty
                                    <span class="text-sm font-semibold text-slate-500">{{ __('app.salary_no_accruals') }}</span>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-wrap items-start gap-3 border-t border-stone-100 pt-4">
                        @if (! $run->isVoided())
                            <p class="w-full rounded-lg bg-rose-50 p-3 text-sm leading-6 text-rose-800">{{ __('app.payroll_void_operation_help') }}</p>
                            <form
                                method="POST"
                                action="{{ route('dashboard.accounts.payroll.runs.void', [$account, $run]) }}"
                                class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-end"
                                data-confirm-action
                                data-confirm-title="{{ __('app.void_payroll_run') }}"
                                data-confirm-body="{{ __('app.void_payroll_run_confirm') }}"
                                data-confirm-accept="{{ __('app.void_payroll_run') }}"
                                data-confirm-variant="danger"
                                data-confirm-icon="ban"
                            >
                                @csrf
                                @method('PATCH')
                                <label class="block min-w-72 grow">
                                    <span class="crm-label">{{ __('app.payroll_void_reason') }}</span>
                                    <input type="text" name="reason" class="crm-field" required minlength="3" maxlength="1000">
                                </label>
                                <x-ui.button type="submit" variant="danger">{{ __('app.void_payroll_run') }}</x-ui.button>
                            </form>
                        @elseif ($run->replacements->where('status', \App\Models\PayrollRun::StatusClosed)->isEmpty())
                            <p class="w-full rounded-lg bg-violet-crm-50 p-3 text-sm leading-6 text-slate-700">{{ __('app.payroll_replace_operation_help') }}</p>
                            <form
                                method="POST"
                                action="{{ route('dashboard.accounts.payroll.runs.store', $account) }}"
                                data-confirm-action
                                data-confirm-title="{{ __('app.confirm_payroll_replace_title') }}"
                                data-confirm-body="{{ __('app.confirm_payroll_replace_body') }}"
                                data-confirm-accept="{{ __('app.replace_payroll_run') }}"
                                data-confirm-variant="primary"
                                data-confirm-icon="refresh-cw"
                            >
                                @csrf
                                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <input type="hidden" name="period_starts_on" value="{{ $run->period_starts_on->toDateString() }}">
                                <input type="hidden" name="period_ends_on" value="{{ $run->period_ends_on->toDateString() }}">
                                <input type="hidden" name="supersedes_payroll_run_id" value="{{ $run->id }}">
                                <x-ui.button type="submit" variant="secondary">
                                    <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                                    {{ __('app.replace_payroll_run') }}
                                </x-ui.button>
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <x-ui.empty-state :title="__('app.payroll_runs_empty')" icon="banknote" class="m-5">
                    {{ __('app.payroll_runs_empty_copy') }}
                </x-ui.empty-state>
            @endforelse
        </div>
    </x-ui.panel>

    @if ($payrollRuns->hasPages())
        <div class="mt-6">{{ $payrollRuns->links() }}</div>
    @endif
@endsection
