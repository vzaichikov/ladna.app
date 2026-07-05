@extends('layouts.app')

@section('title', __('app.edit').' '.$customerClassPass->code)

@section('content')
    @php
        $formatDateTimeLocal = static fn ($date): ?string => \App\Support\DateTimePresenter::dateTimeLocal($date, $account);
        $formatDateTime = static fn ($date): string => \App\Support\DateTimePresenter::format($date, $account) ?? __('app.not_set');
        $formatMoney = static fn (?int $priceCents, ?string $currency = null): string => \App\Support\MoneyFormatter::format($priceCents ?? 0, $currency ?: $account->default_currency);
        $formatScheduledClassDateTime = static function ($scheduledClass) use ($account): string {
            if (! $scheduledClass) {
                return __('app.not_set');
            }

            return \App\Support\DateTimePresenter::formatInTimezone($scheduledClass->starts_at, $scheduledClass->displayTimezone() ?? $account->timezone ?? config('app.timezone')) ?? __('app.not_set');
        };
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
        $formatStatus = static fn (?string $status): string => $status ? __('app.'.$status) : __('app.not_set');
        $statusOptions = collect(\App\Enums\CustomerClassPassStatus::cases())
            ->filter(fn ($status): bool => $customerClassPass->status === \App\Enums\CustomerClassPassStatus::Freezed
                ? $status === \App\Enums\CustomerClassPassStatus::Freezed
                : $status !== \App\Enums\CustomerClassPassStatus::Freezed);
        $locations ??= collect();
        $paymentStatus = $customerClassPass->paymentStatus();
        $paymentStatusClass = match ($paymentStatus) {
            'paid' => 'crm-status-active',
            'partial' => 'crm-status-warning',
            default => 'crm-status-danger',
        };
        $manualCashPayments = $customerClassPass->purchases
            ->where('payment_source', \App\Models\CustomerPurchase::SourceManualCashClassPass)
            ->sortByDesc(fn ($payment) => $payment->paid_at?->timestamp ?? $payment->created_at?->timestamp ?? 0);
        $classPassHistoryEntries ??= collect();
        $canManageClients = auth()->user()?->can('manageClients', $account) ?? false;
        $historyEventClass = static fn (string $type): string => match ($type) {
            'payment', 'reservation_used', 'opened' => 'crm-status-active',
            'adjustment', 'reservation_reserved' => 'crm-status-scheduled',
            'closed', 'reservation_released' => 'crm-status-muted',
            default => 'crm-status-warning',
        };
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ $customerClassPass->code }}</h1>
            <p class="crm-page-copy">
                @if ($customerClassPass->customer && $canManageClients)
                    <a href="{{ route('dashboard.accounts.customers.edit', [$account, $customerClassPass->customer]) }}" class="font-semibold text-brand-700 hover:text-brand-800">{{ $customerClassPass->customer->name }}</a>
                @else
                    {{ $customerClassPass->customer?->name ?? __('app.not_set') }}
                @endif
                · {{ $customerClassPass->plan_name }}
            </p>
        </div>
        <div class="flex flex-wrap gap-3">
            @if ($customerClassPass->status === \App\Enums\CustomerClassPassStatus::Active && $customerClassPass->is_active)
                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.customer-class-passes.freeze', [$account, $customerClassPass]) }}"
                    data-confirm-action
                    data-confirm-title="{{ __('app.confirm_freeze_class_pass_title') }}"
                    data-confirm-body="{{ __('app.confirm_freeze_class_pass_body') }}"
                    data-confirm-accept="{{ __('app.freeze_class_pass') }}"
                    data-confirm-icon="snowflake"
                    data-confirm-variant="danger"
                >
                    @csrf
                    <x-ui.button type="submit" variant="danger">
                        <x-ui.icon name="snowflake" class="h-4 w-4" />
                        {{ __('app.freeze_class_pass') }}
                    </x-ui.button>
                </form>
            @elseif ($customerClassPass->status === \App\Enums\CustomerClassPassStatus::Freezed && $customerClassPass->is_active)
                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.customer-class-passes.unfreeze', [$account, $customerClassPass]) }}"
                    data-confirm-action
                    data-confirm-title="{{ __('app.confirm_unfreeze_class_pass_title') }}"
                    data-confirm-body="{{ __('app.confirm_unfreeze_class_pass_body') }}"
                    data-confirm-accept="{{ __('app.unfreeze_class_pass') }}"
                    data-confirm-icon="sun"
                    data-confirm-variant="success"
                >
                    @csrf
                    <x-ui.button type="submit" variant="success">
                        <x-ui.icon name="sun" class="h-4 w-4" />
                        {{ __('app.unfreeze_class_pass') }}
                    </x-ui.button>
                </form>
            @endif
            <x-ui.button :href="route('dashboard.accounts.customer-class-passes.index', $account)" variant="secondary">{{ __('app.customer_class_passes') }}</x-ui.button>
        </div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)] xl:items-start">
        <div class="order-2 space-y-6">
            <x-ui.panel padding="none" class="overflow-hidden">
                <div class="border-b border-stone-100 px-5 py-4">
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.class_pass_full_history') }}</h2>
                </div>
                @forelse ($classPassHistoryEntries as $entry)
                    @php
                        $entryType = $entry['type'];
                        $entrySource = $entry['source'];
                    @endphp
                    <div class="border-b border-stone-100 px-5 py-4 text-sm last:border-b-0">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <span class="{{ $historyEventClass($entryType) }}">{{ __('app.class_pass_history_event_'.$entryType) }}</span>
                            <div class="text-xs font-medium text-slate-500 sm:text-right">{{ $formatDateTime($entry['occurred_at']) }}</div>
                        </div>

                        @if ($entryType === 'issued')
                            <div class="mt-3 font-semibold text-slate-950">{{ $entrySource->plan_name }}</div>
                            <div class="mt-1 text-slate-500">
                                {{ __('app.sessions_count') }}: {{ $entrySource->sessions_count }} ·
                                {{ __('app.class_pass_price') }}: {{ $formatMoney($entrySource->price_cents, $entrySource->currency) }}
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                                {{ __('app.issued_location') }}: {{ $entrySource->issuedLocation?->name ?? __('app.not_set') }} ·
                                {{ __('app.issued_by') }}: {{ $entrySource->issued_by_actor_name ?? __('app.system') }}
                            </div>
                        @elseif ($entryType === 'opened')
                            <div class="mt-3 font-semibold text-slate-950">{{ __('app.opened_at') }}</div>
                            <div class="mt-1 text-slate-500">{{ __('app.status') }}: {{ __('app.'.$entrySource->status->value) }}</div>
                        @elseif ($entryType === 'closed')
                            <div class="mt-3 font-semibold text-slate-950">{{ __('app.closed_at') }}</div>
                            <div class="mt-1 text-slate-500">{{ __('app.status') }}: {{ __('app.'.$entrySource->status->value) }}</div>
                        @elseif ($entryType === 'payment')
                            @php
                                $providerLabel = $entrySource->provider === \App\Models\CustomerPurchase::ProviderStudioCash
                                    ? __('app.provider_studio_cash')
                                    : \Illuminate\Support\Str::headline((string) $entrySource->provider);
                            @endphp
                            <div class="mt-3 font-semibold text-slate-950">{{ $formatMoney($entrySource->amount_cents, $entrySource->currency) }}</div>
                            <div class="mt-1 text-slate-500">
                                {{ $providerLabel }} · {{ __('app.'.$entrySource->status->value) }}
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                                {{ __('app.payment_location') }}: {{ $entrySource->location?->name ?? __('app.not_set') }}
                                @if ($entrySource->order_id)
                                    · {{ $entrySource->order_id }}
                                @endif
                            </div>
                        @elseif (in_array($entryType, ['reservation_reserved', 'reservation_used', 'reservation_released'], true))
                            @php
                                $reservation = $entrySource;
                                $booking = $reservation->classBooking;
                                $scheduledClass = $booking?->scheduledClass ?? $reservation->scheduledClass;
                                $classTitle = $scheduledClass?->displayTitle() ?? $scheduledClass?->title ?? __('app.booking');
                                $classTypeName = $scheduledClass?->classType?->name;
                            @endphp
                            <div class="mt-3 font-semibold text-slate-950">{{ $classTitle }}</div>
                            <div class="mt-1 text-slate-500">
                                {{ $formatScheduledClassDateTime($scheduledClass) }}
                                @if ($classTypeName)
                                    · {{ $classTypeName }}
                                @endif
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                                @if ($booking)
                                    {{ __('app.booking') }}: {{ __('app.'.$booking->status->value) }} ·
                                @endif
                                {{ __('app.status') }}: {{ __('app.'.$reservation->status->value) }}
                            </div>
                        @elseif ($entryType === 'adjustment')
                            @php
                                $adjustment = $entrySource;
                                $adjustmentType = $adjustment->adjustment_type;
                                $adjustmentTypeLabel = __('app.adjustment_'.$adjustmentType->value);
                                $adjustmentTypeClass = match ($adjustmentType) {
                                    \App\Enums\CustomerClassPassAdjustmentType::Sessions => 'crm-status-active',
                                    \App\Enums\CustomerClassPassAdjustmentType::ValidityDays => 'crm-status-scheduled',
                                    \App\Enums\CustomerClassPassAdjustmentType::Freeze => 'crm-status-warning',
                                    \App\Enums\CustomerClassPassAdjustmentType::Unfreeze => 'crm-status-active',
                                };
                                $sessionsDelta = $adjustment->sessions_delta;
                                $daysDelta = $adjustment->days_delta;
                                $sessionsDeltaLabel = $sessionsDelta !== null ? (($sessionsDelta > 0 ? '+' : '').$sessionsDelta.' '.__('app.classes_count')) : null;
                                $daysDeltaLabel = $daysDelta !== null ? (($daysDelta > 0 ? '+' : '').$daysDelta.' '.__('app.days')) : null;
                                $primaryLabel = match ($adjustmentType) {
                                    \App\Enums\CustomerClassPassAdjustmentType::Sessions => $sessionsDeltaLabel,
                                    \App\Enums\CustomerClassPassAdjustmentType::ValidityDays,
                                    \App\Enums\CustomerClassPassAdjustmentType::Unfreeze => $daysDeltaLabel,
                                    \App\Enums\CustomerClassPassAdjustmentType::Freeze => __('app.freeze_class_pass'),
                                };
                            @endphp
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <span class="{{ $adjustmentTypeClass }}">{{ $adjustmentTypeLabel }}</span>
                                @if ($primaryLabel)
                                    <span class="font-semibold text-slate-950">{{ $primaryLabel }}</span>
                                @endif
                            </div>
                            <div class="mt-2 space-y-1 text-slate-500">
                                @if ($adjustment->previous_sessions_count !== null || $adjustment->new_sessions_count !== null)
                                    <div>{{ __('app.sessions_count') }}: {{ $adjustment->previous_sessions_count ?? __('app.not_set') }} -> {{ $adjustment->new_sessions_count ?? __('app.not_set') }}</div>
                                @endif
                                @if ($adjustment->previous_validity_days !== null || $adjustment->new_validity_days !== null)
                                    <div>{{ __('app.validity_days_delta') }}: {{ $adjustment->previous_validity_days ?? __('app.not_set') }} -> {{ $adjustment->new_validity_days ?? __('app.not_set') }}</div>
                                @endif
                                @if ($adjustment->previous_status || $adjustment->new_status)
                                    <div>{{ __('app.status') }}: {{ __('app.status_change', ['from' => $formatStatus($adjustment->previous_status), 'to' => $formatStatus($adjustment->new_status)]) }}</div>
                                @endif
                                @if ($adjustment->freeze_started_at || $adjustment->freeze_finished_at)
                                    <div>{{ __('app.freeze_period') }}: {{ $formatDateTime($adjustment->freeze_started_at) }} -> {{ $formatDateTime($adjustment->freeze_finished_at) }}</div>
                                @endif
                                @if ($adjustment->freeze_days_count !== null)
                                    <div>{{ __('app.freeze_days_count', ['count' => $adjustment->freeze_days_count]) }}</div>
                                @endif
                            </div>
                            <div class="mt-2 text-slate-600">{{ $adjustment->reason }}</div>
                            <div class="mt-1 text-xs text-slate-500">{{ __('app.adjusted_by') }}: {{ $adjustment->actor_name ?? $adjustment->user?->name ?? __('app.system') }}</div>
                        @endif
                    </div>
                @empty
                    <x-ui.empty-state :title="__('app.no_class_pass_history_entries')" icon="class-pass-plans" class="m-5" />
                @endforelse
            </x-ui.panel>
        </div>

        <div class="order-1 space-y-6">
            <form method="POST" action="{{ route('dashboard.accounts.customer-class-passes.update', [$account, $customerClassPass]) }}" class="space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @method('PUT')

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="crm-label">{{ __('app.status') }}</span>
                <select name="status" class="crm-field">
                    @foreach ($statusOptions as $status)
                        <option value="{{ $status->value }}" @selected(old('status', $customerClassPass->status->value) === $status->value)>{{ __('app.'.$status->value) }}</option>
                    @endforeach
                </select>
                @error('status') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            @if ($customerClassPass->status === \App\Enums\CustomerClassPassStatus::Freezed)
                <label class="mt-7 flex items-center gap-3 text-sm font-medium text-slate-700">
                    <input type="hidden" name="is_active" value="1">
                    <input type="checkbox" value="1" checked disabled class="crm-checkbox">
                    {{ __('app.active') }}
                </label>
            @else
                <label class="mt-7 flex items-center gap-3 text-sm font-medium text-slate-700">
                    <input type="hidden" name="is_active" value="0">
                    <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $customerClassPass->is_active)) class="crm-checkbox">
                    {{ __('app.active') }}
                </label>
            @endif
            <label class="block">
                <span class="crm-label">{{ __('app.issued_location') }}</span>
                <select name="issued_location_id" class="crm-field" required>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected((string) old('issued_location_id', $customerClassPass->issued_location_id) === (string) $location->id)>{{ $location->name }}</option>
                    @endforeach
                </select>
                @error('issued_location_id') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="crm-label">{{ __('app.purchased_at') }}</span>
                <input name="purchased_at" type="datetime-local" value="{{ old('purchased_at', $formatDateTimeLocal($customerClassPass->purchased_at)) }}" class="crm-field" required>
                @error('purchased_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.opened_at') }}</span>
                <input name="opened_at" type="datetime-local" value="{{ old('opened_at', $formatDateTimeLocal($customerClassPass->opened_at)) }}" class="crm-field">
                @error('opened_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.expires_after_first_class') }}</span>
                <input name="expires_at" type="datetime-local" value="{{ old('expires_at', $formatDateTimeLocal($customerClassPass->expires_at)) }}" class="crm-field">
                @error('expires_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.usable_until_at') }}</span>
                <input type="datetime-local" value="{{ $formatDateTimeLocal($customerClassPass->usableUntilAt()) }}" class="crm-field" disabled>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.closed_at') }}</span>
                <input name="closed_at" type="datetime-local" value="{{ old('closed_at', $formatDateTimeLocal($customerClassPass->closed_at)) }}" class="crm-field">
                @error('closed_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.frozen_at') }}</span>
                <input type="datetime-local" value="{{ $formatDateTimeLocal($customerClassPass->frozen_at) }}" class="crm-field" disabled>
            </label>
        </div>

        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
            {{ __('app.used_sessions') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->used_sessions_count }}</span> ·
            {{ __('app.reserved_sessions') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->reserved_sessions_count }}</span> ·
            {{ __('app.sessions_count') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->sessions_count }}</span>
            <div class="mt-2">
                <span class="{{ $paymentStatusClass }}">{{ __('app.class_pass_'.$paymentStatus) }}</span>
                <span @class([
                    'crm-status-active' => $customerClassPass->status === \App\Enums\CustomerClassPassStatus::Active,
                    'crm-status-warning' => $customerClassPass->status === \App\Enums\CustomerClassPassStatus::Freezed,
                    'crm-status-muted' => ! in_array($customerClassPass->status, [\App\Enums\CustomerClassPassStatus::Active, \App\Enums\CustomerClassPassStatus::Freezed], true),
                ])">{{ __('app.'.$customerClassPass->status->value) }}</span>
            </div>
            <div class="mt-2 text-xs text-slate-500">{{ __('app.issued_by') }}: {{ $customerClassPass->issued_by_actor_name ?? __('app.system') }}</div>
        </div>

        <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
    </form>

    <x-ui.panel>
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.class_pass_payment') }}</h2>
        <div class="mt-4 grid gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-sm">
                <div class="text-xs font-semibold uppercase text-slate-500">{{ __('app.class_pass_price') }}</div>
                <div class="mt-1 font-semibold text-slate-950">{{ $formatMoney($customerClassPass->price_cents, $customerClassPass->currency) }}</div>
            </div>
            <div class="rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-sm">
                <div class="text-xs font-semibold uppercase text-slate-500">{{ __('app.class_pass_paid_amount') }}</div>
                <div class="mt-1 font-semibold text-slate-950">{{ $formatMoney($customerClassPass->paidAmountCents(), $customerClassPass->currency) }}</div>
            </div>
            <div class="rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-sm">
                <div class="text-xs font-semibold uppercase text-slate-500">{{ __('app.class_pass_remaining_amount') }}</div>
                <div class="mt-1 font-semibold text-slate-950">{{ $formatMoney($customerClassPass->remainingPaymentCents(), $customerClassPass->currency) }}</div>
            </div>
            <div class="rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-sm">
                <div class="text-xs font-semibold uppercase text-slate-500">{{ __('app.class_pass_payment_status') }}</div>
                <div class="mt-1"><span class="{{ $paymentStatusClass }}">{{ __('app.class_pass_'.$paymentStatus) }}</span></div>
            </div>
        </div>

        @if ($customerClassPass->source === 'manual' && $customerClassPass->remainingPaymentCents() > 0)
            <form method="POST" action="{{ route('dashboard.accounts.customer-class-passes.payments.store', [$account, $customerClassPass]) }}" class="mt-5 grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                @csrf
                <label class="block">
                    <span class="crm-label">{{ __('app.payment_location') }}</span>
                    <select name="location_id" class="crm-field" required>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected((string) old('location_id', $customerClassPass->issued_location_id) === (string) $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                    @error('location_id') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.class_pass_payment_amount') }}</span>
                    <input name="amount" value="{{ old('amount', $formatMoneyInput($customerClassPass->remainingPaymentCents())) }}" inputmode="decimal" class="crm-field" required>
                    @error('amount') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <x-ui.button type="submit">
                    <x-ui.icon name="payments" class="h-4 w-4" />
                    {{ __('app.class_pass_record_payment') }}
                </x-ui.button>
            </form>
        @elseif ($customerClassPass->source !== 'manual')
            <div class="mt-5 rounded-lg border border-stone-200 bg-stone-50 px-4 py-3 text-sm text-slate-600">{{ __('app.class_pass_online_payment_locked') }}</div>
        @else
            <div class="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">{{ __('app.class_pass_fully_paid') }}</div>
        @endif

        @if ($manualCashPayments->isNotEmpty())
            <div class="mt-5 divide-y divide-stone-100 rounded-lg border border-stone-200">
                @foreach ($manualCashPayments as $payment)
                    <div class="grid gap-2 px-4 py-3 text-sm sm:grid-cols-[1fr_auto] sm:items-center">
                        <div>
                            <div class="font-semibold text-slate-950">{{ $formatMoney($payment->amount_cents, $payment->currency) }}</div>
                            <div class="mt-1 text-xs text-slate-500">{{ $payment->order_id }} · {{ $payment->location?->name ?? __('app.not_set') }}</div>
                        </div>
                        <div class="text-xs font-medium text-slate-500 sm:text-right">{{ $formatDateTime($payment->paid_at ?? $payment->created_at) }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.panel>

    <x-ui.panel>
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.class_pass_session_adjustment') }}</h2>
        <form
            method="POST"
            action="{{ route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $customerClassPass]) }}"
            class="mt-4 space-y-4"
            data-confirm-action
            data-confirm-title="{{ __('app.confirm_add_class_pass_sessions_title') }}"
            data-confirm-body="{{ __('app.confirm_add_class_pass_sessions_body') }}"
            data-confirm-accept="{{ __('app.add_sessions') }}"
            data-confirm-variant="success"
        >
            @csrf
            <label class="block">
                <span class="crm-label">{{ __('app.sessions_to_adjust') }}</span>
                <input name="sessions_delta" type="number" min="1" max="500" value="{{ old('sessions_delta', 1) }}" class="crm-field" required>
                @error('sessions_delta') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.adjustment_reason') }}</span>
                <textarea name="reason" rows="4" class="crm-field" required>{{ old('reason') }}</textarea>
                @error('reason') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            @error('direction') <span class="crm-help">{{ $message }}</span> @enderror
            <div class="flex flex-col gap-3 sm:flex-row">
                <x-ui.button
                    type="submit"
                    name="direction"
                    value="add"
                    variant="success"
                    data-confirm-title="{{ __('app.confirm_add_class_pass_sessions_title') }}"
                    data-confirm-body="{{ __('app.confirm_add_class_pass_sessions_body') }}"
                    data-confirm-accept="{{ __('app.add_sessions') }}"
                    data-confirm-icon="plus"
                    data-confirm-variant="success"
                >
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('app.add_sessions') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    name="direction"
                    value="subtract"
                    variant="danger"
                    data-confirm-title="{{ __('app.confirm_remove_class_pass_sessions_title') }}"
                    data-confirm-body="{{ __('app.confirm_remove_class_pass_sessions_body') }}"
                    data-confirm-accept="{{ __('app.remove_sessions') }}"
                    data-confirm-icon="minus"
                    data-confirm-variant="danger"
                >
                    <x-ui.icon name="minus" class="h-4 w-4" />
                    {{ __('app.remove_sessions') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.panel>

    <x-ui.panel>
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.class_pass_validity_days_adjustment') }}</h2>
        <form
            method="POST"
            action="{{ route('dashboard.accounts.customer-class-passes.validity-adjustments.store', [$account, $customerClassPass]) }}"
            class="mt-4 space-y-4"
            data-confirm-action
            data-confirm-title="{{ __('app.confirm_add_class_pass_days_title') }}"
            data-confirm-body="{{ __('app.confirm_add_class_pass_days_body') }}"
            data-confirm-accept="{{ __('app.add_days') }}"
            data-confirm-variant="success"
        >
            @csrf
            <label class="block">
                <span class="crm-label">{{ __('app.days_to_adjust') }}</span>
                <input name="days_delta" type="number" min="1" max="3650" value="{{ old('days_delta', 1) }}" class="crm-field" required>
                @error('days_delta') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.adjustment_reason') }}</span>
                <textarea name="reason" rows="4" class="crm-field" required>{{ old('reason') }}</textarea>
                @error('reason') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            @error('direction') <span class="crm-help">{{ $message }}</span> @enderror
            <div class="flex flex-col gap-3 sm:flex-row">
                <x-ui.button
                    type="submit"
                    name="direction"
                    value="add"
                    variant="success"
                    data-confirm-title="{{ __('app.confirm_add_class_pass_days_title') }}"
                    data-confirm-body="{{ __('app.confirm_add_class_pass_days_body') }}"
                    data-confirm-accept="{{ __('app.add_days') }}"
                    data-confirm-icon="plus"
                    data-confirm-variant="success"
                >
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('app.add_days') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    name="direction"
                    value="subtract"
                    variant="danger"
                    data-confirm-title="{{ __('app.confirm_remove_class_pass_days_title') }}"
                    data-confirm-body="{{ __('app.confirm_remove_class_pass_days_body') }}"
                    data-confirm-accept="{{ __('app.remove_days') }}"
                    data-confirm-icon="minus"
                    data-confirm-variant="danger"
                >
                    <x-ui.icon name="minus" class="h-4 w-4" />
                    {{ __('app.remove_days') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.panel>

        </div>
    </div>
@endsection
