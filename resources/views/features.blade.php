@extends('layouts.public')

@section('title', __('features.page_title'))

@push('head')
    <meta name="description" content="{{ __('features.meta_description') }}">
@endpush

@section('content')
    @php
        $features = __('features');
        $demoAvailable = $demoAvailable ?? false;
        $publicPricing = $publicPricing ?? null;
        $publicOwnerOnboardingAvailable = $publicOwnerOnboardingAvailable ?? false;
        $trustedStudiosAvailable = $trustedStudiosAvailable ?? false;
        $currentLocale = app()->getLocale() === 'en' ? 'en' : 'uk';
        $homeHref = $currentLocale === 'en' ? route('home.en') : route('home');
        $featuresHref = $currentLocale === 'en' ? route('features.en') : route('features');
        $loginHref = $currentLocale === 'en' ? route('login.en') : route('login');
        $headerAuthHref = auth()->check() ? route('dashboard.index') : $loginHref;
        $headerAuthLabel = auth()->check() ? __('app.dashboard') : __('app.login');
        $showPrimaryCta = auth()->check() || $publicOwnerOnboardingAvailable || $demoAvailable;
        $primaryHref = match (true) {
            auth()->check() => route('dashboard.index'),
            $publicOwnerOnboardingAvailable => route('register'),
            $demoAvailable => route('demo.login', [], false),
            default => null,
        };
        $primaryLabel = match (true) {
            auth()->check() => __('app.dashboard'),
            $publicOwnerOnboardingAvailable => __('app.onboarding.registration_cta'),
            default => __('app.landing.final_cta'),
        };
        $showSecondaryDemoCta = ! auth()->check() && $publicOwnerOnboardingAvailable && $demoAvailable;
        $pricingHref = $publicPricing ? $homeHref.'#pricing' : null;
        $localeLinks = [
            'uk' => ['label' => 'UA', 'href' => route('features')],
            'en' => ['label' => 'EN', 'href' => route('features.en')],
        ];
        $sectionVisuals = [
            'today' => ['icon' => 'layout-dashboard', 'image' => 'studio-dashboard.png'],
            'schedule' => ['icon' => 'calendar-days', 'image' => 'weekly-schedule.png'],
            'clients' => ['icon' => 'heart-handshake', 'image' => 'public-schedule.png'],
            'passes' => ['icon' => 'ticket-check', 'image' => 'active-passes.png'],
            'team' => ['icon' => 'users-round', 'image' => 'trainer-permissions.png'],
            'money' => ['icon' => 'chart-no-axes-combined', 'image' => 'payments-period.png'],
        ];
        $optionalIcons = ['credit-card', 'message-circle', 'bot', 'plug', 'video', 'smartphone'];
    @endphp

    <main class="overflow-hidden bg-[#FAF8F5] text-[#2B2B2F]">
        <section class="relative px-5 pb-16 pt-5 sm:px-8 lg:px-10 lg:pb-20">
            <div class="absolute inset-0 overflow-hidden" aria-hidden="true">
                <div class="absolute left-[-8rem] top-[-10rem] h-96 w-96 rounded-full bg-[#E7DDC9]/55 blur-3xl"></div>
                <div class="absolute right-[-12rem] top-12 h-[34rem] w-[34rem] rounded-full bg-[#DCCFF0]/45 blur-3xl"></div>
                <div class="absolute inset-x-0 top-24 h-px bg-gradient-to-r from-transparent via-[#A78AB9]/30 to-transparent"></div>
            </div>

            <div class="relative mx-auto max-w-7xl">
                <x-marketing.header
                    active-page="features"
                    :auth-href="$headerAuthHref"
                    :auth-label="$headerAuthLabel"
                    :current-locale="$currentLocale"
                    :features-href="$featuresHref"
                    :flow-href="$homeHref.'#flow'"
                    :home-href="$homeHref"
                    :locale-links="$localeLinks"
                    :pricing-href="$pricingHref"
                    :studios-href="$trustedStudiosAvailable ? $homeHref.'#trusted-studios' : null"
                />

                <div class="mx-auto max-w-4xl pb-8 pt-20 text-center sm:pt-24">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-[#7F6189]">{{ $features['hero']['eyebrow'] }}</p>
                    <h1 class="mt-4 text-4xl font-semibold leading-[1.06] text-[#2B1731] sm:text-6xl lg:text-7xl">
                        {{ $features['hero']['title'] }}
                    </h1>
                    <p class="mx-auto mt-6 max-w-3xl text-lg leading-8 text-[#4D3152]/80 sm:text-xl sm:leading-9">
                        {{ $features['hero']['copy'] }}
                    </p>

                    <x-marketing.cta-buttons
                        class="mt-8 justify-center"
                        :demo-href="route('demo.login', [], false)"
                        :demo-label="__('app.landing.final_cta')"
                        :pricing-href="$pricingHref"
                        :pricing-label="$features['cta']['pricing']"
                        :primary-href="$primaryHref"
                        :primary-label="$primaryLabel"
                        :show-demo="$showSecondaryDemoCta"
                        :show-primary="$showPrimaryCta"
                    />
                </div>

                <nav class="mx-auto mt-8 grid max-w-5xl gap-2 sm:grid-cols-2 lg:grid-cols-3" aria-label="{{ $features['jump_navigation'] }}">
                    @foreach ($features['sections'] as $sectionId => $section)
                        <a href="#{{ $sectionId }}" class="group flex items-center gap-3 rounded-lg border border-[#E7DDC9]/80 bg-white/75 px-4 py-3 text-sm font-semibold text-[#3B223F] shadow-xs transition hover:-translate-y-0.5 hover:border-[#A78AB9]/55 hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#DCCFF0]/55 text-[#3B223F] transition group-hover:bg-[#3B223F] group-hover:text-white">
                                <x-ui.icon :name="$sectionVisuals[$sectionId]['icon']" class="h-4 w-4" />
                            </span>
                            <span>{{ $section['short_title'] }}</span>
                        </a>
                    @endforeach
                </nav>
            </div>
        </section>

        <div class="border-y border-[#E7DDC9]/80 bg-white/55">
            @foreach ($features['sections'] as $sectionId => $section)
                <section id="{{ $sectionId }}" class="scroll-mt-6 border-b border-[#E7DDC9]/70 px-5 py-18 last:border-b-0 sm:px-8 lg:px-10 lg:py-24">
                    <div class="mx-auto grid max-w-7xl gap-10 lg:grid-cols-2 lg:items-center lg:gap-16">
                        <div @class(['lg:order-2' => $loop->even])>
                            <span class="flex h-12 w-12 items-center justify-center rounded-lg bg-[#3B223F] text-white shadow-[0_16px_30px_rgba(59,34,63,0.18)]">
                                <x-ui.icon :name="$sectionVisuals[$sectionId]['icon']" class="h-5 w-5" />
                            </span>
                            <h2 class="mt-5 text-3xl font-semibold leading-tight text-[#2B1731] sm:text-5xl">
                                {{ $section['title'] }}
                            </h2>
                            <p class="mt-5 text-base leading-7 text-[#4D3152]/78 sm:text-lg sm:leading-8">
                                {{ $section['copy'] }}
                            </p>
                            <ul class="mt-7 grid gap-3">
                                @foreach ($section['items'] as $item)
                                    <li class="flex gap-3 text-sm leading-6 text-[#4D3152]/82 sm:text-base sm:leading-7">
                                        <span class="mt-1 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[#DCCFF0]/75 text-[#3B223F]">
                                            <x-ui.icon name="check" class="h-3.5 w-3.5" />
                                        </span>
                                        <span>{{ $item }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <figure @class(['lg:order-1' => $loop->even])>
                            <div class="overflow-hidden rounded-xl border border-[#E7DDC9] bg-white p-2 shadow-[0_24px_70px_rgba(59,34,63,0.12)] sm:p-3">
                                <img
                                    src="{{ asset('assets/help/screenshots/'.$sectionVisuals[$sectionId]['image']) }}"
                                    alt="{{ $section['image_alt'] }}"
                                    width="1440"
                                    height="1000"
                                    loading="{{ $loop->first ? 'eager' : 'lazy' }}"
                                    class="h-auto w-full rounded-lg object-contain"
                                >
                            </div>
                        </figure>
                    </div>
                </section>
            @endforeach
        </div>

        <section class="relative overflow-hidden bg-[#F2ECF5] px-5 py-20 sm:px-8 lg:px-10 lg:py-24">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_12%_18%,rgba(167,138,185,0.22),transparent_30%),radial-gradient(circle_at_88%_76%,rgba(231,221,201,0.72),transparent_32%)]" aria-hidden="true"></div>
            <div class="relative mx-auto max-w-7xl">
                <div class="max-w-3xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-[#7F6189]">{{ $features['optional']['eyebrow'] }}</p>
                    <h2 class="mt-3 text-3xl font-semibold leading-tight text-[#2B1731] sm:text-5xl">{{ $features['optional']['title'] }}</h2>
                    <p class="mt-5 text-base leading-7 text-[#4D3152]/78 sm:text-lg sm:leading-8">{{ $features['optional']['copy'] }}</p>
                </div>

                <div class="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($features['optional']['items'] as $item)
                        <article class="rounded-lg border border-white/80 bg-white/78 p-5 shadow-[0_16px_38px_rgba(59,34,63,0.06)]">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-[#3B223F] text-white">
                                <x-ui.icon :name="$optionalIcons[$loop->index]" class="h-5 w-5" />
                            </span>
                            <h3 class="mt-5 text-lg font-semibold text-[#2B1731]">{{ $item['title'] }}</h3>
                            <p class="mt-3 text-sm leading-6 text-[#4D3152]/75">{{ $item['copy'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="relative overflow-hidden bg-[#2B1731] px-5 py-20 text-white sm:px-8 lg:px-10 lg:py-24">
            <div class="absolute inset-0" aria-hidden="true">
                <div class="absolute left-[-10rem] top-[-12rem] h-96 w-96 rounded-full bg-[#A78AB9]/25 blur-3xl"></div>
                <div class="absolute bottom-[-11rem] right-[-10rem] h-[30rem] w-[30rem] rounded-full bg-[#E7DDC9]/18 blur-3xl"></div>
            </div>
            <div class="relative mx-auto max-w-4xl text-center">
                <h2 class="text-3xl font-semibold leading-tight sm:text-5xl">{{ $features['final']['title'] }}</h2>
                <p class="mx-auto mt-5 max-w-2xl text-base leading-7 text-white/72 sm:text-lg sm:leading-8">{{ $features['final']['copy'] }}</p>
                <x-marketing.cta-buttons
                    class="mt-8 justify-center"
                    :demo-href="route('demo.login', [], false)"
                    :demo-label="__('app.landing.final_cta')"
                    :on-dark="true"
                    :pricing-href="$pricingHref"
                    :pricing-label="$features['cta']['pricing']"
                    :primary-href="$primaryHref"
                    :primary-label="$primaryLabel"
                    :show-demo="$showSecondaryDemoCta"
                    :show-primary="$showPrimaryCta"
                />
            </div>
        </section>
    </main>
@endsection

@section('publicFooter')
    <x-marketing.footer />
@endsection
