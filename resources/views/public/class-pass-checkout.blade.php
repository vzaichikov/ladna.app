@extends('layouts.public')

@section('title', __('app.class_pass_checkout').' - '.$classPassPlan->name)

@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@push('head')
    @if ($stage === 'login' && $methods->google)
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@500&amp;display=swap">
    @endif

    @if ($stage === 'login' && $methods->otp && ! $otpPhone)
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
@endpush

@section('content')
    @php
        $formatMoney = static fn (?int $priceCents, string $currency = 'UAH'): string => $priceCents === null
            ? ''
            : \App\Support\MoneyFormatter::format($priceCents, $currency);
        $purchaseStatus = $purchase?->status;
        $purchaseStatusClass = match ($purchaseStatus) {
            \App\Enums\CustomerPurchaseStatus::PaymentPaid => 'border-emerald-200 bg-emerald-50 text-emerald-900',
            \App\Enums\CustomerPurchaseStatus::PaymentFailed,
            \App\Enums\CustomerPurchaseStatus::PaymentCancelled,
            \App\Enums\CustomerPurchaseStatus::PaymentExpired => 'border-rose-200 bg-rose-50 text-rose-900',
            default => 'border-amber-200 bg-amber-50 text-amber-900',
        };
    @endphp

    <main class="min-h-[calc(100vh-8rem)] bg-canvas px-5 py-8 text-slate-950">
        <section class="mx-auto max-w-5xl">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl border border-stone-200 bg-white shadow-xs">
                        <img src="{{ $account->logoUrl() }}" alt="" class="max-h-10 max-w-10 object-contain">
                    </span>
                    <div>
                        <div class="text-sm font-semibold uppercase text-brand-600">{{ $account->name }}</div>
                        <h1 class="text-2xl font-semibold text-slate-950">{{ __('app.class_pass_checkout') }}</h1>
                    </div>
                </div>
                <x-ui.button :href="route('public.price', [$account->slug, $location->slug])" variant="secondary">
                    <x-ui.icon name="arrow-left" class="h-4 w-4" />
                    {{ __('app.public_price') }}
                </x-ui.button>
            </div>

            @if (session('status'))
                <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-900">
                    {{ $errors->first() }}
                </div>
            @endif

            <section class="mt-6 grid gap-6 lg:grid-cols-[1fr_0.75fr] lg:items-start">
                <article class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="text-sm font-semibold uppercase text-brand-600">{{ __('app.class_pass_plan') }}</div>
                            <h2 class="mt-2 text-3xl font-semibold leading-tight text-slate-950">{{ $classPassPlan->name }}</h2>
                            @if ($classPassPlan->description)
                                <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-500">{{ $classPassPlan->description }}</p>
                            @endif
                        </div>
                        @if ($classPassPlan->is_trial)
                            <span class="crm-status-scheduled">{{ __('app.trial_class_pass_short') }}</span>
                        @endif
                    </div>

                    <div class="mt-6 text-4xl font-semibold text-slate-950">{{ $formatMoney($classPassPlan->price_cents, $classPassPlan->currency) }}</div>

                    <dl class="mt-6 grid gap-3 text-sm sm:grid-cols-2">
                        <div class="rounded-lg bg-slate-50 p-3">
                            <dt class="text-slate-500">{{ __('app.sessions_count') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-950">{{ $classPassPlan->sessions_count }}</dd>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3">
                            <dt class="text-slate-500">{{ __('app.location') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-950">{{ $location->name }}</dd>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3">
                            <dt class="text-slate-500">{{ __('app.validity_days_after_first_class') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-950">{{ $classPassPlan->validity_days }}</dd>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3">
                            <dt class="text-slate-500">{{ __('app.total_validity_days') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-950">{{ $classPassPlan->total_validity_days }}</dd>
                        </div>
                    </dl>

                    <div class="mt-5 flex flex-wrap gap-2 text-xs font-semibold text-slate-600">
                        @foreach ($classPassPlan->classTypes as $classType)
                            <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1">{{ $classType->name }}</span>
                        @endforeach
                        @foreach ($classPassPlan->trainerTypes as $trainerType)
                            <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1">{{ $trainerType->name }}</span>
                        @endforeach
                        @foreach ($classPassPlan->rooms as $room)
                            <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1">{{ $room->name }}</span>
                        @endforeach
                    </div>
                </article>

                <aside class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                    @if ($stage === 'login')
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.class_pass_checkout_login_title') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.class_pass_checkout_login_copy') }}</p>
                        <div class="mt-5">
                            <x-customer-auth.login-panel :account="$account" :methods="$methods" :mode="$otpPhone ? 'otp_code' : 'login'" :phone="$otpPhone" />
                        </div>
                    @elseif ($stage === 'google_phone')
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.customer_google_phone_title') }}</h2>
                        <div class="mt-5">
                            <x-customer-auth.google-phone-panel :account="$account" :phone="$googlePhone" />
                        </div>
                    @elseif ($stage === 'profile')
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.class_pass_checkout_profile_title') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.customer_profile_required') }}</p>
                        <div class="mt-5">
                            <x-customer-auth.profile-form
                                :account="$account"
                                :customer="$customer"
                                :profile-phone-merge="$profilePhoneMerge"
                                :embedded="true"
                                :show-portal-link="false"
                            />
                        </div>
                    @elseif ($stage === 'payment')
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.class_pass_checkout_payment_title') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.class_pass_checkout_payment_copy') }}</p>

                        @if (! $trialIsAvailable)
                            <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                                {{ __('app.trial_class_pass_not_available') }}
                            </div>
                        @elseif ($classPassPlan->price_cents === 0)
                            <form method="POST" action="{{ route('public.class-pass-plans.checkout.store', [$account->slug, $location->slug, $classPassPlan->slug]) }}" class="mt-5 space-y-4">
                                @csrf
                                @include('public._studio-rules-agreement')
                                <x-ui.button type="submit" variant="success" size="lg" class="w-full justify-center">
                                    {{ __('app.class_pass_checkout_free_action') }}
                                </x-ui.button>
                            </form>
                        @elseif ($paymentSettings->isEmpty())
                            <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                                {{ __('app.no_payment_methods_available') }}
                            </div>
                        @else
                            <form method="POST" action="{{ route('public.class-pass-plans.checkout.store', [$account->slug, $location->slug, $classPassPlan->slug]) }}" class="mt-5 space-y-4">
                                @csrf
                                @include('public._studio-rules-agreement')
                                <div class="space-y-3">
                                    @foreach ($paymentSettings as $setting)
                                        @php
                                            $provider = $setting->provider->value;
                                            $label = config('integrations.providers.'.$provider.'.label', $provider);
                                        @endphp
                                        <x-ui.button type="submit" name="provider" value="{{ $provider }}" variant="success" size="lg" class="w-full justify-start px-3">
                                            <x-ui.payment-brand :provider="$provider" :label="$label" presentation="card" class="w-full" />
                                        </x-ui.button>
                                    @endforeach
                                </div>
                            </form>
                            <x-ui.accepted-card-brands class="mt-5" />
                        @endif
                    @elseif ($stage === 'confirmation' && $purchase)
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.class_pass_checkout_confirmation_title') }}</h2>
                        <div class="mt-4 rounded-xl border px-4 py-4 {{ $purchaseStatusClass }}">
                            <div class="text-sm font-semibold">{{ __('app.class_pass_checkout_status_'.$purchaseStatus->value) }}</div>
                            @if ($purchase->isPaid() && $purchase->customerClassPass)
                                <div class="mt-3 text-sm">{{ __('app.class_pass_checkout_pass_code') }}</div>
                                <div class="mt-1 font-mono text-2xl font-semibold">{{ $purchase->customerClassPass->code }}</div>
                            @elseif (! $purchaseStatus->isFinal())
                                <p class="mt-2 text-sm leading-6">{{ __('app.class_pass_checkout_pending_help') }}</p>
                            @endif
                        </div>

                        @if (! $purchaseStatus->isFinal())
                            <div
                                class="mt-5"
                                data-class-pass-checkout-poll
                                data-status-url="{{ $statusUrl }}"
                                data-refresh-url="{{ request()->fullUrl() }}"
                            >
                                <p class="text-sm text-slate-500" data-class-pass-checkout-poll-message>{{ __('app.class_pass_checkout_checking_status') }}</p>
                                <div class="mt-3 hidden rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" data-class-pass-checkout-poll-timeout>
                                    {{ __('app.class_pass_checkout_poll_timeout') }}
                                </div>
                                <x-ui.button :href="request()->fullUrl()" variant="secondary" class="mt-3 hidden" data-class-pass-checkout-manual-refresh>
                                    {{ __('app.refresh') }}
                                </x-ui.button>
                            </div>
                        @elseif (! $purchase->isPaid())
                            <form method="POST" action="{{ route('public.class-pass-plans.checkout.retry', [$account->slug, $location->slug, $classPassPlan->slug]) }}" class="mt-5">
                                @csrf
                                <x-ui.button type="submit" class="w-full justify-center">
                                    {{ __('app.class_pass_checkout_retry') }}
                                </x-ui.button>
                            </form>
                        @endif

                        @if ($purchase->isPaid() && $purchase->customerClassPass)
                            <div class="mt-5 grid gap-3">
                                <x-ui.button :href="route('customer.dashboard', $account->slug)" class="w-full justify-center">
                                    {{ __('app.customer_portal') }}
                                </x-ui.button>
                                <x-ui.button :href="route('public.schedule', [$account->slug, $location->slug])" variant="secondary" class="w-full justify-center">
                                    {{ __('app.public_schedule') }}
                                </x-ui.button>
                            </div>
                        @endif
                    @endif
                </aside>
            </section>
        </section>
    </main>
@endsection
