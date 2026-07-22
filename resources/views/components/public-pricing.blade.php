@props(['pricing', 'registrationAvailable' => false])

@php
    $landing = __('app.landing');
    $initialLocationCount = $pricing['minimum_location_count'];
    $initialQuote = $pricing['quotes'][$initialLocationCount];
@endphp

<section
    id="pricing"
    class="relative overflow-hidden border-y border-[#E7DDC9]/80 bg-[#2B1731] px-5 py-20 text-white sm:px-8 lg:px-10"
    data-public-pricing
    data-pricing-quotes='@json($pricing['quotes'])'
    data-pricing-monthly-period="{{ $landing['pricing_per_month'] }}"
    data-pricing-annual-period="{{ $landing['pricing_per_year'] }}"
>
    <div class="absolute inset-0" aria-hidden="true">
        <div class="absolute left-[-10rem] top-[-12rem] h-96 w-96 rounded-full bg-[#A78AB9]/28 blur-3xl"></div>
        <div class="absolute bottom-[-14rem] right-[-10rem] h-[34rem] w-[34rem] rounded-full bg-[#E7DDC9]/18 blur-3xl"></div>
        <div class="absolute left-[38%] top-16 h-72 w-72 rounded-full border border-white/8"></div>
    </div>

    <div class="relative mx-auto max-w-7xl">
        <div class="grid gap-10 lg:grid-cols-[0.88fr_1.12fr] lg:items-start">
            <div class="max-w-xl">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-[#C7B4D3]">
                    {{ $landing['pricing_eyebrow'] }}
                </p>
                <h2 class="mt-3 text-3xl font-semibold leading-tight sm:text-5xl">
                    {{ $landing['pricing_title'] }}
                </h2>
                <p class="mt-5 text-base leading-7 text-white/72 sm:text-lg sm:leading-8">
                    {{ $landing['pricing_copy'] }}
                </p>

                <div class="mt-8 flex flex-col gap-3">
                    <div class="flex items-start gap-3 rounded-lg border border-[#C7B4D3]/25 bg-white/[0.08] p-4">
                        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#DCCFF0] text-[#2B1731]">
                            <x-ui.icon name="sparkles" class="h-4 w-4" />
                        </span>
                        <div>
                            <div class="font-semibold text-white">
                                {{ __('app.landing.pricing_trial_badge', ['days' => $pricing['trial_days']]) }}
                            </div>
                            <p class="mt-1 text-sm leading-6 text-white/68">{{ $landing['pricing_no_card'] }}</p>
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-white/10 bg-white/[0.06] p-4 text-sm font-semibold text-white/82">
                            <span class="flex items-center gap-2">
                                <x-ui.icon name="badge-check" class="h-4 w-4 text-[#C7B4D3]" />
                                {{ $landing['pricing_no_setup_fee'] }}
                            </span>
                        </div>
                        <div class="rounded-lg border border-white/10 bg-white/[0.06] p-4 text-sm font-semibold text-white/82">
                            <span class="flex items-center gap-2">
                                <x-ui.icon name="calendar-check" class="h-4 w-4 text-[#C7B4D3]" />
                                {{ $landing['pricing_cancel_rule'] }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-white/14 bg-[#FAF8F5] p-5 text-[#2B2B2F] shadow-[0_28px_80px_rgba(18,8,22,0.32)] sm:p-7">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-[#4D3152]/68">{{ $pricing['plan_name'] }}</p>
                        <p class="mt-1 text-xl font-semibold text-[#2B1731]">{{ $landing['pricing_location_label'] }}</p>
                    </div>
                    <div class="inline-flex w-full rounded-lg border border-[#A78AB9]/30 bg-white p-1 sm:w-auto" role="group" aria-label="{{ $landing['pricing_location_label'] }}">
                        <button
                            type="button"
                            class="flex flex-1 items-center justify-center rounded-md px-4 py-2 text-sm font-semibold text-[#4D3152] transition aria-pressed:bg-[#3B223F] aria-pressed:text-white sm:flex-none"
                            data-pricing-interval="monthly"
                            aria-pressed="true"
                        >
                            {{ $landing['pricing_monthly'] }}
                        </button>
                        <button
                            type="button"
                            class="flex flex-1 items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-semibold text-[#4D3152] transition aria-pressed:bg-[#3B223F] aria-pressed:text-white sm:flex-none"
                            data-pricing-interval="annual"
                            aria-pressed="false"
                        >
                            {{ $landing['pricing_annual'] }}
                            <span class="rounded-sm bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">
                                {{ __('app.landing.pricing_save_percent', ['percent' => $pricing['annual_discount_percent']]) }}
                            </span>
                        </button>
                    </div>
                </div>

                <div class="mt-7 grid gap-6 md:grid-cols-[0.8fr_1.2fr] md:items-end">
                    <div>
                        <label for="public-pricing-location-count" class="text-sm font-semibold text-[#4D3152]">
                            {{ $landing['pricing_location_label'] }}
                        </label>
                        <div class="mt-2 grid grid-cols-[2.75rem_1fr_2.75rem] overflow-hidden rounded-lg border border-[#A78AB9]/35 bg-white shadow-xs">
                            <button type="button" class="flex h-12 items-center justify-center text-[#3B223F] transition hover:bg-[#DCCFF0]/45 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#A78AB9]" data-pricing-decrement aria-label="{{ $landing['pricing_location_decrease'] }}">
                                <x-ui.icon name="minus" class="h-4 w-4" />
                            </button>
                            <input
                                id="public-pricing-location-count"
                                type="number"
                                inputmode="numeric"
                                min="{{ $pricing['minimum_location_count'] }}"
                                max="{{ $pricing['maximum_location_count'] }}"
                                value="{{ $initialLocationCount }}"
                                class="public-pricing-number-input h-12 w-full border-x border-[#A78AB9]/25 bg-white text-center text-lg font-semibold text-[#2B1731] outline-none focus:ring-2 focus:ring-inset focus:ring-[#A78AB9]"
                                data-pricing-location-count
                            >
                            <button type="button" class="flex h-12 items-center justify-center text-[#3B223F] transition hover:bg-[#DCCFF0]/45 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#A78AB9]" data-pricing-increment aria-label="{{ $landing['pricing_location_increase'] }}">
                                <x-ui.icon name="plus" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <div class="rounded-lg bg-[#E7DDC9]/48 p-4" aria-live="polite">
                        <div class="flex items-end gap-2">
                            <output class="text-4xl font-semibold tracking-tight text-[#2B1731] sm:text-5xl" data-pricing-total>
                                {{ $initialQuote['monthly']['total'] }}
                            </output>
                            <span class="pb-1 text-sm font-semibold text-[#4D3152]/68" data-pricing-period>{{ $landing['pricing_per_month'] }}</span>
                        </div>
                        <p class="mt-2 text-sm text-[#4D3152]/75" data-pricing-location-label>
                            {{ trans_choice('app.landing.pricing_location_count', $initialLocationCount, ['count' => $initialLocationCount]) }}
                        </p>
                        <p class="mt-2 text-sm font-semibold text-emerald-700">
                            {{ $landing['pricing_annual_savings'] }}:
                            <span data-pricing-annual-savings>{{ $initialQuote['annual']['discount'] }}</span>
                        </p>
                    </div>
                </div>

                <p class="mt-4 text-sm leading-6 text-[#4D3152]/68">
                    {{ $landing['pricing_active_location_definition'] }}
                </p>

                <div class="mt-7 border-t border-[#E7DDC9] pt-6">
                    <h3 class="text-sm font-semibold uppercase tracking-[0.14em] text-[#4D3152]/62">
                        {{ $landing['pricing_tiers_title'] }}
                    </h3>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach ($pricing['tiers'] as $tier)
                            <div class="rounded-lg border border-[#E7DDC9] bg-white p-4">
                                <p class="text-sm font-semibold text-[#2B1731]">
                                    @if ($tier['ends_at_location'] === null)
                                        {{ __('app.landing.pricing_tier_open', ['from' => $tier['starts_at_location']]) }}
                                    @elseif ($tier['starts_at_location'] === $tier['ends_at_location'])
                                        {{ __('app.landing.pricing_tier_single', ['from' => $tier['starts_at_location']]) }}
                                    @else
                                        {{ __('app.landing.pricing_tier_range', ['from' => $tier['starts_at_location'], 'to' => $tier['ends_at_location']]) }}
                                    @endif
                                </p>
                                <p class="mt-2 text-lg font-semibold text-[#2B1731]">{{ $tier['unit_price'] }}</p>
                                <p class="mt-1 text-xs leading-5 text-[#4D3152]/62">{{ $landing['pricing_per_active_location'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 grid gap-4 lg:grid-cols-[1fr_0.72fr]">
            <div class="rounded-xl border border-white/12 bg-white/[0.08] p-6">
                <h3 class="text-lg font-semibold">{{ $landing['pricing_included_title'] }}</h3>
                <div class="mt-5 grid gap-3 md:grid-cols-3">
                    @foreach ($landing['pricing_included_items'] as $item)
                        <div class="flex items-start gap-3 text-sm leading-6 text-white/78">
                            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#C7B4D3] text-[#2B1731]">
                                <x-ui.icon name="check" class="h-3.5 w-3.5" />
                            </span>
                            <span>{{ $item }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-col justify-between gap-5 rounded-xl border border-[#E7DDC9]/26 bg-[#E7DDC9]/12 p-6">
                <p class="text-sm leading-6 text-white/68">{{ $landing['pricing_third_party'] }}</p>
                <div class="flex flex-col gap-3">
                    <a href="{{ $registrationAvailable ? route('register') : route('demo.login', [], false) }}" class="inline-flex h-12 items-center justify-center gap-2 rounded-lg bg-[#E7DDC9] px-6 text-sm font-semibold text-[#2B1731] shadow-[0_18px_34px_rgba(0,0,0,0.16)] transition hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C7B4D3] focus-visible:ring-offset-2 focus-visible:ring-offset-[#2B1731]">
                        {{ $registrationAvailable ? __('app.onboarding.registration_cta') : $landing['pricing_cta'] }}
                        <x-ui.icon name="arrow-right" class="h-4 w-4" />
                    </a>
                    @if ($registrationAvailable)
                        <a href="{{ route('demo.login', [], false) }}" data-pricing-demo-cta class="inline-flex h-12 items-center justify-center rounded-lg border border-white/20 bg-white/[0.08] px-6 text-sm font-semibold text-white transition hover:border-white/35 hover:bg-white/[0.14] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C7B4D3] focus-visible:ring-offset-2 focus-visible:ring-offset-[#2B1731]">
                            {{ $landing['pricing_cta'] }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>
