@extends('layouts.app')

@section('title', __('app.sms_account').' - '.$account->name)

@section('content')
    @php
        $timezone = $account->timezone ?: config('app.timezone');
        $mode = $account->customerAuthSetting?->sms_sending_mode ?? \App\Enums\SmsSendingMode::Disabled;
        $formatMoney = fn (?int $cents): string => \App\Support\MoneyFormatter::format($cents, 'UAH');
        $isLadnaMode = $mode === \App\Enums\SmsSendingMode::LadnaService;
        $isFree = $segmentPriceCents === 0;
        $canFund = ! $platformView && $serviceEnabled && $isLadnaMode && ($segmentPriceCents ?? 0) > 0 && ! $account->isReadOnlyDemo();
        $approximatelyAvailableSegments = ($segmentPriceCents ?? 0) > 0
            ? intdiv($wallet->spendableBalanceCents(), $segmentPriceCents)
            : null;
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="crm-page-kicker">{{ $account->name }}</div>
            <h1 class="crm-page-title">{{ __('app.sms_account') }}</h1>
            <p class="crm-page-copy">{{ __('app.sms_account_copy') }}</p>
        </div>
        @if ($platformView)
            <x-ui.button :href="route('platform.accounts.show', $account)" variant="secondary">{{ __('app.back') }}</x-ui.button>
        @else
            <x-ui.button :href="route('dashboard.accounts.integrations.index', [$account, 'tab' => 'messaging'])" variant="secondary">{{ __('app.sms_settings') }}</x-ui.button>
        @endif
    </div>

    @if (! $serviceEnabled)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ __('app.sms_service_temporarily_unavailable') }}
        </div>
    @elseif ($segmentPriceCents === null)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ __('app.sms_service_unavailable_for_tariff') }}
        </div>
    @elseif (! $isLadnaMode)
        <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
            {{ $mode === \App\Enums\SmsSendingMode::OwnGateway ? __('app.sms_credit_preserved_own_gateway') : __('app.sms_credit_preserved_disabled') }}
        </div>
    @endif

    @if ($wallet->outstanding_cents > 0)
        <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-900">
            {{ __('app.sms_outstanding_debt_warning', ['amount' => $formatMoney($wallet->outstanding_cents)]) }}
        </div>
    @elseif ($wallet->auto_top_up_suspended_at)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ __('app.sms_auto_top_up_suspended_warning') }}
        </div>
    @elseif ($isLadnaMode && ($segmentPriceCents ?? 0) > 0 && $wallet->spendableBalanceCents() < $segmentPriceCents)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ __('app.sms_low_credit_warning') }}
        </div>
    @endif

    <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-ui.metric :label="__('app.sms_current_credit')" :value="$formatMoney($wallet->balance_cents)" icon="payments" accent="emerald" />
        <x-ui.metric :label="__('app.sms_spendable_credit')" :value="$formatMoney($wallet->spendableBalanceCents())" icon="payments" accent="brand" />
        <x-ui.metric :label="__('app.sms_pending_reservations')" :value="$formatMoney($wallet->reserved_cents)" icon="scheduled-tasks" accent="amber" />
        <x-ui.metric
            :label="__('app.sms_segment_price')"
            :value="$segmentPriceCents === null ? __('app.not_available') : ($isFree ? __('app.free') : $formatMoney($segmentPriceCents))"
            icon="bell"
            accent="slate"
        />
        <x-ui.metric
            :label="__('app.sms_approximate_segments')"
            :value="$approximatelyAvailableSegments === null ? ($isFree ? '∞' : __('app.not_available')) : number_format($approximatelyAvailableSegments, 0, '.', ' ')"
            :meta="__('app.sms_monthly_usage', ['amount' => $formatMoney($monthlyUsageCents)])"
            icon="activity-log"
            accent="slate"
        />
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-ui.panel padding="lg">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.sms_top_up') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.sms_top_up_copy') }}</p>

            @if ($isFree)
                <p class="mt-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ __('app.sms_free_tariff_no_top_up') }}</p>
            @elseif ($canFund)
                <div class="mt-5 flex flex-wrap gap-3">
                    @foreach ($topUpPresetsCents as $presetCents)
                        <form method="POST" action="{{ route('dashboard.accounts.sms-account.top-ups.store', $account) }}">
                            @csrf
                            <input type="hidden" name="amount_cents" value="{{ $presetCents }}">
                            <x-ui.button type="submit">{{ $formatMoney($presetCents) }}</x-ui.button>
                        </form>
                    @endforeach
                </div>
                <p class="mt-4 text-xs leading-5 text-slate-500">{{ __('app.sms_credit_terms_copy') }}</p>
            @elseif (! $platformView)
                <p class="mt-5 text-sm text-slate-600">{{ __('app.sms_top_up_unavailable') }}</p>
            @endif

            <dl class="mt-5 space-y-3 text-sm">
                <div>
                    <dt class="text-slate-500">{{ __('app.saved_payment_method') }}</dt>
                    <dd class="mt-1 font-semibold text-slate-950">
                        {{ $paymentMethod?->isActive() ? trim(($paymentMethod->card_brand ? strtoupper($paymentMethod->card_brand).' ' : '').$paymentMethod->masked_pan) : __('app.not_set') }}
                    </dd>
                </div>
            </dl>
        </x-ui.panel>

        <x-ui.panel padding="lg">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.sms_auto_top_up') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.sms_auto_top_up_copy') }}</p>

            @if (! $platformView && $canFund)
                <form method="POST" action="{{ route('dashboard.accounts.sms-account.auto-top-up.update', $account) }}" class="mt-5 space-y-4">
                    @csrf
                    @method('PUT')
                    <label class="flex items-start gap-3 rounded-xl border border-stone-200 p-4">
                        <input type="hidden" name="auto_top_up_enabled" value="0">
                        <input type="checkbox" name="auto_top_up_enabled" value="1" class="mt-1" @checked(old('auto_top_up_enabled', $wallet->auto_top_up_enabled))>
                        <span>
                            <span class="block font-semibold text-slate-950">{{ __('app.sms_auto_top_up_enable') }}</span>
                            <span class="mt-1 block text-sm text-slate-500">{{ __('app.sms_auto_top_up_consent') }}</span>
                        </span>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <label class="block">
                            <span class="crm-label">{{ __('app.sms_auto_top_up_threshold') }}</span>
                            <input name="auto_top_up_threshold_uah" type="number" min="0.01" step="0.01" class="crm-field" value="{{ old('auto_top_up_threshold_uah', $wallet->auto_top_up_threshold_cents === null ? null : \App\Support\Payments\PaymentAmounts::centsToDecimalString($wallet->auto_top_up_threshold_cents)) }}">
                            @error('auto_top_up_threshold_uah') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="crm-label">{{ __('app.sms_auto_top_up_target') }}</span>
                            <input name="auto_top_up_target_uah" type="number" min="0.01" step="0.01" class="crm-field" value="{{ old('auto_top_up_target_uah', $wallet->auto_top_up_target_cents === null ? null : \App\Support\Payments\PaymentAmounts::centsToDecimalString($wallet->auto_top_up_target_cents)) }}">
                            @error('auto_top_up_target_uah') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="crm-label">{{ __('app.sms_auto_top_up_monthly_cap') }}</span>
                            <input name="auto_top_up_monthly_cap_uah" type="number" min="0.01" step="0.01" class="crm-field" value="{{ old('auto_top_up_monthly_cap_uah', $wallet->auto_top_up_monthly_cap_cents === null ? null : \App\Support\Payments\PaymentAmounts::centsToDecimalString($wallet->auto_top_up_monthly_cap_cents)) }}">
                            @error('auto_top_up_monthly_cap_uah') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                    </div>
                    <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
                </form>
            @else
                <p class="mt-5 text-sm text-slate-600">
                    {{ $wallet->auto_top_up_enabled ? __('app.enabled') : __('app.disabled') }}
                </p>
            @endif
        </x-ui.panel>
    </div>

    @if ($platformView)
        <x-ui.panel padding="lg" class="mt-6">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.sms_wallet_adjustment') }}</h2>
            <p class="mt-2 text-sm text-slate-500">{{ __('app.sms_wallet_adjustment_copy') }}</p>
            <form method="POST" action="{{ route('platform.accounts.sms-account.adjust', $account) }}" class="mt-5 grid gap-4 sm:grid-cols-[180px_minmax(0,1fr)_auto] sm:items-end">
                @csrf
                <label class="block">
                    <span class="crm-label">{{ __('app.amount_uah') }}</span>
                    <input name="amount_uah" type="number" step="0.01" class="crm-field" value="{{ old('amount_uah') }}">
                    @error('amount_uah') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.reason') }}</span>
                    <input name="reason" class="crm-field" value="{{ old('reason') }}" required>
                    @error('reason') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <x-ui.button type="submit">{{ __('app.apply') }}</x-ui.button>
            </form>
        </x-ui.panel>
    @endif

    <x-ui.panel padding="lg" class="mt-6">
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.sms_segments_explainer_title') }}</h2>
        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('app.sms_segments_explainer_copy') }}</p>
    </x-ui.panel>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="border-b border-stone-100 p-5">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.sms_wallet_ledger') }}</h2>
        </div>
        @forelse ($ledgerEntries as $entry)
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_140px_150px_170px] lg:items-center">
                <div>
                    <div class="font-semibold text-slate-950">{{ __('app.sms_ledger_'.$entry->type->value) }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $entry->reason ?: '—' }}</div>
                </div>
                <div class="font-semibold {{ $entry->amount_cents >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                    {{ $entry->amount_cents > 0 ? '+' : '' }}{{ $formatMoney($entry->amount_cents) }}
                </div>
                <div class="text-sm text-slate-600">{{ __('app.balance_after') }}: {{ $formatMoney($entry->balance_after_cents) }}</div>
                <div class="text-sm text-slate-500">{{ $entry->created_at?->timezone($timezone)->format('d.m.Y H:i') }}</div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.sms_no_ledger_entries')" icon="payments" class="m-5" />
        @endforelse
    </x-ui.panel>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="border-b border-stone-100 p-5">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.sms_top_up_history') }}</h2>
        </div>
        @forelse ($topUpPayments as $payment)
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_140px_160px_180px] lg:items-center">
                <div>
                    <div class="font-semibold text-slate-950">{{ __('app.sms_top_up_kind_'.$payment->kind->value) }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $payment->order_id }}</div>
                </div>
                <div class="font-semibold text-slate-700">{{ $formatMoney($payment->amount_cents) }}</div>
                <span class="{{ $payment->isPaid() ? 'crm-status-active' : 'crm-status-muted' }}">{{ __('app.'.$payment->status->value) }}</span>
                <div class="text-sm text-slate-500">{{ ($payment->paid_at ?? $payment->started_at)?->timezone($timezone)->format('d.m.Y H:i') }}</div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.sms_no_top_ups')" icon="payments" class="m-5" />
        @endforelse
    </x-ui.panel>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="border-b border-stone-100 p-5">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.sms_recent_deliveries') }}</h2>
        </div>
        @forelse ($deliveries as $delivery)
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_150px_120px_150px_170px] lg:items-center">
                <div>
                    <div class="font-semibold text-slate-950">{{ __('app.sms_purpose_'.$delivery->purpose->value) }} · {{ $delivery->recipient_phone }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $delivery->provider_message_id ?: '—' }}</div>
                </div>
                <span class="{{ $delivery->status === \App\Enums\SmsDeliveryStatus::Delivered ? 'crm-status-active' : 'crm-status-muted' }}">{{ __('app.sms_delivery_status_'.$delivery->status->value) }}</span>
                <div class="text-sm text-slate-600">{{ $delivery->billed_segments ?? $delivery->estimated_segments }} {{ __('app.sms_segments_short') }}</div>
                <div class="text-sm font-semibold text-slate-700">{{ $formatMoney($delivery->amount_cents) }}</div>
                <div class="text-sm text-slate-500">{{ $delivery->created_at?->timezone($timezone)->format('d.m.Y H:i') }}</div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.sms_no_deliveries')" icon="bell" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
