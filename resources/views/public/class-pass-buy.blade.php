@extends('layouts.public')

@section('title', __('app.buy_class_pass').' - '.$classPassPlan->name)

@section('publicFooter')
    <x-ui.powered-footer class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    @php
        $formatMoney = static fn (?int $priceCents, string $currency = 'UAH'): string => $priceCents === null
            ? ''
            : number_format($priceCents / 100, $priceCents % 100 === 0 ? 0 : 2, '.', ' ').' '.$currency;
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
                        <h1 class="text-2xl font-semibold text-slate-950">{{ __('app.buy_class_pass') }}</h1>
                    </div>
                </div>
                <x-ui.button :href="route('public.price', [$account->slug, $location->slug])" variant="secondary">
                    <x-ui.icon name="arrow-left" class="h-4 w-4" />
                    {{ __('app.public_price') }}
                </x-ui.button>
            </div>

            @if ($errors->any())
                <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-900">
                    {{ $errors->first() }}
                </div>
            @endif

            <section class="mt-6 grid gap-6 lg:grid-cols-[1fr_0.75fr]">
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

                    <dl class="mt-6 grid gap-3 text-sm sm:grid-cols-4">
                        <div class="rounded-lg bg-slate-50 p-3">
                            <dt class="text-slate-500">{{ __('app.sessions_count') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-950">{{ $classPassPlan->sessions_count }}</dd>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3">
                            <dt class="text-slate-500">{{ __('app.validity_days_after_first_class') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-950">{{ $classPassPlan->validity_days }}</dd>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3">
                            <dt class="text-slate-500">{{ __('app.total_validity_days') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-950">{{ $classPassPlan->total_validity_days }}</dd>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3">
                            <dt class="text-slate-500">{{ __('app.location') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-950">{{ $location->name }}</dd>
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

                <aside class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.payment_method') }}</h2>

                    <div class="mt-4 space-y-3">
                        @forelse ($paymentSettings as $setting)
                            @php
                                $provider = $setting->provider->value;
                                $label = config('integrations.providers.'.$provider.'.label', $provider);
                            @endphp
                            <form method="POST" action="{{ route('public.class-pass-plans.purchase', [$account->slug, $location->slug, $classPassPlan->slug]) }}">
                                @csrf
                                <input type="hidden" name="provider" value="{{ $provider }}">
                                <div class="mb-3">
                                    @include('public._studio-rules-agreement')
                                </div>
                                <x-ui.button type="submit" variant="secondary" size="lg" class="w-full justify-start px-3">
                                    <x-ui.payment-brand :provider="$provider" :label="$label" class="w-full" />
                                </x-ui.button>
                            </form>
                        @empty
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                                {{ __('app.no_payment_methods_available') }}
                            </div>
                        @endforelse
                    </div>
                </aside>
            </section>
        </section>
    </main>
@endsection
