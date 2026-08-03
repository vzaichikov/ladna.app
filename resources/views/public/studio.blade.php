@extends('layouts.public')

@section('title', $account->name.' - '.__('app.studio_public_landing_title'))

@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    @php
        $customerDisplayName = $customer?->name ?? $customer?->phone ?? $customer?->email;
        $studioColor = is_string($account->brand_color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $account->brand_color)
            ? $account->brand_color
            : '#3B223F';
    @endphp

    <main class="min-h-[calc(100vh-8rem)] bg-canvas text-slate-950" style="--studio-brand-color: {{ $studioColor }};">
        <section class="mx-auto max-w-6xl px-5 py-8 sm:px-8 sm:py-10">
            <header class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm">
                <div class="h-2" style="background-color: var(--studio-brand-color);"></div>
                <div class="grid gap-6 p-5 sm:p-8 lg:grid-cols-[1fr_auto] lg:items-end">
                    <div class="flex items-center gap-4 sm:gap-5">
                        <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl border border-stone-200 bg-slate-50 p-2.5 shadow-xs sm:h-20 sm:w-20 sm:rounded-2xl sm:p-3">
                            <img src="{{ $account->logoUrl() }}" alt="" class="max-h-11 max-w-11 object-contain sm:max-h-14 sm:max-w-14">
                        </span>
                        <div class="min-w-0">
                            <h1 class="text-3xl font-semibold leading-tight text-slate-950 sm:text-5xl">{{ $account->name }}</h1>
                            @if ($account->studio_slogan)
                                <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500 sm:mt-4 sm:text-base sm:leading-7">{{ $account->studio_slogan }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:justify-end">
                        @if ($customer)
                            <a
                                href="{{ route('customer.dashboard', $account->slug) }}"
                                class="inline-flex items-center justify-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-800 shadow-xs transition hover:border-emerald-300 hover:bg-emerald-100"
                                data-customer-dashboard-link
                            >
                                <x-ui.icon name="user" class="h-4 w-4" />
                                {{ __('app.public_schedule_logged_in_as', ['name' => $customerDisplayName ?? __('app.customer_section')]) }}
                            </a>
                        @else
                            <x-ui.button :href="route('customer.studio.login', $account->slug)" variant="brand">
                                <x-ui.icon name="log-in" class="h-4 w-4" />
                                {{ __('app.customer_login') }}
                            </x-ui.button>
                        @endif
                    </div>
                </div>
            </header>

            @if ($locations->count() > 1)
                <nav class="mt-8" aria-label="{{ __('app.studio_landing_locations_title') }}">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 class="text-2xl font-semibold text-slate-950">{{ __('app.studio_landing_locations_title') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.studio_landing_locations_copy') }}</p>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach ($locations as $location)
                            <a href="#location-{{ $location->slug }}" class="group flex min-h-28 items-start gap-4 rounded-xl border border-stone-200 bg-white p-4 shadow-xs transition hover:border-brand-100 hover:bg-brand-50">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-700 transition group-hover:bg-white group-hover:text-brand-700">
                                    <x-ui.icon name="locations" class="h-5 w-5" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block font-semibold text-slate-950">{{ $location->name }}</span>
                                    @if ($location->address)
                                        <span class="mt-1 block text-sm leading-6 text-slate-500">{{ $location->address }}</span>
                                    @endif
                                </span>
                            </a>
                        @endforeach
                    </div>
                </nav>
            @endif

            <section class="mt-8 space-y-8">
                @forelse ($locations as $location)
                    <article id="location-{{ $location->slug }}" class="scroll-mt-8 overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm">
                        <div class="p-6 sm:p-8">
                            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div class="inline-flex items-center gap-2 rounded-full bg-slate-50 px-3 py-1 text-sm font-semibold text-slate-600 ring-1 ring-stone-200">
                                        <x-ui.icon name="locations" class="h-4 w-4" />
                                        {{ __('app.location') }}
                                    </div>
                                    <h2 class="mt-4 text-3xl font-semibold leading-tight text-slate-950">{{ $location->name }}</h2>
                                    @if ($location->address)
                                        <p class="mt-3 max-w-2xl text-base leading-7 text-slate-500">{{ $location->address }}</p>
                                    @endif
                                </div>
                                <div class="flex flex-col gap-3 sm:flex-row lg:justify-end">
                                    @if (filled($account->studio_rules_html))
                                        <x-ui.public-legal-links
                                            :account="$account"
                                            :return-url="request()->fullUrl()"
                                            variant="landing"
                                            :show-offer="false"
                                        />
                                    @endif
                                    <x-ui.button :href="route('public.price', [$account->slug, $location->slug])" variant="secondary" class="w-full sm:w-auto">
                                        <x-ui.icon name="class-pass-plans" class="h-4 w-4" />
                                        {{ __('app.studio_landing_price_cta') }}
                                    </x-ui.button>
                                    <div class="grid gap-3">
                                        <x-ui.button :href="route('public.schedule', [$account->slug, $location->slug])" variant="secondary" class="w-full">
                                            <x-ui.icon name="schedule" class="h-4 w-4" />
                                            {{ __('app.studio_landing_schedule_cta') }}
                                        </x-ui.button>
                                        @if ($loop->first && $customerTelegramBotPublicStudioLink)
                                            <x-ui.button
                                                :href="$customerTelegramBotPublicStudioLink"
                                                variant="secondary"
                                                class="w-full"
                                                target="_blank"
                                                rel="noopener"
                                                data-customer-telegram-bot-link="public-studio"
                                            >
                                                <img src="{{ asset('assets/social/telegram.svg') }}" alt="" class="h-4 w-4 shrink-0">
                                                {{ __('app.customer_telegram_booking_bot') }}
                                            </x-ui.button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        @if ($location->google_maps_embed_url)
                            <div class="border-t border-stone-200 bg-stone-50">
                                <div class="aspect-video w-full">
                                    <iframe
                                        src="{{ $location->google_maps_embed_url }}"
                                        class="h-full w-full border-0"
                                        loading="lazy"
                                        referrerpolicy="no-referrer-when-downgrade"
                                        allowfullscreen
                                    ></iframe>
                                </div>
                            </div>
                        @endif
                    </article>
                @empty
                    <x-ui.empty-state icon="locations">
                        {{ __('app.studio_landing_no_locations') }}
                    </x-ui.empty-state>
                @endforelse
            </section>

            @if ($events->isNotEmpty())
                <section class="mt-10">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold text-brand-700">{{ __('app.event_one_time_activities') }}</p>
                            <h2 class="mt-1 text-2xl font-semibold text-slate-950">{{ __('app.events_upcoming_public') }}</h2>
                        </div>
                        <x-ui.button :href="route('public.events.index', $account->slug)" variant="secondary">
                            {{ __('app.events_view_all_public') }}
                            <x-ui.icon name="arrow-right" class="h-4 w-4" />
                        </x-ui.button>
                    </div>

                    <div
                        class="mt-5 grid snap-x snap-mandatory auto-cols-[86%] grid-flow-col gap-4 overflow-x-auto pb-3 scrollbar-thin sm:auto-cols-[22rem] lg:auto-cols-[24rem]"
                        aria-label="{{ __('app.events_upcoming_public') }}"
                        data-public-events-rail
                    >
                        @foreach ($events as $event)
                            <x-ui.public-event-card :account="$account" :event="$event" class="snap-start" />
                        @endforeach
                    </div>
                </section>
            @endif

            <x-ui.public-contact-links :account="$account" :telegram-bot-link="$customerTelegramBotPublicContactsLink" class="mt-8" />
        </section>
    </main>
@endsection
