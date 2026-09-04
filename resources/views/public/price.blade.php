@extends('layouts.public')

@section('title', $account->name.' '.$location->name.' '.__('app.public_price_title'))

@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="! $isEmbed" class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    @php
        $formatMoney = static function (?int $priceCents, string $currency = 'UAH'): string {
            if ($priceCents === null) {
                return '';
            }

            return \App\Support\MoneyFormatter::format($priceCents, $currency);
        };
    @endphp

    <main class="min-h-[calc(100vh-8rem)] bg-canvas text-slate-950">
        <section class="mx-auto max-w-6xl px-4 py-4 sm:px-6">
            @include('public._compact-header')

            @if ($priceGroups->isNotEmpty())
                @php
                    $initialGroupKey = $priceGroups->first()['key'];
                @endphp
                <section class="mt-8" data-public-price-kind-tabs data-active-kind="{{ $initialGroupKey }}">
                    <div class="overflow-x-auto pb-1">
                        <div class="grid min-w-[21rem] grid-cols-3 gap-1 rounded-xl bg-brand-600 p-1.5 shadow-crm" role="tablist" aria-label="{{ __('app.public_price_title') }}">
                            @foreach ($priceGroups as $groupIndex => $group)
                                @php
                                    $kindTabId = 'public-price-kind-tab-'.$groupIndex;
                                    $kindPanelId = 'public-price-kind-panel-'.$groupIndex;
                                @endphp
                                <button
                                    type="button"
                                    id="{{ $kindTabId }}"
                                    class="inline-flex min-h-11 items-center justify-center whitespace-nowrap rounded-lg px-3 py-2.5 text-sm font-semibold text-white/70 transition hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand-600 aria-selected:bg-white aria-selected:text-brand-700 aria-selected:shadow-xs"
                                    role="tab"
                                    data-public-price-kind-tab="{{ $group['key'] }}"
                                    aria-controls="{{ $kindPanelId }}"
                                    aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                                    tabindex="{{ $loop->first ? '0' : '-1' }}"
                                >
                                    {{ __('app.public_price_'.$group['key'].'_tab') }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="mt-4">
                        @foreach ($priceGroups as $groupIndex => $group)
                            @php
                                $kindTabId = 'public-price-kind-tab-'.$groupIndex;
                                $kindPanelId = 'public-price-kind-panel-'.$groupIndex;
                                $hasSectionTabs = $group['sections']->count() > 1;
                            @endphp
                            <section
                                id="{{ $kindPanelId }}"
                                role="tabpanel"
                                data-public-price-kind-panel="{{ $group['key'] }}"
                                aria-labelledby="{{ $kindTabId }}"
                            >
                                <h2 class="sr-only">{{ $group['title'] }}</h2>
                                <div
                                    @if ($hasSectionTabs)
                                        data-public-price-segment-tabs
                                        data-active-section="{{ $group['sections']->first()['key'] }}"
                                    @endif
                                >
                        @if ($hasSectionTabs)
                            <div class="overflow-x-auto pb-1">
                                <div class="flex min-w-max gap-1 rounded-lg bg-stone-100 p-1" role="tablist" aria-label="{{ $group['title'] }}">
                                    @foreach ($group['sections'] as $sectionIndex => $section)
                                        @php
                                            $sectionLabel = $section['title'] !== ''
                                                ? $section['title']
                                                : __('app.public_price_other_options');
                                            $tabId = 'public-price-segment-tab-'.$groupIndex.'-'.$sectionIndex;
                                            $panelId = 'public-price-segment-panel-'.$groupIndex.'-'.$sectionIndex;
                                        @endphp
                                        <button
                                            type="button"
                                            id="{{ $tabId }}"
                                            class="crm-tab whitespace-nowrap"
                                            role="tab"
                                            data-public-price-segment-tab="{{ $section['key'] }}"
                                            aria-controls="{{ $panelId }}"
                                            aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                                            tabindex="{{ $loop->first ? '0' : '-1' }}"
                                        >
                                            {{ $sectionLabel }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div @class(['mt-4' => $hasSectionTabs, 'space-y-6' => ! $hasSectionTabs])>
                            @foreach ($group['sections'] as $sectionIndex => $section)
                                @php
                                    $tabId = 'public-price-segment-tab-'.$groupIndex.'-'.$sectionIndex;
                                    $panelId = 'public-price-segment-panel-'.$groupIndex.'-'.$sectionIndex;
                                @endphp
                                <section
                                    @if ($hasSectionTabs)
                                        id="{{ $panelId }}"
                                        role="tabpanel"
                                        data-public-price-segment-panel="{{ $section['key'] }}"
                                        aria-labelledby="{{ $tabId }}"
                                    @endif
                                >
                                    @if (! $hasSectionTabs && $section['title'] !== '')
                                        <div class="mb-3 flex items-center gap-3">
                                            <span class="inline-flex items-center rounded-md border border-brand-200 bg-brand-50 px-3 py-1.5 text-sm font-semibold text-brand-700 shadow-xs">
                                                {{ $section['title'] }}
                                            </span>
                                            <span class="h-px flex-1 bg-stone-200"></span>
                                        </div>
                                    @endif
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
                                                <div class="mt-4 flex flex-wrap gap-2 text-xs font-semibold text-slate-600" data-class-type-list>
                                                    @foreach ($classPassPlan->classTypes as $classType)
                                                        <span
                                                            @class([
                                                                'rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1',
                                                                'hidden' => $loop->index >= 2,
                                                            ])
                                                            @if ($loop->index >= 2) data-class-type-list-extra @endif
                                                        >
                                                            {{ $classType->name }}
                                                        </span>
                                                    @endforeach
                                                    @if ($classPassPlan->classTypes->count() > 2)
                                                        @php
                                                            $hiddenClassTypesCount = $classPassPlan->classTypes->count() - 2;
                                                        @endphp
                                                        <button
                                                            type="button"
                                                            class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 transition hover:border-slate-300 hover:text-slate-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                                            data-class-type-list-toggle
                                                            data-collapsed-label="{{ __('app.more_class_types', ['count' => $hiddenClassTypesCount]) }}"
                                                            data-expanded-label="{{ __('app.less_class_types') }}"
                                                            aria-expanded="false"
                                                        >
                                                            <span data-class-type-list-toggle-label>{{ __('app.more_class_types', ['count' => $hiddenClassTypesCount]) }}</span>
                                                        </button>
                                                    @endif
                                                    @foreach ($classPassPlan->trainerTypes as $trainerType)
                                                        <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1">{{ $trainerType->name }}</span>
                                                    @endforeach
                                                    @foreach ($classPassPlan->rooms as $room)
                                                        <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1">{{ $room->name }}</span>
                                                    @endforeach
                                                </div>
                                                <div class="mt-5">
                                                    <x-ui.button :href="route('public.class-pass-plans.checkout', [$account->slug, $location->slug, $classPassPlan->slug])" class="w-full">
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
                            </section>
                        @endforeach
                    </div>
                </section>
            @else
                <section class="mt-8">
                    <x-ui.empty-state icon="class-pass-plans">
                        {{ __('app.no_class_pass_plans') }}
                    </x-ui.empty-state>
                </section>
            @endif

            @unless ($isEmbed)
                <x-ui.public-contact-links :account="$account" class="mt-8" />
            @endunless
        </section>
    </main>
@endsection
