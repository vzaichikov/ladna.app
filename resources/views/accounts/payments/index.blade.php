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
        $defaultCashLocationId = old('location_id', $locationId ?: $locations->first()?->id);
        $defaultExpenseLocationId = old('location_id', $locationId ?: $locations->first()?->id);
        $defaultExpenseCategoryId = old('expense_category_id', $activeExpenseCategories->first()?->id);
        $defaultExpenseMethod = old('payment_method', \App\Models\StudioExpense::PaymentMethodCashdesk);
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.payments') }}</h1>
            <p class="crm-page-copy">{{ __('app.account_payments_copy') }}</p>
        </div>
        @if ($canManageStudioCashflow)
            <div class="flex flex-wrap gap-2">
                <details class="relative" @if ($errors->hasAny(['expense_category_id', 'payment_method', 'amount', 'occurred_at', 'location_id', 'reason'])) open @endif>
                    <summary class="inline-flex cursor-pointer items-center justify-center gap-2 rounded-md bg-brand-600 px-3 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-brand-700">
                        <x-ui.icon name="plus" class="h-4 w-4" />
                        {{ __('app.add_operational_expense') }}
                    </summary>
                    <form method="POST" action="{{ route('dashboard.accounts.expenses.store', $account) }}" class="absolute right-0 z-30 mt-2 w-[min(24rem,calc(100vw-2rem))] space-y-3 rounded-xl border border-stone-200 bg-white p-4 shadow-xl">
                        @csrf
                        @if ($activeExpenseCategories->isEmpty())
                            <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{ __('app.create_expense_category_first') }}</p>
                        @else
                            <label class="block">
                                <span class="crm-label">{{ __('app.expense_category') }}</span>
                                <select name="expense_category_id" class="crm-field" required>
                                    @foreach ($activeExpenseCategories as $expenseCategory)
                                        <option value="{{ $expenseCategory->id }}" @selected((int) $defaultExpenseCategoryId === $expenseCategory->id)>{{ $expenseCategory->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.amount') }}</span>
                                <input name="amount" type="number" min="0.01" max="999999.99" step="0.01" inputmode="decimal" value="{{ old('amount') }}" class="crm-field" placeholder="0.00" required>
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.payment_method') }}</span>
                                <select name="payment_method" class="crm-field" required>
                                    @foreach ($expensePaymentMethods as $paymentMethod)
                                        <option value="{{ $paymentMethod }}" @selected($defaultExpenseMethod === $paymentMethod)>{{ __('app.expense_payment_method_'.$paymentMethod) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.location') }}</span>
                                <select name="location_id" class="crm-field">
                                    <option value="">{{ __('app.not_set') }}</option>
                                    @foreach ($locations as $location)
                                        <option value="{{ $location->id }}" @selected((int) $defaultExpenseLocationId === $location->id)>{{ $location->name }}</option>
                                    @endforeach
                                </select>
                                <span class="mt-1 block text-xs text-slate-500">{{ __('app.expense_cashdesk_location_hint') }}</span>
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.occurred_at') }}</span>
                                <input name="occurred_at" type="datetime-local" value="{{ old('occurred_at', $formatDateTimeLocal(now())) }}" class="crm-field" required>
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.reason') }}</span>
                                <textarea name="reason" rows="3" class="crm-field" required placeholder="{{ __('app.expense_reason_placeholder') }}">{{ old('reason') }}</textarea>
                            </label>
                            <x-ui.button type="submit" class="w-fit">{{ __('app.save_expense') }}</x-ui.button>
                        @endif
                    </form>
                </details>
                @foreach ([\App\Models\StudioCashEntry::DirectionIn => __('app.cash_purpose_deposit'), \App\Models\StudioCashEntry::DirectionOut => __('app.cash_purpose_owner_withdrawal')] as $direction => $label)
                    <details class="relative">
                        <summary class="inline-flex cursor-pointer items-center justify-center gap-2 rounded-md border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-xs transition hover:border-brand-200 hover:text-brand-700">
                            <x-ui.icon :name="$direction === \App\Models\StudioCashEntry::DirectionIn ? 'plus' : 'minus'" class="h-4 w-4" />
                            {{ $label }}
                        </summary>
                        <form method="POST" action="{{ route('dashboard.accounts.cash-entries.store', $account) }}" class="absolute right-0 z-20 mt-2 w-80 space-y-3 rounded-xl border border-stone-200 bg-white p-4 shadow-xl">
                            @csrf
                            <input type="hidden" name="direction" value="{{ $direction }}">
                            <label class="block">
                                <span class="crm-label">{{ __('app.location') }}</span>
                                <select name="location_id" class="crm-field" required>
                                    @foreach ($locations as $location)
                                        <option value="{{ $location->id }}" @selected((int) $defaultCashLocationId === $location->id)>{{ $location->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.amount') }}</span>
                                <input name="amount" type="number" min="0.01" step="0.01" inputmode="decimal" class="crm-field" placeholder="0.00" required>
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.occurred_at') }}</span>
                                <input name="occurred_at" type="datetime-local" value="{{ old('occurred_at', $formatDateTimeLocal(now())) }}" class="crm-field" required>
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.reason') }}</span>
                                <textarea name="reason" rows="3" class="crm-field" required placeholder="{{ __('app.cash_entry_reason_placeholder') }}"></textarea>
                            </label>
                            <x-ui.button type="submit" class="w-fit">{{ __('app.save') }}</x-ui.button>
                        </form>
                    </details>
                @endforeach
            </div>
        @endif
    </div>

    <form method="GET" action="{{ route('dashboard.accounts.payments.index', $account) }}" class="mt-6 grid gap-4 rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:grid-cols-2 xl:grid-cols-4">
        <label class="block">
            <span class="crm-label">{{ __('app.date_from') }}</span>
            <input name="date_from" type="date" value="{{ $filters['date_from'] }}" class="crm-field" required>
            @error('date_from') <span class="crm-help">{{ $message }}</span> @enderror
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.date_to') }}</span>
            <input name="date_to" type="date" value="{{ $filters['date_to'] }}" class="crm-field" required>
            @error('date_to') <span class="crm-help">{{ $message }}</span> @enderror
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.payment_location') }}</span>
            <select name="location_id" class="crm-field">
                <option value="">{{ __('app.all_locations') }}</option>
                @foreach ($locations as $location)
                    <option value="{{ $location->id }}" @selected((int) $locationId === $location->id)>{{ $location->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.payment_status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all_statuses') }}</option>
                @foreach ($statuses as $statusOption)
                    <option value="{{ $statusOption->value }}" @selected($status === $statusOption->value)>{{ __('app.'.$statusOption->value) }}</option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.payment_provider') }}</span>
            <select name="provider" class="crm-field">
                <option value="">{{ __('app.all_payment_providers') }}</option>
                @foreach ($providers as $providerKey => $providerOptionLabel)
                    <option value="{{ $providerKey }}" @selected($provider === $providerKey)>{{ $providerOptionLabel }}</option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.expense_category') }}</span>
            <select name="expense_category_id" class="crm-field">
                <option value="">{{ __('app.all_expense_categories') }}</option>
                @foreach ($expenseCategories as $expenseCategory)
                    <option value="{{ $expenseCategory->id }}" @selected($filters['expense_category_id'] === $expenseCategory->id)>{{ $expenseCategory->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.payment_method') }}</span>
            <select name="expense_payment_method" class="crm-field">
                <option value="">{{ __('app.all_payment_methods') }}</option>
                @foreach ($expensePaymentMethods as $paymentMethod)
                    <option value="{{ $paymentMethod }}" @selected($filters['expense_payment_method'] === $paymentMethod)>{{ __('app.expense_payment_method_'.$paymentMethod) }}</option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.expense_status') }}</span>
            <select name="expense_status" class="crm-field">
                <option value="">{{ __('app.all_statuses') }}</option>
                @foreach ($expenseStatuses as $expenseStatus)
                    <option value="{{ $expenseStatus }}" @selected($filters['expense_status'] === $expenseStatus)>{{ __('app.expense_status_'.$expenseStatus) }}</option>
                @endforeach
            </select>
        </label>

        <div class="flex flex-wrap items-end gap-2 sm:col-span-2 xl:col-span-4">
            <x-ui.button type="submit" variant="secondary">
                <x-ui.icon name="search" class="h-4 w-4" />
                {{ __('app.apply_filters') }}
            </x-ui.button>
            <a href="{{ route('dashboard.accounts.payments.index', $account) }}" class="inline-flex items-center justify-center rounded-md border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-brand-200 hover:text-brand-700">
                {{ __('app.reset_filters') }}
            </a>
        </div>
    </form>

    <section class="mt-6">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.period_overview') }}</h2>
            <p class="text-sm text-slate-500">{{ $filters['date_from'] }} – {{ $filters['date_to'] }}</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.metric :label="__('app.period_paid_income')" :value="$formatMoneyTotals($periodOverview['income_by_currency'])" icon="payments" accent="emerald" />
            <x-ui.metric :label="__('app.period_operational_expenses')" :value="$formatMoneyTotals($periodOverview['expense_by_currency'])" icon="minus" accent="violet" />
            <x-ui.metric :label="__('app.period_income_minus_expenses')" :value="$formatMoneyTotals($periodOverview['net_by_currency'])" icon="wallet" accent="brand" />
            <x-ui.metric :label="__('app.period_owner_withdrawals')" :value="$formatMoneyTotals($periodOverview['owner_withdrawal_by_currency'])" icon="banknote" accent="amber" />
        </div>
    </section>

    <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-6">
        <x-ui.metric :label="__('app.payments_total')" :value="$stats['total']" icon="payments" accent="slate" />
        <x-ui.metric :label="__('app.payment_paid')" :value="$formatMoneyTotals($stats['paid_amounts_by_currency'])" icon="check-circle" accent="emerald" />
        <x-ui.metric :label="__('app.payment_pending')" :value="$stats['pending']" icon="schedule" accent="brand" />
        <x-ui.metric :label="__('app.payment_failed')" :value="$stats['failed']" icon="bell" accent="violet" />
        <x-ui.metric :label="__('app.cashdesk_current_balance')" :value="$formatMoneyTotals($stats['cash_balance_by_currency'])" icon="wallet" accent="amber" />
        @if ($fiscalizationEnabled)
            <x-ui.metric :label="__('app.fiscalization_failed')" :value="$stats['fiscal_failed']" icon="settings" accent="slate" />
        @endif
    </section>

    <section class="mt-6 grid gap-4 lg:grid-cols-2">
        <x-ui.panel>
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.cashdesk_current_lifetime_balance') }}</h2>
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
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.period_cash_entries') }}</h2>
            <div class="mt-4 space-y-3">
                @forelse ($cashEntries as $cashEntry)
                    <div class="rounded-lg border border-stone-200 bg-slate-50 p-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <span @class([
                                    'crm-status-active' => $cashEntry->direction === \App\Models\StudioCashEntry::DirectionIn,
                                    'crm-status-danger' => $cashEntry->direction === \App\Models\StudioCashEntry::DirectionOut,
                                ])>{{ __('app.'.$cashEntry->direction) }}</span>
                                <span class="ml-2 text-xs font-semibold text-slate-500">{{ __('app.cash_purpose_'.$cashEntry->purpose) }}</span>
                                <span class="ml-2 font-semibold text-slate-950">{{ $cashEntry->location?->name ?? __('app.not_set') }}</span>
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
    </section>

    <section class="mt-6 grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(20rem,0.7fr)]">
        <x-ui.panel padding="none" class="overflow-hidden">
            <div class="border-b border-stone-100 p-5">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.operational_expense_history') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('app.operational_expense_history_copy') }}</p>
            </div>

            @forelse ($expenses as $expense)
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
                                <summary class="inline-flex cursor-pointer items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-100">
                                    {{ __('app.void_expense') }}
                                </summary>
                                <form method="POST" action="{{ route('dashboard.accounts.expenses.void', [$account, $expense]) }}" class="mt-3 space-y-3 rounded-lg border border-rose-200 bg-rose-50 p-3">
                                    @csrf
                                    @method('PATCH')
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.void_reason') }}</span>
                                        <textarea name="reason" rows="3" class="crm-field" required minlength="3" placeholder="{{ __('app.expense_void_reason_placeholder') }}"></textarea>
                                    </label>
                                    <x-ui.button type="submit" size="sm" class="w-fit">{{ __('app.confirm_void_expense') }}</x-ui.button>
                                </form>
                            </details>
                        @else
                            <span class="text-sm font-semibold text-slate-500">{{ __('app.expense_kept_for_audit') }}</span>
                        @endif
                    </div>
                </article>
            @empty
                <x-ui.empty-state :title="__('app.no_operational_expenses')" icon="wallet" class="m-5" />
            @endforelse

            @if ($expenses->hasPages())
                <div class="border-t border-stone-100 p-5">
                    {{ $expenses->links() }}
                </div>
            @endif
        </x-ui.panel>

        <div class="space-y-4">
            <x-ui.panel>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.expense_by_category') }}</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($expenseCategoryBreakdown as $categoryTotal)
                        <div>
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="font-semibold text-slate-700">{{ $categoryTotal['category']->name }}</span>
                                <span class="font-semibold text-slate-950">{{ $formatMoney($categoryTotal['amount_cents'], $categoryTotal['currency']) }} · {{ number_format($categoryTotal['share'], 1) }}%</span>
                            </div>
                            <progress class="mt-2 h-2 w-full overflow-hidden rounded-full accent-brand-500" value="{{ min(100, $categoryTotal['share']) }}" max="100"></progress>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('app.no_expense_breakdown') }}</p>
                    @endforelse
                </div>
            </x-ui.panel>

            <x-ui.panel>
                <details @if ($errors->has('name')) open @endif>
                    <summary class="cursor-pointer text-lg font-semibold text-slate-950">{{ __('app.manage_expense_categories') }}</summary>
                    <form method="POST" action="{{ route('dashboard.accounts.expense-categories.store', $account) }}" class="mt-4 flex gap-2">
                        @csrf
                        <label class="min-w-0 flex-1">
                            <span class="sr-only">{{ __('app.expense_category_name') }}</span>
                            <input name="name" type="text" maxlength="255" class="crm-field" placeholder="{{ __('app.expense_category_name') }}" required>
                        </label>
                        <x-ui.button type="submit" size="sm">{{ __('app.save') }}</x-ui.button>
                    </form>
                    <div class="mt-4 space-y-3">
                        @forelse ($expenseCategories as $expenseCategory)
                            <div class="rounded-lg border border-stone-200 bg-slate-50 p-3">
                                <form method="POST" action="{{ route('dashboard.accounts.expense-categories.update', [$account, $expenseCategory]) }}" class="flex gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <input name="name" type="text" maxlength="255" value="{{ $expenseCategory->name }}" class="crm-field" required>
                                    <x-ui.button type="submit" size="sm" variant="secondary">{{ __('app.rename') }}</x-ui.button>
                                </form>
                                <form method="POST" action="{{ route('dashboard.accounts.expense-categories.status', [$account, $expenseCategory]) }}" class="mt-2">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $expenseCategory->is_active ? 0 : 1 }}">
                                    <button type="submit" class="text-xs font-semibold text-brand-700 hover:text-brand-900">
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
        </div>
    </section>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="border-b border-stone-100 p-5">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.payment_history') }}</h2>
        </div>

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
                $showFiscalStatus = $fiscalizationEnabled && ! $payment->isManualCashStudioPayment();
            @endphp

            <article class="crm-row lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_130px_140px_160px_minmax(0,1fr)] lg:items-center">
                <div class="min-w-0">
                    <div class="font-semibold text-slate-950">{{ $payment->plan_name }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $payment->order_id }}</div>
                    <div class="mt-1 text-xs font-medium text-slate-500">{{ __('app.schedule_kind') }}: {{ __('app.'.$payment->schedule_kind) }}</div>
                </div>

                <div class="min-w-0 text-sm">
                    <div class="font-semibold text-slate-950">{{ $payment->customer?->name ?? __('app.not_set') }}</div>
                    <div class="mt-1 text-slate-500">{{ $payment->customer?->phone ?? $payment->customer?->email ?? __('app.not_set') }}</div>
                    @if ($payment->customerClassPass)
                        <div class="mt-1 text-xs font-semibold text-slate-500">{{ __('app.class_pass_code') }}: {{ $payment->customerClassPass->code }}</div>
                    @endif
                    @if ($payment->classBooking?->scheduledClass)
                        <div class="mt-1 text-xs font-semibold text-slate-500">
                            {{ __('app.booking') }}: {{ $payment->classBooking->scheduledClass->starts_at->copy()->timezone($payment->classBooking->scheduledClass->displayTimezone())->format('Y-m-d H:i') }}
                            @if ($payment->classBooking->scheduledClass->room)
                                · {{ $payment->classBooking->scheduledClass->room->name }}
                            @endif
                        </div>
                    @endif
                </div>

                <div class="text-sm font-semibold text-slate-700">
                    {{ $formatMoney($payment->amount_cents, $payment->currency) }}
                    @if ($payment->corrections->isNotEmpty())
                        <div class="mt-2 inline-flex rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">
                            {{ __('app.corrected_payment') }}
                        </div>
                    @endif
                </div>

                <div class="text-sm text-slate-500">
                    <div>{{ $currentProviderLabel }}</div>
                    <div class="mt-1">{{ __('app.payment_location') }}: {{ $payment->location?->name ?? __('app.not_set') }}</div>
                    <div class="mt-1">{{ $formatPaymentDate($payment) }}</div>
                </div>

                <div class="space-y-2">
                    <span class="{{ $paymentStatusClass }}">{{ __('app.'.$payment->status->value) }}</span>

                    @if ($showFiscalStatus)
                        <div class="text-xs leading-5 text-slate-500">
                            <span class="{{ $fiscalStatusClass }}">{{ $receipt?->status ? __('app.fiscal_status_'.$receipt->status->value) : __('app.fiscal_status_pending') }}</span>
                            @if ($receipt?->fiscal_number)
                                <div class="mt-1 font-semibold text-slate-700">{{ __('app.fiscal_receipt_number') }}: {{ $receipt->fiscal_number }}</div>
                            @endif
                            @if ($receipt?->last_error)
                                <div class="mt-1 text-rose-700">{{ $receipt->last_error }}</div>
                                <div class="mt-1 text-rose-700">{{ __('app.fiscalization_contact_checkbox') }}</div>
                            @endif
                        </div>
                    @elseif ($payment->isManualCashStudioPayment())
                        <div class="text-xs leading-5 text-slate-500">{{ __('app.manual_cash_not_fiscalized') }}</div>
                    @endif
                </div>

                <div class="space-y-2 text-sm">
                    @if ($canManageStudioCashflow && $payment->canBeCorrectedAsStudioCash())
                        <details>
                            <summary class="inline-flex w-fit cursor-pointer items-center justify-center rounded-lg border border-stone-200 bg-slate-50 px-3 py-2 font-semibold text-brand-700 transition hover:border-brand-100 hover:bg-brand-50">
                                {{ __('app.edit_payment') }}
                            </summary>
                            <form method="POST" action="{{ route('dashboard.accounts.payments.corrections.store', [$account, $payment]) }}" class="mt-3 space-y-3 rounded-lg border border-stone-200 bg-slate-50 p-3">
                                @csrf
                                <label class="block">
                                    <span class="crm-label">{{ __('app.amount') }}</span>
                                    <input name="amount" type="number" min="0.01" step="0.01" inputmode="decimal" value="{{ $formatMoneyInput($payment->amount_cents) }}" class="crm-field" required>
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.location') }}</span>
                                    <select name="location_id" class="crm-field" required>
                                        @foreach ($locations as $location)
                                            <option value="{{ $location->id }}" @selected($payment->location_id === $location->id)>{{ $location->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.paid_at') }}</span>
                                    <input name="paid_at" type="datetime-local" value="{{ $formatDateTimeLocal($payment->paid_at ?? $payment->started_at) }}" class="crm-field" required>
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.reason') }}</span>
                                    <textarea name="reason" rows="3" class="crm-field" required placeholder="{{ __('app.payment_correction_reason_placeholder') }}"></textarea>
                                </label>
                                <x-ui.button type="submit" size="sm" class="w-fit">{{ __('app.save_correction') }}</x-ui.button>
                            </form>
                        </details>
                    @elseif ($payment->isManualCashStudioPayment())
                        <div class="rounded-lg border border-stone-200 bg-slate-50 p-3 text-xs leading-5 text-slate-500">{{ __('app.payment_correction_not_allowed_short') }}</div>
                    @endif

                    @if ($payment->corrections->isNotEmpty())
                        <details class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                            <summary class="cursor-pointer font-semibold text-amber-900">{{ __('app.payment_correction_history') }}</summary>
                            <div class="mt-3 space-y-3">
                                @foreach ($payment->corrections->sortByDesc('created_at') as $correction)
                                    <div class="rounded-md bg-white/80 p-3 text-xs leading-5 text-amber-950">
                                        <div class="font-semibold">{{ $formatDateTime($correction->created_at) }} · {{ $correction->actor_name ?? __('app.system') }}</div>
                                        <div>{{ __('app.amount') }}: {{ $formatMoney($correction->previous_amount_cents, $payment->currency) }} -> {{ $formatMoney($correction->new_amount_cents, $payment->currency) }}</div>
                                        <div>{{ __('app.location') }}: {{ $correction->previousLocation?->name ?? __('app.not_set') }} -> {{ $correction->newLocation?->name ?? __('app.not_set') }}</div>
                                        <div>{{ __('app.paid_at') }}: {{ $formatDateTime($correction->previous_paid_at) }} -> {{ $formatDateTime($correction->new_paid_at) }}</div>
                                        <div>{{ __('app.reason') }}: {{ $correction->reason }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>
            </article>
        @empty
            <x-ui.empty-state :title="__('app.no_payment_history')" icon="payments" class="m-5" />
        @endforelse
    </x-ui.panel>

    @if ($payments->hasPages())
        <div class="mt-6">
            {{ $payments->links() }}
        </div>
    @endif
@endsection
