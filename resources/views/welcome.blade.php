@extends('layouts.public')

@section('title', __('app.app_name'))

@section('content')
    @php
        $landing = __('app.landing');
        $demoPlan = $demoPlan ?? null;
        $standardPlan = $standardPlan ?? null;
        $primaryLabel = auth()->check() ? __('app.dashboard') : $landing['hero_primary'];
        $currentLandingLocale = app()->getLocale() === 'en' ? 'en' : 'uk';
        $loginHref = $currentLandingLocale === 'en' ? route('login.en') : route('login');
        $headerAuthHref = auth()->check() ? route('dashboard.index') : $loginHref;
        $headerAuthLabel = auth()->check() ? __('app.dashboard') : __('app.login');
        $primaryHref = auth()->check()
            ? route('dashboard.index')
            : route('demo.signup.create');
        $formatMoney = fn (?int $cents, ?string $currency, int $fallbackCents): string => \App\Support\MoneyFormatter::format($cents, $currency, $fallbackCents);
        $demoPrice = $formatMoney($demoPlan?->price_cents, $demoPlan?->currency, 100);
        $standardPrice = $formatMoney($standardPlan?->price_cents, $standardPlan?->currency, 99900);
        $pricingCopy = __('app.landing.pricing_copy', [
            'demo_price' => $demoPrice,
            'standard_price' => $standardPrice,
        ]);
        $landingLocales = [
            'uk' => ['label' => 'UA', 'href' => route('home')],
            'en' => ['label' => 'EN', 'href' => route('home.en')],
        ];
        $featureSections = [
            [
                'id' => 'schedule',
                'title' => $landing['rhythm_title'],
                'copy' => $landing['rhythm_copy'],
                'items' => $landing['rhythm_items'],
                'icon' => 'calendar-days',
            ],
            [
                'id' => 'passes',
                'title' => $landing['passes_title'],
                'copy' => $landing['passes_copy'],
                'items' => $landing['passes_items'],
                'icon' => 'ticket-check',
            ],
            [
                'id' => 'clients',
                'title' => $landing['clients_title'],
                'copy' => $landing['clients_copy'],
                'items' => $landing['clients_items'],
                'icon' => 'heart-handshake',
            ],
            [
                'id' => 'team',
                'title' => $landing['team_title'],
                'copy' => $landing['team_copy'],
                'items' => $landing['team_items'],
                'icon' => 'users-round',
            ],
        ];
    @endphp

    <main class="overflow-hidden bg-[#FAF8F5] text-[#2B2B2F]">
        <section class="relative min-h-[88vh] px-5 pb-10 pt-5 sm:px-8 lg:min-h-[86vh] lg:px-10">
            <div class="absolute inset-0 overflow-hidden" aria-hidden="true">
                <div class="absolute left-[-8rem] top-[-10rem] h-96 w-96 rounded-full bg-[#E7DDC9]/55 blur-3xl"></div>
                <div class="absolute right-[-12rem] top-12 h-[34rem] w-[34rem] rounded-full bg-[#DCCFF0]/45 blur-3xl"></div>
                <div class="absolute bottom-[-14rem] left-[20%] h-[28rem] w-[42rem] rounded-full bg-white/70 blur-3xl"></div>
                <div class="absolute inset-x-0 top-24 h-px bg-gradient-to-r from-transparent via-[#A78AB9]/30 to-transparent"></div>
            </div>

            <div class="relative mx-auto flex max-w-7xl flex-col">
                <header class="flex items-center justify-between gap-4">
                    <a href="{{ route('home') }}" class="inline-flex items-center gap-3 text-[#2B1731]">
                        <x-ui.app-logo
                            mark-class="h-10 w-10"
                            text-class="text-[#2B1731]"
                        />
                    </a>

                    <nav class="hidden items-center gap-8 text-sm font-semibold text-[#4D3152] md:flex" aria-label="Landing navigation">
                        <a href="#schedule" class="transition hover:text-[#2B1731]">{{ $landing['nav_schedule'] }}</a>
                        <a href="#passes" class="transition hover:text-[#2B1731]">{{ $landing['nav_passes'] }}</a>
                        <a href="#clients" class="transition hover:text-[#2B1731]">{{ $landing['nav_clients'] }}</a>
                        <a href="#team" class="transition hover:text-[#2B1731]">{{ $landing['nav_team'] }}</a>
                    </nav>

                    <div class="flex items-center gap-2">
                        <nav class="inline-flex h-10 items-center rounded-lg border border-[#A78AB9]/30 bg-white/70 p-1 shadow-xs" aria-label="{{ __('app.default_language') }}">
                            @foreach ($landingLocales as $locale => $localeOption)
                                <a
                                    href="{{ $localeOption['href'] }}"
                                    @class([
                                        'flex h-8 min-w-9 items-center justify-center rounded-md px-2 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2',
                                        'bg-[#3B223F] text-white shadow-[0_8px_18px_rgba(59,34,63,0.16)]' => $currentLandingLocale === $locale,
                                        'text-[#4D3152] hover:bg-[#DCCFF0]/45 hover:text-[#2B1731]' => $currentLandingLocale !== $locale,
                                    ])
                                >
                                    {{ $localeOption['label'] }}
                                </a>
                            @endforeach
                        </nav>

                        <a href="{{ $headerAuthHref }}" data-landing-header-auth class="inline-flex h-10 items-center justify-center rounded-lg bg-[#3B223F] px-4 text-sm font-semibold text-white shadow-[0_14px_32px_rgba(59,34,63,0.2)] transition hover:bg-[#2B1731] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2">
                            {{ $headerAuthLabel }}
                        </a>
                    </div>
                </header>

                <div class="grid flex-1 gap-8 py-10 lg:min-h-[calc(86vh-5rem)] lg:grid-cols-[0.94fr_1.06fr] lg:items-center lg:py-6">
                    <div class="max-w-3xl">
                        <h1 class="text-4xl font-semibold leading-[1.04] text-[#2B1731] sm:text-6xl lg:text-7xl">
                            {{ $landing['hero_title'] }}
                        </h1>
                        <p class="mt-6 max-w-2xl text-lg leading-8 text-[#4D3152]/80 sm:text-xl sm:leading-9">
                            {{ $landing['hero_copy'] }}
                        </p>

                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            <a href="{{ $primaryHref }}" class="inline-flex h-12 items-center justify-center rounded-lg bg-[#3B223F] px-6 text-sm font-semibold text-white shadow-[0_18px_34px_rgba(59,34,63,0.2)] transition hover:bg-[#2B1731] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2">
                                {{ $primaryLabel }}
                            </a>
                            <a href="#flow" class="inline-flex h-12 items-center justify-center rounded-lg border border-[#A78AB9]/30 bg-white/70 px-6 text-sm font-semibold text-[#3B223F] shadow-xs transition hover:border-[#A78AB9]/60 hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2">
                                {{ $landing['hero_secondary'] }}
                            </a>
                            @unless (auth()->check())
                                <a href="{{ $loginHref }}" class="inline-flex h-12 items-center justify-center rounded-lg px-6 text-sm font-semibold text-[#3B223F] transition hover:bg-white/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2">
                                    {{ __('app.login') }}
                                </a>
                            @endunless
                        </div>

                        <div class="mt-8 max-w-xl border-l-2 border-[#A78AB9]/40 pl-4 text-sm leading-6 text-[#4D3152]/70">
                            {{ $landing['hero_note'] }}
                        </div>
                    </div>

                    <div class="relative min-h-[34rem] lg:min-h-[40rem]" aria-hidden="true">
                        <div class="absolute inset-0">
                            <div class="absolute left-1/2 top-1/2 h-[32rem] w-[32rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#E7DDC9]/34"></div>
                            <div class="absolute right-0 top-8 h-80 w-80 rounded-full border-2 border-[#A78AB9]/24"></div>
                            <div class="absolute bottom-8 left-5 h-72 w-72 rounded-full border border-[#E7DDC9]/80"></div>
                            <div class="absolute left-7 top-24 grid grid-cols-4 gap-2 opacity-75">
                                @foreach ([true, false, false, true, false, true, false, false, true, false, true, false] as $isActive)
                                    <span class="h-7 w-9 rounded-md {{ $isActive ? 'bg-[#C7B4D3]/60' : 'bg-white/70' }}"></span>
                                @endforeach
                            </div>
                            <div class="absolute left-16 top-11 h-20 w-10 rounded-full border border-[#E7DDC9]/80 bg-[#E7DDC9]/28"></div>
                            <div class="absolute right-14 top-32 h-24 w-14 rounded-full border border-[#A78AB9]/20 bg-[#A78AB9]/12"></div>
                            <div class="absolute bottom-20 right-20 h-20 w-20 rounded-full bg-[#DCCFF0]/45"></div>
                            <div class="absolute bottom-7 left-1/2 h-28 w-[70%] -translate-x-1/2 rounded-[50%] bg-[#3B223F]/10 blur-2xl"></div>
                            <svg class="absolute right-10 top-4 h-80 w-80 text-[#A78AB9]/35" viewBox="0 0 320 320" fill="none">
                                <path d="M36 218C88 76 214 48 284 101" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                                <path d="M80 265C126 223 207 214 258 246" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                                <circle cx="284" cy="101" r="6" fill="currentColor" />
                            </svg>
                            <svg class="absolute left-2 top-4 h-24 w-24 text-[#A78AB9]/22" viewBox="0 0 96 96" fill="none">
                                <circle cx="48" cy="17" r="7" fill="currentColor" />
                                <path d="M48 25C47 41 38 48 27 54M48 26C56 40 66 46 77 50M48 35C45 51 40 66 32 81M49 36C55 53 61 66 70 80" stroke="currentColor" stroke-width="7" stroke-linecap="round" />
                            </svg>
                            <svg class="absolute bottom-24 right-4 h-28 w-28 text-[#3B223F]/10" viewBox="0 0 112 112" fill="none">
                                <circle cx="54" cy="22" r="7" fill="currentColor" />
                                <path d="M52 32C44 42 35 52 20 59M54 35C67 41 78 48 91 58M47 52C40 68 34 79 25 92M56 53C68 68 75 79 88 90" stroke="currentColor" stroke-width="8" stroke-linecap="round" />
                            </svg>
                        </div>
                        <img
                            src="{{ asset('assets/brand/landing/ladna-landing-mascot-cutout.png') }}"
                            alt=""
                            class="absolute bottom-0 left-1/2 h-full max-h-[44rem] w-auto max-w-none -translate-x-1/2 object-contain drop-shadow-[0_28px_54px_rgba(59,34,63,0.18)]"
                        >
                    </div>
                </div>
            </div>
        </section>

        <section class="relative border-y border-[#E7DDC9]/80 bg-white/65 px-5 py-18 sm:px-8 lg:px-10">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_14%_18%,rgba(167,138,185,0.14),transparent_26%),radial-gradient(circle_at_86%_20%,rgba(231,221,201,0.54),transparent_28%)]" aria-hidden="true"></div>
            <div class="relative mx-auto max-w-7xl">
                <div class="max-w-3xl">
                    <h2 class="text-3xl font-semibold leading-tight text-[#2B1731] sm:text-5xl">
                        {{ $landing['pain_title'] }}
                    </h2>
                    <p class="mt-5 text-base leading-7 text-[#4D3152]/75 sm:text-lg sm:leading-8">
                        {{ $landing['pain_copy'] }}
                    </p>
                </div>

                <div class="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($landing['pain_items'] as $item)
                        <article class="min-h-52 rounded-lg border border-[#E7DDC9]/80 bg-[#FAF8F5]/88 p-5 shadow-[0_18px_44px_rgba(59,34,63,0.06)]">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-[#3B223F] text-white shadow-[0_12px_26px_rgba(59,34,63,0.18)]">
                                <x-ui.icon name="check" class="h-5 w-5" />
                            </span>
                            <h3 class="mt-5 text-lg font-semibold leading-7 text-[#2B1731]">{{ $item['title'] }}</h3>
                            <p class="mt-3 text-sm leading-6 text-[#4D3152]/75">{{ $item['copy'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="flow" class="relative border-y border-[#E7DDC9]/80 bg-white/50 px-5 py-20 sm:px-8 lg:px-10">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_12%_22%,rgba(167,138,185,0.12),transparent_28%),radial-gradient(circle_at_88%_40%,rgba(231,221,201,0.55),transparent_30%)]" aria-hidden="true"></div>
            <div class="relative mx-auto max-w-7xl">
                <div class="max-w-3xl">
                    <h2 class="text-3xl font-semibold leading-tight text-[#2B1731] sm:text-5xl">
                        {{ $landing['flow_title'] }}
                    </h2>
                </div>

                <div class="mt-12 grid gap-4 md:grid-cols-4">
                    @foreach ($landing['flow_steps'] as $index => $step)
                        <article class="relative overflow-hidden rounded-lg border border-[#E7DDC9]/80 bg-[#FAF8F5]/90 p-5 shadow-[0_18px_44px_rgba(59,34,63,0.06)]">
                            <div class="absolute -right-5 -top-5 h-20 w-20 rounded-full bg-[#DCCFF0]/40" aria-hidden="true"></div>
                            <div class="relative flex h-10 w-10 items-center justify-center rounded-lg bg-[#3B223F] text-sm font-semibold text-white">
                                {{ $index + 1 }}
                            </div>
                            <h3 class="relative mt-5 text-lg font-semibold text-[#2B1731]">{{ $step['title'] }}</h3>
                            <p class="relative mt-3 text-sm leading-6 text-[#4D3152]/75">{{ $step['copy'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="border-b border-[#E7DDC9]/80 bg-[#FAF8F5] px-5 py-18 sm:px-8 lg:px-10">
            <div class="mx-auto grid max-w-7xl gap-8 lg:grid-cols-[0.8fr_1.2fr] lg:items-start">
                <div>
                    <h2 class="text-3xl font-semibold leading-tight text-[#2B1731] sm:text-5xl">
                        {{ $landing['pricing_title'] }}
                    </h2>
                    <p class="mt-5 text-base leading-7 text-[#4D3152]/75">
                        {{ $pricingCopy }}
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <article class="rounded-lg border border-[#A78AB9]/28 bg-white/75 p-6 shadow-[0_18px_44px_rgba(59,34,63,0.06)]">
                        <div class="text-sm font-semibold uppercase tracking-[0.18em] text-[#A78AB9]">{{ $landing['pricing_demo_label'] }}</div>
                        <h3 class="mt-4 text-2xl font-semibold text-[#2B1731]">{{ $landing['pricing_demo_title'] }}</h3>
                        <div class="mt-5 text-4xl font-semibold text-[#2B1731]">{{ $demoPrice }}</div>
                        <p class="mt-4 text-sm leading-6 text-[#4D3152]/75">{{ $landing['pricing_demo_copy'] }}</p>
                        <a href="{{ $primaryHref }}" class="mt-6 inline-flex h-11 items-center justify-center rounded-lg bg-[#3B223F] px-5 text-sm font-semibold text-white transition hover:bg-[#2B1731]">
                            {{ $landing['pricing_demo_cta'] }}
                        </a>
                    </article>

                    <article class="rounded-lg border border-[#E7DDC9]/90 bg-white/75 p-6 shadow-[0_18px_44px_rgba(59,34,63,0.06)]">
                        <div class="text-sm font-semibold uppercase tracking-[0.18em] text-[#A78AB9]">{{ $landing['pricing_standard_label'] }}</div>
                        <h3 class="mt-4 text-2xl font-semibold text-[#2B1731]">{{ $landing['pricing_standard_title'] }}</h3>
                        <div class="mt-5 text-4xl font-semibold text-[#2B1731]">{{ $standardPrice }}</div>
                        <p class="mt-4 text-sm leading-6 text-[#4D3152]/75">{{ $landing['pricing_standard_copy'] }}</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="px-5 py-20 sm:px-8 lg:px-10">
            <div class="mx-auto grid max-w-7xl gap-16">
                @foreach ($featureSections as $section)
                    <article id="{{ $section['id'] }}" class="grid gap-8 lg:grid-cols-[0.7fr_1.3fr] lg:items-start">
                        <div class="lg:sticky lg:top-8">
                            <span class="flex h-12 w-12 items-center justify-center rounded-lg bg-[#3B223F] text-white shadow-[0_16px_30px_rgba(59,34,63,0.18)]">
                                <x-ui.icon :name="$section['icon']" class="h-5 w-5" />
                            </span>
                            <h2 class="mt-5 text-3xl font-semibold leading-tight text-[#2B1731] sm:text-4xl">
                                {{ $section['title'] }}
                            </h2>
                            <p class="mt-4 text-base leading-7 text-[#4D3152]/75">
                                {{ $section['copy'] }}
                            </p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-3">
                            @foreach ($section['items'] as $item)
                                <div class="min-h-44 rounded-lg border border-[#E7DDC9]/80 bg-white/70 p-5 shadow-[0_16px_34px_rgba(59,34,63,0.05)]">
                                    <div class="h-1.5 w-16 rounded-full bg-gradient-to-r from-[#3B223F] via-[#A78AB9] to-[#E7DDC9]"></div>
                                    <p class="mt-8 text-lg font-semibold leading-7 text-[#2B1731]">{{ $item }}</p>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="relative px-5 py-20 sm:px-8 lg:px-10">
            <div class="absolute inset-0 overflow-hidden" aria-hidden="true">
                <div class="absolute left-1/2 top-0 h-px w-[86vw] -translate-x-1/2 bg-gradient-to-r from-transparent via-[#A78AB9]/35 to-transparent"></div>
                <div class="absolute -bottom-28 right-[-12rem] h-96 w-96 rounded-full bg-[#DCCFF0]/50 blur-3xl"></div>
            </div>

            <div class="relative mx-auto grid max-w-7xl gap-10 lg:grid-cols-[1fr_0.85fr] lg:items-center">
                <div>
                    <h2 class="max-w-4xl text-3xl font-semibold leading-tight text-[#2B1731] sm:text-5xl">
                        {{ $landing['final_title'] }}
                    </h2>
                    <p class="mt-5 max-w-2xl text-lg leading-8 text-[#4D3152]/75">
                        {{ $landing['final_copy'] }}
                    </p>
                    <a href="{{ $primaryHref }}" class="mt-8 inline-flex h-12 items-center justify-center rounded-lg bg-[#3B223F] px-6 text-sm font-semibold text-white shadow-[0_18px_34px_rgba(59,34,63,0.2)] transition hover:bg-[#2B1731] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2">
                        {{ auth()->check() ? __('app.dashboard') : $landing['final_cta'] }}
                    </a>
                </div>

                <div class="relative min-h-80 overflow-hidden rounded-lg border border-[#E7DDC9]/80 bg-[#FAF8F5] p-6 shadow-[0_22px_54px_rgba(59,34,63,0.08)]">
                    <div class="absolute inset-0 bg-[radial-gradient(circle_at_15%_15%,rgba(167,138,185,0.24),transparent_22%),radial-gradient(circle_at_80%_20%,rgba(231,221,201,0.7),transparent_30%)]" aria-hidden="true"></div>
                    <div class="relative grid h-full min-h-72 grid-cols-5 grid-rows-5 gap-3">
                        @foreach ([0, 2, 5, 6, 11, 13, 17, 18, 22] as $slot)
                            <div class="rounded-md bg-white/75 shadow-xs {{ in_array($slot, [2, 11, 18], true) ? 'col-span-2 bg-[#DCCFF0]/70' : '' }}" style="grid-column-start: {{ ($slot % 5) + 1 }}; grid-row-start: {{ intdiv($slot, 5) + 1 }};"></div>
                        @endforeach
                        <div class="absolute bottom-8 left-8 h-24 w-20 rounded-full border border-[#A78AB9]/25 bg-[#A78AB9]/12"></div>
                        <div class="absolute bottom-12 left-14 h-28 w-3 rounded-full bg-[#A78AB9]/28"></div>
                        <div class="absolute right-10 top-10 h-24 w-24 rounded-full border border-[#E7DDC9]"></div>
                        <div class="absolute right-14 top-14 h-16 w-16 rounded-full bg-[#3B223F]/10"></div>
                    </div>
                </div>
            </div>
        </section>
    </main>
@endsection

@section('publicFooter')
    @php
        $localizedSuffix = app()->getLocale() === 'uk' ? 'ua' : 'en';
        $applicationVersion = $applicationVersion ?? \App\Support\ApplicationVersion::current();
    @endphp

    <footer class="border-t border-[#E7DDC9]/80 bg-[#FAF8F5] px-5 py-6 text-sm text-[#4D3152]/70 sm:px-8 lg:px-10">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <p>
                &copy; {{ now()->year }} {{ __('app.app_name') }}. {{ __('app.app_tagline') }}.
                <span class="whitespace-nowrap">{{ __('app.version') }} {{ $applicationVersion }}</span>
            </p>
            <nav class="flex flex-wrap gap-x-5 gap-y-2 font-semibold" aria-label="{{ __('app.footer_links') }}">
                <a href="{{ route('terms.'.$localizedSuffix) }}" class="text-[#3B223F] transition hover:text-[#2B1731]">
                    {{ __('app.terms_of_service') }}
                </a>
                <a href="{{ route('privacy.'.$localizedSuffix) }}" class="text-[#3B223F] transition hover:text-[#2B1731]">
                    {{ __('app.privacy_policy') }}
                </a>
                <a href="{{ route('changelog.'.$localizedSuffix) }}" class="text-[#3B223F] transition hover:text-[#2B1731]">
                    {{ __('app.whats_new') }}
                </a>
                <a href="{{ route('help.index') }}" class="text-[#3B223F] transition hover:text-[#2B1731]">
                    {{ __('app.help') }}
                </a>
            </nav>
        </div>
    </footer>
@endsection
