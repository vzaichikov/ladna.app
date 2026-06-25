@extends('layouts.public')

@section('title', $account->name.' '.$location->name.' '.strtolower(__('app.price')))

@section('publicFooter')
    <x-ui.powered-footer class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    @php
        $formatMoney = static function (?int $priceCents, string $currency = 'UAH'): string {
            if ($priceCents === null) {
                return '';
            }

            return number_format($priceCents / 100, $priceCents % 100 === 0 ? 0 : 2, '.', ' ').' '.$currency;
        };
    @endphp

    <main class="min-h-[calc(100vh-8rem)] bg-canvas text-slate-950">
        <section class="mx-auto max-w-6xl px-5 sm:px-8 {{ $isEmbed ? 'py-5' : 'py-10' }}">
            @unless ($isEmbed)
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 text-sm font-semibold text-slate-600 hover:text-slate-950">
                    <x-ui.app-logo mark-class="h-9 w-9" />
                </a>
            @endunless

            <header class="mt-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-crm">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div class="text-sm font-semibold uppercase text-brand-600">{{ $account->name }}</div>
                        <h1 class="mt-2 text-4xl font-semibold leading-tight text-slate-950">{{ __('app.price') }}</h1>
                        <p class="mt-3 max-w-2xl text-slate-500">{{ $location->name }} · {{ $location->address }}</p>
                    </div>
                    <form method="POST" action="{{ route('locale.update') }}">
                        @csrf
                        <select name="locale" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-xs">
                            @foreach (config('charm.locales') as $locale => $label)
                                <option value="{{ $locale }}" @selected(app()->getLocale() === $locale)>{{ strtoupper($locale) }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
            </header>

            <section class="mt-8 space-y-10">
                @forelse ($priceGroups as $group)
                    <div>
                        <h2 class="text-2xl font-semibold text-slate-950">{{ $group['title'] }}</h2>
                        <div class="mt-4 space-y-6">
                            @foreach ($group['sections'] as $section)
                                <section>
                                    <div class="mb-3 text-sm font-semibold uppercase text-brand-600">{{ $section['title'] }}</div>
                                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                        @foreach ($section['plans'] as $classPassPlan)
                                            <article class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                                                <div class="flex items-start justify-between gap-4">
                                                    <div>
                                                        <h3 class="text-lg font-semibold text-slate-950">{{ $classPassPlan->name }}</h3>
                                                        @if ($classPassPlan->description)
                                                            <p class="mt-2 text-sm leading-6 text-slate-500">{{ $classPassPlan->description }}</p>
                                                        @endif
                                                    </div>
                                                    @if ($classPassPlan->is_trial)
                                                        <span class="crm-status-scheduled">{{ __('app.trial_class_pass_short') }}</span>
                                                    @endif
                                                </div>
                                                <div class="mt-5 text-3xl font-semibold text-slate-950">{{ $formatMoney($classPassPlan->price_cents, $classPassPlan->currency) }}</div>
                                                <dl class="mt-5 grid grid-cols-2 gap-3 text-sm">
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
                                                </dl>
                                                <div class="mt-4 flex flex-wrap gap-2 text-xs font-semibold text-slate-600">
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
                                                <div class="mt-5">
                                                    <x-ui.button :href="route('public.class-pass-plans.buy', [$account->slug, $location->slug, $classPassPlan->slug])" class="w-full">
                                                        <x-ui.icon name="credit-card" class="h-4 w-4" />
                                                        {{ __('app.buy') }}
                                                    </x-ui.button>
                                                </div>
                                            </article>
                                        @endforeach
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state icon="class-pass-plans">
                        {{ __('app.no_class_pass_plans') }}
                    </x-ui.empty-state>
                @endforelse
            </section>
        </section>
    </main>
@endsection
