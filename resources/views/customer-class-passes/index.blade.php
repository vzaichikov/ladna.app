@extends('layouts.app')

@section('title', __('app.customer_class_passes').' - '.$account->name)

@section('content')
    @php
        $formatMoney = static function (?int $priceCents, string $currency = 'UAH'): string {
            if ($priceCents === null) {
                return '';
            }

            return \App\Support\MoneyFormatter::format($priceCents, $currency);
        };
        $formatDate = static fn ($date): string => \App\Support\DateTimePresenter::date($date, $account) ?? __('app.not_set');
        $unpaidActiveClassPassesCount ??= 0;
        $partialActiveClassPassesCount ??= 0;
        $paymentStatus ??= '';
        $unpaidFilterUrl = route('dashboard.accounts.customer-class-passes.index', array_merge(['account' => $account], request()->except('page'), [
            'state' => 'active',
            'payment_status' => 'unpaid',
        ]));
        $partialFilterUrl = route('dashboard.accounts.customer-class-passes.index', array_merge(['account' => $account], request()->except('page'), [
            'state' => 'active',
            'payment_status' => 'partial',
        ]));
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.customer_class_passes') }}</h1>
            <p class="crm-page-copy">{{ __('app.customer_class_passes_copy') }}</p>
        </div>
    </div>

    @if ($unpaidActiveClassPassesCount > 0 && ! ($state === 'active' && $paymentStatus === 'unpaid'))
        <div class="mt-6 flex flex-col gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 sm:flex-row sm:items-center sm:justify-between">
            <div class="font-medium">{{ __('app.unpaid_class_passes_notice', ['count' => $unpaidActiveClassPassesCount]) }}</div>
            <x-ui.button :href="$unpaidFilterUrl" variant="secondary" size="sm">
                {{ __('app.show_unpaid_class_passes') }}
            </x-ui.button>
        </div>
    @endif
    @if ($partialActiveClassPassesCount > 0 && ! ($state === 'active' && $paymentStatus === 'partial'))
        <div class="mt-4 flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between">
            <div class="font-medium">{{ __('app.partial_class_passes_notice', ['count' => $partialActiveClassPassesCount]) }}</div>
            <x-ui.button :href="$partialFilterUrl" variant="secondary" size="sm">
                {{ __('app.show_partial_class_passes') }}
            </x-ui.button>
        </div>
    @endif

    <form method="GET" action="{{ route('dashboard.accounts.customer-class-passes.index', $account) }}" class="mt-6 overflow-x-auto rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
        <div class="customer-pass-filter-grid">
            <label class="block">
                <span class="crm-label">{{ __('app.search') }}</span>
                <input name="q" value="{{ request('q') }}" class="crm-field" placeholder="{{ __('app.class_pass_search_placeholder') }}">
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.status') }}</span>
                <select name="state" class="crm-field">
                    <option value="active" @selected($state === 'active')>{{ __('app.active') }}</option>
                    <option value="freezed" @selected($state === 'freezed')>{{ __('app.freezed') }}</option>
                    <option value="inactive" @selected($state === 'inactive')>{{ __('app.inactive') }}</option>
                    <option value="all" @selected($state === 'all')>{{ __('app.all_statuses') }}</option>
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.class_pass_payment_status') }}</span>
                <select name="payment_status" class="crm-field">
                    <option value="" @selected($paymentStatus === '')>{{ __('app.all_payment_statuses') }}</option>
                    <option value="paid" @selected($paymentStatus === 'paid')>{{ __('app.class_pass_paid') }}</option>
                    <option value="partial" @selected($paymentStatus === 'partial')>{{ __('app.class_pass_partial') }}</option>
                    <option value="unpaid" @selected($paymentStatus === 'unpaid')>{{ __('app.class_pass_unpaid') }}</option>
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.schedule_kind') }}</span>
                <select name="schedule_kind" class="crm-field">
                    <option value="">{{ __('app.all_formats') }}</option>
                    @foreach ($enabledScheduleKinds as $kind)
                        <option value="{{ $kind }}" @selected($scheduleKind === $kind)>{{ __('app.'.$kind) }}</option>
                    @endforeach
                </select>
            </label>
            <x-ui.button type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.customer-class-passes.index', $account)" variant="secondary">{{ __('app.reset_filters') }}</x-ui.button>
        </div>
    </form>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($customerClassPasses as $customerClassPass)
            @php
                $statusClass = match ($customerClassPass->status) {
                    \App\Enums\CustomerClassPassStatus::Active => 'crm-status-active',
                    \App\Enums\CustomerClassPassStatus::Freezed => 'crm-status-warning',
                    default => 'crm-status-muted',
                };
                $currentPaymentStatus = $customerClassPass->paymentStatus();
                $paymentStatusClass = match ($currentPaymentStatus) {
                    'paid' => 'crm-status-active',
                    'partial' => 'crm-status-warning',
                    default => 'crm-status-danger',
                };
            @endphp
            <div class="crm-row xl:grid-cols-[1fr_1fr_0.8fr_0.9fr_0.9fr_auto] xl:items-center">
                <div>
                    <div class="font-semibold text-slate-950">{{ $customerClassPass->code }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $customerClassPass->plan_name }}</div>
                </div>
                <div>
                    <div class="font-semibold text-slate-950">{{ $customerClassPass->customer?->name }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $customerClassPass->customer?->phone ?? $customerClassPass->customer?->email ?? __('app.no_contact') }}</div>
                </div>
                <div class="text-sm text-slate-600">
                    <div>{{ $formatMoney($customerClassPass->price_cents, $customerClassPass->currency) }}</div>
                    @if ($customerClassPass->paidAmountCents() > 0 && ! $customerClassPass->is_paid)
                        <div class="mt-1">{{ __('app.class_pass_paid_amount') }}: {{ $formatMoney($customerClassPass->paidAmountCents(), $customerClassPass->currency) }}</div>
                        <div class="mt-1">{{ __('app.class_pass_remaining_amount') }}: {{ $formatMoney($customerClassPass->remainingPaymentCents(), $customerClassPass->currency) }}</div>
                    @endif
                    <div class="mt-1">{{ $customerClassPass->remainingSessionsCount() }} / {{ $customerClassPass->sessions_count }} {{ __('app.classes_count') }}</div>
                </div>
                <div class="text-sm text-slate-600">
                    <div>{{ __('app.purchased_at') }}: {{ $formatDate($customerClassPass->purchased_at) }}</div>
                    @if ($customerClassPass->issuedLocation)
                        <div class="mt-1">{{ __('app.issued_location') }}: {{ $customerClassPass->issuedLocation->name }}</div>
                    @endif
                    <div class="mt-1">{{ __('app.opened_at') }}: {{ $formatDate($customerClassPass->opened_at) }}</div>
                </div>
                <div class="text-sm text-slate-600">
                    <div>{{ __('app.expires_after_first_class') }}: {{ $formatDate($customerClassPass->expires_at) }}</div>
                    <div class="mt-1 whitespace-nowrap">{{ __('app.usable_until_at') }}: {{ $formatDate($customerClassPass->usableUntilAt()) }}</div>
                    @if ($customerClassPass->frozen_at)
                        <div class="mt-1">{{ __('app.frozen_at') }}: {{ $formatDate($customerClassPass->frozen_at) }}</div>
                    @endif
                    <div class="mt-1">{{ __('app.reserved_sessions') }}: {{ $customerClassPass->reserved_sessions_count }}</div>
                </div>
                <div class="flex flex-wrap gap-2 xl:justify-end">
                    <span class="{{ $paymentStatusClass }}">{{ __('app.class_pass_'.$currentPaymentStatus) }}</span>
                    <span class="{{ $statusClass }}">{{ __('app.'.$customerClassPass->status->value) }}</span>
                    <x-ui.action-button :href="route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass])" icon="edit" :label="__('app.edit')" />
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_customer_class_passes')" icon="class-pass-plans" class="m-5" />
        @endforelse
    </x-ui.panel>

    <div class="mt-6">
        {{ $customerClassPasses->links() }}
    </div>
@endsection
