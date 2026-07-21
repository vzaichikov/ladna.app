@extends('layouts.app')

@section('title', $account->name.' - '.__('app.platform'))

@section('content')
    @php
        $formatMoney = fn (?int $cents, ?string $currency): string => \App\Support\MoneyFormatter::format($cents, $currency ?: $account->default_currency);
        $formatPaymentDate = fn ($payment): string => \App\Support\DateTimePresenter::format($payment->paid_at ?? $payment->started_at, $account) ?? __('app.not_set');
    @endphp

    <x-ui.panel padding="lg">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-center gap-4">
                <span class="flex h-16 w-16 items-center justify-center rounded-xl bg-brand-50">
                    <img src="{{ $account->logoUrl() }}" alt="" class="max-h-11 max-w-11 object-contain">
                </span>
                <div>
                    <div class="crm-page-kicker">{{ __('app.platform') }}</div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="crm-page-title">{{ $account->name }}</h1>
                        @if ($account->isReadOnlyDemo())
                            <span class="crm-status-scheduled">{{ __('app.demo_account_badge') }}</span>
                        @endif
                    </div>
                    <p class="crm-page-copy">{{ $account->slug }} · {{ __('app.'.$account->status->value) }}</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                @unless ($account->isReadOnlyDemo())
                    <x-ui.button :href="route('platform.accounts.edit', $account)">
                        <x-ui.icon name="edit" class="h-4 w-4" />
                        {{ __('app.edit') }}
                    </x-ui.button>
                @endunless
                <x-ui.button :href="route('platform.accounts.customer-auth.edit', $account)" variant="secondary">
                    <x-ui.icon name="sliders-horizontal" class="h-4 w-4" />
                    {{ __('app.studio_capabilities_settings') }}
                </x-ui.button>
                <x-ui.button :href="route('dashboard.accounts.rooms.index', $account)" variant="secondary">
                    <x-ui.icon name="rooms" class="h-4 w-4" />
                    {{ __('app.rooms') }}
                </x-ui.button>
                @if ($account->allowsRtspCameras())
                    <x-ui.button :href="route('dashboard.accounts.service-rooms.index', $account)" variant="secondary">
                        <x-ui.icon name="video" class="h-4 w-4" />
                        {{ __('app.service_rooms') }}
                    </x-ui.button>
                @endif
                @unless ($account->isReadOnlyDemo())
                    <form method="POST" action="{{ route('platform.accounts.destroy', $account) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="danger">
                            <x-ui.icon name="trash" class="h-4 w-4" />
                            {{ __('app.delete') }}
                        </x-ui.button>
                    </form>
                @endunless
            </div>
        </div>
    </x-ui.panel>

    <section class="mt-6 grid gap-4 md:grid-cols-3">
        <x-ui.metric :label="__('app.locations')" :value="$account->locations->count()" icon="locations" />
        <x-ui.metric :label="__('app.generated_classes')" :value="$account->scheduled_classes_count" icon="generated-classes" accent="brand" />
        <x-ui.metric :label="__('app.subscription_plan')" :value="$account->subscription?->plan?->name ?? '-'" icon="payments" accent="emerald" />
    </section>

    @if ($account->subscription?->usesLocationBilling())
        <x-ui.panel padding="lg" class="mt-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="crm-page-kicker">{{ __('app.billing_v2') }}</div>
                    <h2 class="mt-1 text-lg font-semibold text-slate-950">{{ __('app.billing_v2_enrolled') }}</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        {{ __('app.billing_v2_account_summary', [
                            'status' => __('app.'.$account->subscription->status->value),
                            'locations' => $account->subscription->billable_location_count,
                            'trial_end' => $account->subscription->trial_ends_at?->timezone($account->timezone)->format('d.m.Y') ?? __('app.not_set'),
                            'plan' => $account->subscription->plan?->name ?? __('app.not_set'),
                        ]) }}
                    </p>
                </div>
                <span class="crm-status-active">{{ __('app.billing_v2_explicit_enrollment') }}</span>
            </div>

            @if ($account->subscription->pendingPriceVersion)
                <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                    <div class="font-semibold">{{ __('app.billing_tariff_change_pending') }}</div>
                    <p class="mt-1 leading-6">
                        {{ __('app.billing_tariff_change_pending_copy', [
                            'plan' => $account->subscription->pendingPriceVersion->plan?->name ?? __('app.not_set'),
                            'date' => $account->subscription->pending_tariff_change_at?->timezone($account->timezone)->format('d.m.Y') ?? __('app.not_set'),
                        ]) }}
                    </p>
                </div>
            @endif

            @if ($assignablePriceVersions->isNotEmpty())
                <form method="POST" action="{{ route('platform.accounts.billing.tariff.update', $account) }}" class="mt-5 grid gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                    @csrf
                    @method('PATCH')
                    <label class="block">
                        <span class="crm-label">{{ __('app.billing_tariff_assignment') }}</span>
                        <select name="subscription_price_version_id" required class="crm-field">
                            @foreach ($assignablePriceVersions as $priceVersion)
                                <option value="{{ $priceVersion->id }}" @selected((int) old('subscription_price_version_id', $account->subscription->pending_subscription_price_version_id ?? $account->subscription->subscription_price_version_id) === $priceVersion->id)>
                                    {{ $priceVersion->plan->name }} · {{ $priceVersion->plan->public_signup_enabled ? __('app.public_tariff') : __('app.private_tariff') }} · {{ __('app.price_version_number', ['version' => $priceVersion->version]) }} · {{ __('app.from_price_per_location', ['price' => $formatMoney($priceVersion->tiers->first()?->unit_price_cents, $priceVersion->currency)]) }}
                                </option>
                            @endforeach
                        </select>
                        @error('subscription_price_version_id') <span class="crm-help">{{ $message }}</span> @enderror
                        @error('tariff') <span class="crm-help">{{ $message }}</span> @enderror
                        <p class="crm-help">{{ __('app.billing_tariff_assignment_help') }}</p>
                    </label>
                    <x-ui.button type="submit">{{ __('app.change_billing_tariff') }}</x-ui.button>
                </form>
            @endif
        </x-ui.panel>
    @elseif ($assignablePriceVersions->isNotEmpty() && ! $account->isReadOnlyDemo())
        <x-ui.panel padding="lg" class="mt-6 border-amber-200 bg-amber-50/50">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="crm-page-kicker">{{ __('app.billing_v2') }}</div>
                    <h2 class="mt-1 text-lg font-semibold text-slate-950">{{ __('app.billing_v2_not_enrolled') }}</h2>
                    <p class="mt-2 max-w-2xl text-sm text-slate-600">
                        {{ __('app.billing_v2_enrollment_warning') }}
                    </p>
                </div>
                <form method="POST" action="{{ route('platform.accounts.billing.enroll', $account) }}" class="w-full max-w-xl" data-confirm-delete>
                    @csrf
                    <label class="block">
                        <span class="crm-label">{{ __('app.billing_tariff_assignment') }}</span>
                        <select name="subscription_price_version_id" required class="crm-field">
                            @foreach ($assignablePriceVersions as $priceVersion)
                                <option value="{{ $priceVersion->id }}" @selected((int) old('subscription_price_version_id', $assignablePriceVersions->first()->id) === $priceVersion->id)>
                                    {{ $priceVersion->plan->name }} · {{ $priceVersion->plan->public_signup_enabled ? __('app.public_tariff') : __('app.private_tariff') }} · {{ trans_choice('app.free_trial_days_count', $priceVersion->trial_days, ['count' => $priceVersion->trial_days]) }} · {{ __('app.from_price_per_location', ['price' => $formatMoney($priceVersion->tiers->first()?->unit_price_cents, $priceVersion->currency)]) }}
                                </option>
                            @endforeach
                        </select>
                        @error('subscription_price_version_id') <span class="crm-help">{{ $message }}</span> @enderror
                        @error('billing') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <x-ui.button type="submit" class="mt-3">{{ __('app.billing_v2_start_trial') }}</x-ui.button>
                </form>
            </div>
        </x-ui.panel>
    @endif

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="border-b border-stone-100 p-5">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.payment_history') }}</h2>
        </div>

        @forelse ($subscriptionPayments as $payment)
            @php
                $paymentStatusClass = match ($payment->status->value) {
                    'payment_paid' => 'crm-status-active',
                    'payment_pending', 'payment_started' => 'crm-status-scheduled',
                    'payment_failed', 'payment_cancelled', 'payment_expired' => 'crm-status-danger',
                    default => 'crm-status-muted',
                };
            @endphp
            <div class="crm-row lg:grid-cols-[1fr_140px_140px_150px_auto] lg:items-center">
                <div>
                    <div class="font-semibold text-slate-950">{{ $payment->plan?->name ?? __('app.not_set') }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $payment->order_id }}</div>
                </div>
                <div class="text-sm font-semibold text-slate-700">{{ $formatMoney($payment->amount_cents, $payment->currency) }}</div>
                <div class="text-sm text-slate-500">{{ __('app.'.$payment->payment_type->value) }}</div>
                <div class="text-sm text-slate-500">
                    {{ $formatPaymentDate($payment) }}
                </div>
                <span class="{{ $paymentStatusClass }}">{{ __('app.'.$payment->status->value) }}</span>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_subscription_payments')" icon="payments" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
