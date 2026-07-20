@extends('layouts.app')

@section('title', __('app.payments').' - '.$account->name)

@section('content')
    @php
        $formatMoney = fn (?int $cents, ?string $currency = null): string => \App\Support\MoneyFormatter::format($cents ?? 0, $currency ?: $account->default_currency);
        $formatMoneyTotals = fn (array $totals): string => collect($totals)
            ->map(fn (int $amountCents, string $currency): string => $formatMoney($amountCents, $currency))
            ->whenEmpty(fn ($values) => $values->push($formatMoney(0)))
            ->implode(' + ');
        $formatMoneyInput = static function (?int $priceCents): string {
            if ($priceCents === null) {
                return '';
            }

            $whole = intdiv($priceCents, 100);
            $fraction = $priceCents % 100;

            return $fraction === 0
                ? (string) $whole
                : number_format($priceCents / 100, 2, '.', '');
        };
        $formatPaymentDate = fn ($payment): string => \App\Support\DateTimePresenter::format($payment->effectiveOccurredAt(), $account) ?? __('app.not_set');
        $formatDateTime = fn ($date): string => \App\Support\DateTimePresenter::format($date, $account) ?? __('app.not_set');
        $formatDateTimeLocal = fn ($date): string => \App\Support\DateTimePresenter::dateTimeLocal($date, $account) ?? '';
        $providerLabelResolver = static function (string $provider): string {
            $translationKey = 'app.provider_'.$provider;
            $label = __($translationKey);

            return $label === $translationKey ? config('integrations.providers.'.$provider.'.label', $provider) : $label;
        };
        $defaultCashLocationId = old('location_id', $locations->first()?->id);
        $defaultExpenseLocationId = old('location_id', $locations->first()?->id);
        $defaultExpenseCategoryId = old('expense_category_id', $activeExpenseCategories->first()?->id);
        $defaultExpenseMethod = old('payment_method', \App\Models\StudioExpense::PaymentMethodCashdesk);
        $hasValue = static fn (mixed $value): bool => $value !== null && $value !== '';
        $paymentFilterKeys = ['search', 'payment_method', 'status', 'provider', 'location_id'];
        $expenseFilterKeys = ['expense_category_id', 'expense_payment_method', 'expense_status'];
        $paymentFilterValues = collect($filters)->only($paymentFilterKeys)->filter($hasValue)->all();
        $expenseFilterValues = collect($filters)->only($expenseFilterKeys)->filter($hasValue)->all();
        $periodPreservedFilterValues = collect($filters)->except(['date_from', 'date_to'])->filter($hasValue)->all();
        $paymentResetParameters = [
            'account' => $account,
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            ...$expenseFilterValues,
        ];
        $expenseResetParameters = [
            'account' => $account,
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            ...$paymentFilterValues,
        ];
        $accountToday = \Carbon\CarbonImmutable::now(\App\Support\DateTimePresenter::accountTimezone($account))->toDateString();
        $isToday = $filters['date_from'] === $accountToday && $filters['date_to'] === $accountToday;
        $hasAdvancedPaymentFilters = filled($filters['provider']) || filled($filters['location_id']);
    @endphp

    <div>
        <h1 class="crm-page-title">{{ __('app.payments') }}</h1>
        <p class="crm-page-copy">{{ __('app.account_payments_copy') }}</p>
    </div>

    <form method="GET" action="{{ route('dashboard.accounts.payments.index', $account) }}" class="mt-5 grid gap-3 rounded-xl border border-stone-200 bg-white p-4 shadow-crm sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
        @foreach ($periodPreservedFilterValues as $filterName => $filterValue)
            <input type="hidden" name="{{ $filterName }}" value="{{ $filterValue }}">
        @endforeach

        <label class="block">
            <span class="crm-label">{{ __('app.date_from') }}</span>
            <input name="date_from" type="date" value="{{ $filters['date_from'] }}" class="crm-field min-h-11" required>
            @error('date_from') <span class="crm-help">{{ $message }}</span> @enderror
        </label>
        <label class="block">
            <span class="crm-label">{{ __('app.date_to') }}</span>
            <input name="date_to" type="date" value="{{ $filters['date_to'] }}" class="crm-field min-h-11" required>
            @error('date_to') <span class="crm-help">{{ $message }}</span> @enderror
        </label>
        <div class="flex min-h-11 flex-wrap items-center gap-2">
            <x-ui.button type="submit" variant="secondary" class="min-h-11">
                <x-ui.icon name="calendar" class="h-4 w-4" />
                {{ __('app.apply_filters') }}
            </x-ui.button>
            <a href="{{ route('dashboard.accounts.payments.index', ['account' => $account, ...$periodPreservedFilterValues]) }}" class="crm-focus inline-flex min-h-11 items-center justify-center rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-brand-200 hover:text-brand-700">
                {{ __('app.today') }}
            </a>
        </div>
    </form>

    <section class="mt-6" data-payments-section="overview">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.period_overview') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('app.period_overview_copy') }}</p>
            </div>
            <p class="text-sm font-medium text-slate-500">{{ $filters['date_from'] }} – {{ $filters['date_to'] }}</p>
        </div>

        <x-ui.panel padding="none" class="overflow-hidden">
            <div class="grid divide-y divide-stone-100 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                <div class="flex items-center justify-between gap-4 p-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700">
                            <x-ui.icon name="payments" class="h-5 w-5" />
                        </span>
                        <span class="text-sm font-medium text-slate-500">{{ __('app.period_paid_income') }}</span>
                    </div>
                    <span class="shrink-0 text-xl font-semibold text-slate-950 sm:text-2xl">{{ $formatMoneyTotals($periodOverview['income_by_currency']) }}</span>
                </div>
                <div class="flex items-center justify-between gap-4 p-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-violet-crm-100 text-brand-700">
                            <x-ui.icon name="minus" class="h-5 w-5" />
                        </span>
                        <span class="text-sm font-medium text-slate-500">{{ __('app.period_operational_expenses') }}</span>
                    </div>
                    <span class="shrink-0 text-xl font-semibold text-slate-950 sm:text-2xl">{{ $formatMoneyTotals($periodOverview['expense_by_currency']) }}</span>
                </div>
                <div class="p-4">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-brand-700">
                                <x-ui.icon name="wallet" class="h-5 w-5" />
                            </span>
                            <span class="text-sm font-medium text-slate-500">{{ __('app.period_remaining_in_studio') }}</span>
                        </div>
                        <span class="shrink-0 text-xl font-semibold text-slate-950 sm:text-2xl">{{ $formatMoneyTotals($periodOverview['remaining_by_currency']) }}</span>
                    </div>
                    <details class="mt-2 text-xs text-slate-500">
                        <summary class="crm-focus inline-flex min-h-11 cursor-pointer items-center rounded-md px-1 font-semibold text-brand-700">{{ __('app.what_is_this') }}</summary>
                        <p class="pb-1 leading-5">{{ __('app.period_remaining_formula') }}</p>
                    </details>
                </div>
            </div>
        </x-ui.panel>
    </section>

    <section class="mt-6" data-payments-section="history">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.payment_history') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('app.payment_filters_copy') }}</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1.5 text-sm font-semibold text-slate-700">{{ __('app.payments_found', ['count' => $stats['total']]) }}</span>
        </div>

        <form method="GET" action="{{ route('dashboard.accounts.payments.index', $account) }}" class="rounded-xl border border-stone-200 bg-white p-4 shadow-crm">
            <input type="hidden" name="date_from" value="{{ $filters['date_from'] }}">
            <input type="hidden" name="date_to" value="{{ $filters['date_to'] }}">
            @foreach ($expenseFilterValues as $filterName => $filterValue)
                <input type="hidden" name="{{ $filterName }}" value="{{ $filterValue }}">
            @endforeach

            <div class="grid gap-3 md:grid-cols-[minmax(0,1.4fr)_minmax(0,0.8fr)_minmax(0,0.8fr)]">
                <label class="block min-w-0">
                    <span class="crm-label">{{ __('app.payment_search') }}</span>
                    <input name="search" type="search" value="{{ $filters['search'] }}" class="crm-field min-h-11" placeholder="{{ __('app.payment_search_placeholder') }}">
                </label>
                <label class="block min-w-0">
                    <span class="crm-label">{{ __('app.payment_method') }}</span>
                    <select name="payment_method" class="crm-field min-h-11">
                        <option value="">{{ __('app.all_payment_methods') }}</option>
                        @foreach ($paymentMethods as $paymentMethod)
                            <option value="{{ $paymentMethod }}" @selected($filters['payment_method'] === $paymentMethod)>{{ __('app.payment_method_'.$paymentMethod) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block min-w-0">
                    <span class="crm-label">{{ __('app.payment_status') }}</span>
                    <select name="status" class="crm-field min-h-11">
                        <option value="">{{ __('app.all_statuses') }}</option>
                        @foreach ($statuses as $statusOption)
                            <option value="{{ $statusOption->value }}" @selected($filters['status'] === $statusOption->value)>{{ __('app.'.$statusOption->value) }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <details class="mt-3" @if ($hasAdvancedPaymentFilters) open @endif>
                <summary class="crm-focus inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg text-sm font-semibold text-brand-700">
                    <x-ui.icon name="filter" class="h-4 w-4" />
                    {{ __('app.more_filters') }}
                </summary>
                <div class="grid gap-3 pb-1 sm:grid-cols-2">
                    <label class="block min-w-0">
                        <span class="crm-label">{{ __('app.payment_provider') }}</span>
                        <select name="provider" class="crm-field min-h-11">
                            <option value="">{{ __('app.all_payment_providers') }}</option>
                            @foreach ($providers as $providerKey => $providerOptionLabel)
                                <option value="{{ $providerKey }}" @selected($filters['provider'] === $providerKey)>{{ $providerOptionLabel }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block min-w-0">
                        <span class="crm-label">{{ __('app.payment_location') }}</span>
                        <select name="location_id" class="crm-field min-h-11">
                            <option value="">{{ __('app.all_locations') }}</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}" @selected((int) $filters['location_id'] === $location->id)>{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </details>

            <div class="mt-3 flex flex-wrap gap-2">
                <x-ui.button type="submit" variant="secondary" class="min-h-11">
                    <x-ui.icon name="search" class="h-4 w-4" />
                    {{ __('app.apply_filters') }}
                </x-ui.button>
                <a href="{{ route('dashboard.accounts.payments.index', $paymentResetParameters) }}" class="crm-focus inline-flex min-h-11 items-center justify-center rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-brand-200 hover:text-brand-700">
                    {{ __('app.reset_filters') }}
                </a>
            </div>
        </form>

        @if ($stats['pending'] > 0 || $stats['failed'] > 0 || $stats['fiscal_failed'] > 0)
            <div class="mt-4 grid gap-3 sm:grid-cols-3" aria-label="{{ __('app.payment_attention') }}">
                @if ($stats['pending'] > 0)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-900" role="status">
                        {{ __('app.payment_pending_attention', ['count' => $stats['pending']]) }}
                    </div>
                @endif
                @if ($stats['failed'] > 0)
                    <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-900" role="alert">
                        {{ __('app.payment_failed_attention', ['count' => $stats['failed']]) }}
                    </div>
                @endif
                @if ($stats['fiscal_failed'] > 0)
                    <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-900" role="alert">
                        {{ __('app.fiscalization_failed_attention', ['count' => $stats['fiscal_failed']]) }}
                    </div>
                @endif
            </div>
        @endif

        <x-ui.panel padding="none" class="mt-4 overflow-hidden">
            @forelse ($payments as $payment)
                @php
                    $paymentStatusClass = match ($payment->status->value) {
                        'payment_paid' => 'crm-status-active',
                        'payment_pending', 'payment_started' => 'crm-status-scheduled',
                        'payment_failed', 'payment_cancelled', 'payment_expired' => 'crm-status-danger',
                        default => 'crm-status-muted',
                    };
                    $receipt = $payment->fiscalReceipt;
                    $fiscalStatusClass = match ($receipt?->status?->value) {
                        'fiscalized' => 'crm-status-active',
                        'processing', 'pending' => 'crm-status-scheduled',
                        'failed' => 'crm-status-danger',
                        default => 'crm-status-muted',
                    };
                    $currentProviderLabel = $providerLabelResolver($payment->provider);
                    $paymentMethodLabel = $payment->isManualCashStudioPayment() ? __('app.payment_method_cash') : __('app.payment_method_online');
                    $showFiscalStatus = $fiscalizationEnabled && ! $payment->isManualCashStudioPayment();
                    $fiscalActionRequired = $showFiscalStatus && $receipt?->status === \App\Enums\FiscalReceiptStatus::Failed;
                @endphp

                <details class="group border-b border-stone-100 last:border-b-0" data-payment-details="{{ $payment->id }}">
                    <summary class="crm-focus min-h-11 cursor-pointer list-none p-4 transition hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
                        <div class="flex min-w-0 items-start justify-between gap-3 lg:grid lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)_140px_150px_24px] lg:items-center">
                            <div class="min-w-0">
                                <div class="break-words font-semibold text-slate-950">{{ $payment->customer?->name ?? __('app.not_set') }}</div>
                                <div class="mt-1 break-words text-sm text-slate-500">{{ $payment->plan_name }}</div>
                            </div>
                            <div class="hidden min-w-0 text-sm text-slate-500 lg:block">
                                <div>{{ $formatPaymentDate($payment) }}</div>
                                <div class="mt-1">{{ $paymentMethodLabel }}</div>
                            </div>
                            <div class="shrink-0 text-right lg:text-left">
                                <div class="font-semibold text-slate-950">{{ $formatMoney($payment->amount_cents, $payment->currency) }}</div>
                                @if ($payment->corrections->isNotEmpty())
                                    <span class="mt-1 inline-flex rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">{{ __('app.corrected_payment') }}</span>
                                @endif
                            </div>
                            <div class="hidden space-y-2 lg:block">
                                <span class="{{ $paymentStatusClass }}">{{ __('app.'.$payment->status->value) }}</span>
                                @if ($fiscalActionRequired)
                                    <div><span class="crm-status-danger">{{ __('app.fiscal_status_failed') }}</span></div>
                                @endif
                            </div>
                            <x-ui.icon name="chevron-down" class="mt-1 hidden h-5 w-5 text-slate-400 transition group-open:rotate-180 lg:block" />
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-slate-500 lg:hidden">
                            <span>{{ $formatPaymentDate($payment) }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ $paymentMethodLabel }}</span>
                            <span class="{{ $paymentStatusClass }}">{{ __('app.'.$payment->status->value) }}</span>
                            @if ($fiscalActionRequired)
                                <span class="crm-status-danger">{{ __('app.fiscal_status_failed') }}</span>
                            @endif
                            <x-ui.icon name="chevron-down" class="ml-auto h-5 w-5 text-slate-400 transition group-open:rotate-180" />
                        </div>
                    </summary>

                    <div class="border-t border-stone-100 bg-slate-50/70 p-4">
                        <div class="grid gap-4 text-sm lg:grid-cols-3">
                            <div>
                                <h3 class="font-semibold text-slate-950">{{ __('app.payment_details') }}</h3>
                                <dl class="mt-2 space-y-2 text-slate-600">
                                    <div><dt class="inline font-semibold text-slate-700">{{ __('app.phone') }}:</dt> <dd class="inline">{{ $payment->customer?->phone ?? $payment->customer?->email ?? __('app.not_set') }}</dd></div>
                                    <div><dt class="inline font-semibold text-slate-700">{{ __('app.payment_provider') }}:</dt> <dd class="inline">{{ $currentProviderLabel }}</dd></div>
                                    <div><dt class="inline font-semibold text-slate-700">{{ __('app.payment_location') }}:</dt> <dd class="inline">{{ $payment->location?->name ?? __('app.not_set') }}</dd></div>
                                    <div><dt class="inline font-semibold text-slate-700">{{ __('app.schedule_kind') }}:</dt> <dd class="inline">{{ __('app.'.$payment->schedule_kind) }}</dd></div>
                                </dl>
                            </div>
                            <div>
                                <h3 class="font-semibold text-slate-950">{{ __('app.payment_technical_details') }}</h3>
                                <dl class="mt-2 space-y-2 break-words text-slate-600">
                                    <div><dt class="inline font-semibold text-slate-700">ID:</dt> <dd class="inline">{{ $payment->order_id }}</dd></div>
                                    @if ($payment->customerClassPass)
                                        <div><dt class="inline font-semibold text-slate-700">{{ __('app.class_pass_code') }}:</dt> <dd class="inline">{{ $payment->customerClassPass->code }}</dd></div>
                                    @endif
                                    @if ($payment->classBooking?->scheduledClass)
                                        <div>
                                            <dt class="inline font-semibold text-slate-700">{{ __('app.booking') }}:</dt>
                                            <dd class="inline">
                                                {{ $payment->classBooking->scheduledClass->starts_at->copy()->timezone($payment->classBooking->scheduledClass->displayTimezone())->format('Y-m-d H:i') }}
                                                @if ($payment->classBooking->scheduledClass->room)
                                                    · {{ $payment->classBooking->scheduledClass->room->name }}
                                                @endif
                                            </dd>
                                        </div>
                                    @endif
                                </dl>
                            </div>
                            <div>
                                <h3 class="font-semibold text-slate-950">{{ __('app.payment_status') }}</h3>
                                <div class="mt-2 space-y-2 text-sm text-slate-600">
                                    <span class="{{ $paymentStatusClass }}">{{ __('app.'.$payment->status->value) }}</span>
                                    @if ($showFiscalStatus)
                                        <div>
                                            <span class="{{ $fiscalStatusClass }}">{{ $receipt?->status ? __('app.fiscal_status_'.$receipt->status->value) : __('app.fiscal_status_pending') }}</span>
                                            @if ($receipt?->fiscal_number)
                                                <div class="mt-2 font-semibold text-slate-700">{{ __('app.fiscal_receipt_number') }}: {{ $receipt->fiscal_number }}</div>
                                            @endif
                                            @if ($receipt?->last_error)
                                                <div class="mt-2 text-rose-700">{{ $receipt->last_error }}</div>
                                                <div class="mt-1 text-rose-700">{{ __('app.fiscalization_contact_checkbox') }}</div>
                                            @endif
                                        </div>
                                    @elseif ($payment->isManualCashStudioPayment())
                                        <p class="leading-5">{{ __('app.manual_cash_not_fiscalized') }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>

                        @if ($canManageStudioCashflow && $payment->canBeCorrectedAsStudioCash())
                            <details class="mt-4">
                                <summary class="crm-focus inline-flex min-h-11 cursor-pointer items-center rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-brand-700 transition hover:border-brand-100 hover:bg-brand-50">
                                    {{ __('app.edit_payment') }}
                                </summary>
                                <form method="POST" action="{{ route('dashboard.accounts.payments.corrections.store', [$account, $payment]) }}" class="mt-3 grid gap-3 rounded-lg border border-stone-200 bg-white p-3 sm:grid-cols-2">
                                    @csrf
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.amount') }}</span>
                                        <input name="amount" type="number" min="0.01" step="0.01" inputmode="decimal" value="{{ $formatMoneyInput($payment->amount_cents) }}" class="crm-field min-h-11" required>
                                    </label>
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.location') }}</span>
                                        <select name="location_id" class="crm-field min-h-11" required>
                                            @foreach ($locations as $location)
                                                <option value="{{ $location->id }}" @selected($payment->location_id === $location->id)>{{ $location->name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.paid_at') }}</span>
                                        <input name="paid_at" type="datetime-local" value="{{ $formatDateTimeLocal($payment->paid_at ?? $payment->started_at) }}" class="crm-field min-h-11" required>
                                    </label>
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.reason') }}</span>
                                        <textarea name="reason" rows="3" class="crm-field" required placeholder="{{ __('app.payment_correction_reason_placeholder') }}"></textarea>
                                    </label>
                                    <div class="sm:col-span-2"><x-ui.button type="submit" size="sm" class="min-h-11">{{ __('app.save_correction') }}</x-ui.button></div>
                                </form>
                            </details>
                        @elseif ($payment->isManualCashStudioPayment())
                            <p class="mt-4 rounded-lg border border-stone-200 bg-white p-3 text-xs leading-5 text-slate-500">{{ __('app.payment_correction_not_allowed_short') }}</p>
                        @endif

                        @if ($payment->corrections->isNotEmpty())
                            <details class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3">
                                <summary class="crm-focus min-h-11 cursor-pointer font-semibold text-amber-900">{{ __('app.payment_correction_history') }}</summary>
                                <div class="mt-3 space-y-3">
                                    @foreach ($payment->corrections->sortByDesc('created_at') as $correction)
                                        <div class="rounded-md bg-white/80 p-3 text-xs leading-5 text-amber-950">
                                            <div class="font-semibold">{{ $formatDateTime($correction->created_at) }} · {{ $correction->actor_name ?? __('app.system') }}</div>
                                            <div>{{ __('app.amount') }}: {{ $formatMoney($correction->previous_amount_cents, $payment->currency) }} → {{ $formatMoney($correction->new_amount_cents, $payment->currency) }}</div>
                                            <div>{{ __('app.location') }}: {{ $correction->previousLocation?->name ?? __('app.not_set') }} → {{ $correction->newLocation?->name ?? __('app.not_set') }}</div>
                                            <div>{{ __('app.paid_at') }}: {{ $formatDateTime($correction->previous_paid_at) }} → {{ $formatDateTime($correction->new_paid_at) }}</div>
                                            <div>{{ __('app.reason') }}: {{ $correction->reason }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                </details>
            @empty
                <p class="p-4 text-sm text-slate-500">{{ __('app.no_payment_history') }}</p>
            @endforelse
        </x-ui.panel>

        @if ($payments->hasPages())
            <div class="mt-4">{{ $payments->links() }}</div>
        @endif
    </section>

    <section class="mt-8" data-payments-section="cash">
        <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.cash_overview') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('app.cash_overview_copy') }}</p>
            </div>
            @if ($canManageStudioCashflow)
                <div class="grid gap-2 sm:flex sm:flex-wrap">
                    @foreach ([\App\Models\StudioCashEntry::DirectionIn => __('app.deposit_cash_action'), \App\Models\StudioCashEntry::DirectionOut => __('app.record_collection_action')] as $direction => $label)
                        <details class="relative" @if (old('direction') === $direction && $errors->any()) open @endif>
                            <summary class="crm-focus inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-xs transition hover:border-brand-200 hover:text-brand-700">
                                <x-ui.icon :name="$direction === \App\Models\StudioCashEntry::DirectionIn ? 'plus' : 'minus'" class="h-4 w-4" />
                                {{ $label }}
                            </summary>
                            <form method="POST" action="{{ route('dashboard.accounts.cash-entries.store', $account) }}" class="absolute left-0 z-30 mt-2 w-[min(22rem,calc(100vw-2rem))] space-y-3 rounded-xl border border-stone-200 bg-white p-4 shadow-xl sm:left-auto sm:right-0">
                                @csrf
                                <input type="hidden" name="direction" value="{{ $direction }}">
                                <label class="block">
                                    <span class="crm-label">{{ __('app.location') }}</span>
                                    <select name="location_id" class="crm-field min-h-11" required>
                                        @foreach ($locations as $location)
                                            <option value="{{ $location->id }}" @selected((int) $defaultCashLocationId === $location->id)>{{ $location->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.amount') }}</span>
                                    <input name="amount" type="number" min="0.01" step="0.01" inputmode="decimal" class="crm-field min-h-11" placeholder="0.00" required>
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.occurred_at') }}</span>
                                    <input name="occurred_at" type="datetime-local" value="{{ old('occurred_at', $formatDateTimeLocal(now())) }}" class="crm-field min-h-11" required>
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.reason') }}</span>
                                    <textarea name="reason" rows="3" class="crm-field" required placeholder="{{ __('app.cash_entry_reason_placeholder') }}"></textarea>
                                </label>
                                <x-ui.button type="submit" class="min-h-11">{{ __('app.save') }}</x-ui.button>
                            </form>
                        </details>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <x-ui.metric :label="__('app.period_cash_received')" :value="$formatMoneyTotals($periodOverview['cash_received_by_currency'])" :meta="__('app.selected_period')" icon="plus" accent="emerald" mobile-inline />
            <x-ui.metric :label="__('app.period_cash_collected')" :value="$formatMoneyTotals($periodOverview['collection_by_currency'])" :meta="__('app.selected_period')" icon="minus" accent="violet" mobile-inline />
            <x-ui.metric :label="__('app.cash_now')" :value="$formatMoneyTotals($stats['cash_balance_by_currency'])" :meta="__('app.cash_now_copy')" icon="wallet" accent="amber" mobile-inline />
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <x-ui.panel>
                <h3 class="text-base font-semibold text-slate-950">{{ __('app.cashdesk_current_lifetime_balance') }}</h3>
                <p class="mt-1 text-sm text-slate-500">{{ __('app.cashdesk_balance_not_period_filtered') }}</p>
                <div class="mt-4 space-y-3">
                    @forelse ($cashBalances as $cashBalance)
                        <div class="rounded-lg border border-stone-200 bg-slate-50 p-3 text-sm" data-cash-balance-location="{{ $cashBalance['location']->id }}" data-cash-balance-cents="{{ $cashBalance['balance_by_currency'][$account->default_currency] ?? 0 }}" data-cash-balances='@json($cashBalance['balance_by_currency'])'>
                            <div class="flex items-center justify-between gap-3">
                                <div class="font-semibold text-slate-950">{{ $cashBalance['location']->name }}</div>
                                <div class="font-semibold text-slate-950">{{ $formatMoneyTotals($cashBalance['balance_by_currency']) }}</div>
                            </div>
                            <div class="mt-2 grid gap-2 text-xs font-semibold text-slate-500 sm:grid-cols-3">
                                <span>{{ __('app.manual_cash_payments') }}: {{ $formatMoneyTotals($cashBalance['manual_cash_by_currency']) }}</span>
                                <span>{{ __('app.cash_in') }}: {{ $formatMoneyTotals($cashBalance['cash_in_by_currency']) }}</span>
                                <span>{{ __('app.cash_out') }}: {{ $formatMoneyTotals($cashBalance['cash_out_by_currency']) }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('app.no_cashdesk_locations') }}</p>
                    @endforelse
                </div>
            </x-ui.panel>

            <x-ui.panel>
                <h3 class="text-base font-semibold text-slate-950">{{ __('app.period_cash_entries') }}</h3>
                <div class="mt-4 space-y-3">
                    @forelse ($cashEntries as $cashEntry)
                        <div class="rounded-lg border border-stone-200 bg-slate-50 p-3 text-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <span @class([
                                        'crm-status-active' => $cashEntry->direction === \App\Models\StudioCashEntry::DirectionIn,
                                        'crm-status-danger' => $cashEntry->direction === \App\Models\StudioCashEntry::DirectionOut,
                                    ])>{{ __('app.'.$cashEntry->direction) }}</span>
                                    <div class="mt-2 text-xs font-semibold text-slate-500">{{ __('app.cash_purpose_'.$cashEntry->purpose) }} · {{ $cashEntry->location?->name ?? __('app.not_set') }}</div>
                                </div>
                                <div class="font-semibold text-slate-950">{{ $formatMoney($cashEntry->amount_cents, $cashEntry->currency) }}</div>
                            </div>
                            <div class="mt-2 text-xs leading-5 text-slate-500">
                                <div>{{ $formatDateTime($cashEntry->occurred_at) }}</div>
                                <div>{{ $cashEntry->reason }}</div>
                                @if ($cashEntry->actor_name)
                                    <div>{{ __('app.changed_by') }}: {{ $cashEntry->actor_name }}</div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('app.no_cash_entries') }}</p>
                    @endforelse
                </div>
            </x-ui.panel>
        </div>
    </section>

    <section class="mt-8" data-payments-section="expenses">
        <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.operational_expenses') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('app.operational_expenses_copy') }}</p>
            </div>
            @if ($canManageStudioCashflow)
                <details class="relative" @if (old('payment_method') !== null && $errors->any()) open @endif>
                    <summary class="crm-focus inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-brand-700">
                        <x-ui.icon name="plus" class="h-4 w-4" />
                        {{ __('app.add_operational_expense') }}
                    </summary>
                    <form method="POST" action="{{ route('dashboard.accounts.expenses.store', $account) }}" class="absolute left-0 z-30 mt-2 w-[min(24rem,calc(100vw-2rem))] space-y-3 rounded-xl border border-stone-200 bg-white p-4 shadow-xl sm:left-auto sm:right-0">
                        @csrf
                        @if ($activeExpenseCategories->isEmpty())
                            <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{ __('app.create_expense_category_first') }}</p>
                        @else
                            <label class="block">
                                <span class="crm-label">{{ __('app.expense_category') }}</span>
                                <select name="expense_category_id" class="crm-field min-h-11" required>
                                    @foreach ($activeExpenseCategories as $expenseCategory)
                                        <option value="{{ $expenseCategory->id }}" @selected((int) $defaultExpenseCategoryId === $expenseCategory->id)>{{ $expenseCategory->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.amount') }}</span>
                                <input name="amount" type="number" min="0.01" max="999999.99" step="0.01" inputmode="decimal" value="{{ old('amount') }}" class="crm-field min-h-11" placeholder="0.00" required>
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.payment_method') }}</span>
                                <select name="payment_method" class="crm-field min-h-11" required>
                                    @foreach ($expensePaymentMethods as $paymentMethod)
                                        <option value="{{ $paymentMethod }}" @selected($defaultExpenseMethod === $paymentMethod)>{{ __('app.expense_payment_method_'.$paymentMethod) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.location') }}</span>
                                <select name="location_id" class="crm-field min-h-11">
                                    <option value="">{{ __('app.not_set') }}</option>
                                    @foreach ($locations as $location)
                                        <option value="{{ $location->id }}" @selected((int) $defaultExpenseLocationId === $location->id)>{{ $location->name }}</option>
                                    @endforeach
                                </select>
                                <span class="mt-1 block text-xs text-slate-500">{{ __('app.expense_cashdesk_location_hint') }}</span>
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.occurred_at') }}</span>
                                <input name="occurred_at" type="datetime-local" value="{{ old('occurred_at', $formatDateTimeLocal(now())) }}" class="crm-field min-h-11" required>
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.reason') }}</span>
                                <textarea name="reason" rows="3" class="crm-field" required placeholder="{{ __('app.expense_reason_placeholder') }}">{{ old('reason') }}</textarea>
                            </label>
                            <x-ui.button type="submit" class="min-h-11">{{ __('app.save_expense') }}</x-ui.button>
                        @endif
                    </form>
                </details>
            @endif
        </div>

        <form method="GET" action="{{ route('dashboard.accounts.payments.index', $account) }}" class="grid gap-3 rounded-xl border border-stone-200 bg-white p-4 shadow-crm sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
            <input type="hidden" name="date_from" value="{{ $filters['date_from'] }}">
            <input type="hidden" name="date_to" value="{{ $filters['date_to'] }}">
            @foreach ($paymentFilterValues as $filterName => $filterValue)
                <input type="hidden" name="{{ $filterName }}" value="{{ $filterValue }}">
            @endforeach

            <label class="block min-w-0">
                <span class="crm-label">{{ __('app.expense_category') }}</span>
                <select name="expense_category_id" class="crm-field min-h-11">
                    <option value="">{{ __('app.all_expense_categories') }}</option>
                    @foreach ($expenseCategories as $expenseCategory)
                        <option value="{{ $expenseCategory->id }}" @selected($filters['expense_category_id'] === $expenseCategory->id)>{{ $expenseCategory->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block min-w-0">
                <span class="crm-label">{{ __('app.expense_status') }}</span>
                <select name="expense_status" class="crm-field min-h-11">
                    <option value="">{{ __('app.all_statuses') }}</option>
                    @foreach ($expenseStatuses as $expenseStatus)
                        <option value="{{ $expenseStatus }}" @selected($filters['expense_status'] === $expenseStatus)>{{ __('app.expense_status_'.$expenseStatus) }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex min-h-11 flex-wrap items-center gap-2">
                <x-ui.button type="submit" variant="secondary" class="min-h-11">{{ __('app.apply_filters') }}</x-ui.button>
                <a href="{{ route('dashboard.accounts.payments.index', $expenseResetParameters) }}" class="crm-focus inline-flex min-h-11 items-center justify-center rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-brand-200 hover:text-brand-700">{{ __('app.reset_filters') }}</a>
            </div>
        </form>

        @if ($expenses->isEmpty())
            <x-ui.panel class="mt-4">
                <p class="text-sm font-medium text-slate-600">{{ $isToday && empty($expenseFilterValues) ? __('app.no_operational_expenses_today') : __('app.no_operational_expenses') }}</p>
            </x-ui.panel>
        @else
            <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(20rem,0.7fr)]">
                <x-ui.panel padding="none" class="overflow-hidden">
                    <div class="border-b border-stone-100 p-4">
                        <h3 class="text-base font-semibold text-slate-950">{{ __('app.operational_expense_history') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ __('app.operational_expense_history_copy') }}</p>
                    </div>
                    @foreach ($expenses as $expense)
                        <article class="crm-row lg:grid-cols-[minmax(0,1.2fr)_140px_160px_minmax(0,1fr)] lg:items-center" data-studio-expense-id="{{ $expense->id }}" data-expense-status="{{ $expense->status() }}">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-slate-950">{{ $expense->category->name }}</span>
                                    <span @class([
                                        'crm-status-active' => ! $expense->isVoided(),
                                        'crm-status-danger' => $expense->isVoided(),
                                    ])>{{ __('app.expense_status_'.$expense->status()) }}</span>
                                </div>
                                <div class="mt-1 text-sm text-slate-500">{{ $expense->reason }}</div>
                                @if ($expense->isVoided())
                                    <div class="mt-2 rounded-lg border border-rose-200 bg-rose-50 p-2 text-xs leading-5 text-rose-800">
                                        <div>{{ __('app.void_reason') }}: {{ $expense->void_reason }}</div>
                                        <div>{{ $formatDateTime($expense->voided_at) }} · {{ $expense->voided_by_actor_name ?? __('app.system') }}</div>
                                    </div>
                                @endif
                            </div>
                            <div>
                                <div class="font-semibold text-slate-950">{{ $formatMoney($expense->amount_cents, $expense->currency) }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ __('app.expense_payment_method_'.$expense->payment_method) }}</div>
                            </div>
                            <div class="text-sm text-slate-500">
                                <div>{{ $formatDateTime($expense->occurred_at) }}</div>
                                <div class="mt-1">{{ $expense->location?->name ?? __('app.not_set') }}</div>
                                @if ($expense->actor_name)
                                    <div class="mt-1">{{ __('app.changed_by') }}: {{ $expense->actor_name }}</div>
                                @endif
                            </div>
                            <div>
                                @if (! $expense->isVoided())
                                    <details>
                                        <summary class="crm-focus inline-flex min-h-11 cursor-pointer items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-100">{{ __('app.void_expense') }}</summary>
                                        <form method="POST" action="{{ route('dashboard.accounts.expenses.void', [$account, $expense]) }}" class="mt-3 space-y-3 rounded-lg border border-rose-200 bg-rose-50 p-3">
                                            @csrf
                                            @method('PATCH')
                                            <label class="block">
                                                <span class="crm-label">{{ __('app.void_reason') }}</span>
                                                <textarea name="reason" rows="3" class="crm-field" required minlength="3" placeholder="{{ __('app.expense_void_reason_placeholder') }}"></textarea>
                                            </label>
                                            <x-ui.button type="submit" size="sm" class="min-h-11">{{ __('app.confirm_void_expense') }}</x-ui.button>
                                        </form>
                                    </details>
                                @else
                                    <span class="text-sm font-semibold text-slate-500">{{ __('app.expense_kept_for_audit') }}</span>
                                @endif
                            </div>
                        </article>
                    @endforeach
                    @if ($expenses->hasPages())
                        <div class="border-t border-stone-100 p-4">{{ $expenses->links() }}</div>
                    @endif
                </x-ui.panel>

                <div class="space-y-4">
                    @if ($expenseCategoryBreakdown->isNotEmpty())
                        <x-ui.panel>
                            <h3 class="text-base font-semibold text-slate-950">{{ __('app.expense_by_category') }}</h3>
                            <div class="mt-4 space-y-3">
                                @foreach ($expenseCategoryBreakdown as $categoryTotal)
                                    <div>
                                        <div class="flex items-center justify-between gap-3 text-sm">
                                            <span class="font-semibold text-slate-700">{{ $categoryTotal['category']->name }}</span>
                                            <span class="font-semibold text-slate-950">{{ $formatMoney($categoryTotal['amount_cents'], $categoryTotal['currency']) }} · {{ number_format($categoryTotal['share'], 1) }}%</span>
                                        </div>
                                        <progress class="mt-2 h-2 w-full overflow-hidden rounded-full accent-brand-500" value="{{ min(100, $categoryTotal['share']) }}" max="100"></progress>
                                    </div>
                                @endforeach
                            </div>
                        </x-ui.panel>
                    @endif
                </div>
            </div>
        @endif

        <x-ui.panel class="mt-4">
            <details @if ($errors->has('name')) open @endif>
                <summary class="crm-focus min-h-11 cursor-pointer text-base font-semibold text-slate-950">{{ __('app.manage_expense_categories') }}</summary>
                <form method="POST" action="{{ route('dashboard.accounts.expense-categories.store', $account) }}" class="mt-4 flex gap-2">
                    @csrf
                    <label class="min-w-0 flex-1">
                        <span class="sr-only">{{ __('app.expense_category_name') }}</span>
                        <input name="name" type="text" maxlength="255" class="crm-field min-h-11" placeholder="{{ __('app.expense_category_name') }}" required>
                    </label>
                    <x-ui.button type="submit" size="sm" class="min-h-11">{{ __('app.save') }}</x-ui.button>
                </form>
                <div class="mt-4 space-y-3">
                    @forelse ($expenseCategories as $expenseCategory)
                        <div class="rounded-lg border border-stone-200 bg-slate-50 p-3">
                            <form method="POST" action="{{ route('dashboard.accounts.expense-categories.update', [$account, $expenseCategory]) }}" class="flex flex-col gap-2 sm:flex-row">
                                @csrf
                                @method('PATCH')
                                <input name="name" type="text" maxlength="255" value="{{ $expenseCategory->name }}" class="crm-field min-h-11" required>
                                <x-ui.button type="submit" size="sm" variant="secondary" class="min-h-11">{{ __('app.rename') }}</x-ui.button>
                            </form>
                            <form method="POST" action="{{ route('dashboard.accounts.expense-categories.status', [$account, $expenseCategory]) }}" class="mt-2">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="is_active" value="{{ $expenseCategory->is_active ? 0 : 1 }}">
                                <button type="submit" class="crm-focus min-h-11 text-xs font-semibold text-brand-700 hover:text-brand-900">
                                    {{ $expenseCategory->is_active ? __('app.deactivate') : __('app.reactivate') }}
                                </button>
                            </form>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('app.no_expense_categories') }}</p>
                    @endforelse
                </div>
            </details>
        </x-ui.panel>
    </section>
@endsection
