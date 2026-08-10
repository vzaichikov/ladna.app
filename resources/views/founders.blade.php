@extends('layouts.public')

@section('title', __('founders.meta.title'))

@push('head')
    <meta name="description" content="{{ __('founders.meta.description') }}">
@endpush

@section('content')
    @php
        $founders = __('founders');
        $foundersProgram = $foundersProgram ?? [];
        $remainingStudios = (int) ($foundersProgram['remaining_studios'] ?? 0);
        $supportUrl = (string) ($foundersProgram['support_url'] ?? '');
        $publicPricing = $publicPricing ?? null;
        $trustedStudiosAvailable = $trustedStudiosAvailable ?? false;
        $currentLocale = app()->getLocale() === 'en' ? 'en' : 'uk';
        $homeHref = $currentLocale === 'en' ? route('home.en') : route('home');
        $featuresHref = $currentLocale === 'en' ? route('features.en') : route('features');
        $loginHref = $currentLocale === 'en' ? route('login.en') : route('login');
        $headerAuthHref = auth()->check() ? route('dashboard.index') : $loginHref;
        $headerAuthLabel = auth()->check() ? __('app.dashboard') : __('app.login');
        $pricingHref = $publicPricing ? $homeHref.'#pricing' : null;
        $localeLinks = [
            'uk' => ['label' => 'UA', 'href' => route('founders')],
            'en' => ['label' => 'EN', 'href' => route('founders.en')],
        ];
        $benefitIcons = ['wallet', 'message-circle', 'ticket', 'rocket', 'sparkles', 'users'];
    @endphp

    <main class="overflow-hidden bg-[#FAF8F5] text-[#2B2B2F]">
        <x-marketing.founders-banner :program="$foundersProgram" href="#join-founders" />

        <section class="relative px-5 pb-16 pt-5 sm:px-8 lg:px-10 lg:pb-20">
            <div class="absolute inset-0 overflow-hidden" aria-hidden="true">
                <div class="absolute left-[-9rem] top-[-11rem] h-[30rem] w-[30rem] rounded-full bg-[#E7DDC9]/60 blur-3xl"></div>
                <div class="absolute right-[-14rem] top-8 h-[38rem] w-[38rem] rounded-full bg-[#DCCFF0]/52 blur-3xl"></div>
                <div class="absolute bottom-[-10rem] left-[30%] h-[24rem] w-[34rem] rounded-full bg-white/80 blur-3xl"></div>
                <div class="absolute inset-x-0 top-24 h-px bg-gradient-to-r from-transparent via-[#A78AB9]/30 to-transparent"></div>
            </div>

            <div class="relative mx-auto max-w-7xl">
                <x-marketing.header
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

                <div class="grid gap-10 pb-4 pt-12 lg:min-h-[42rem] lg:grid-cols-[0.9fr_1.1fr] lg:items-center lg:pt-8">
                    <div class="max-w-3xl">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-[#7F6189]">{{ $founders['hero']['eyebrow'] }}</p>
                        <h1 class="mt-4 text-4xl font-semibold leading-[1.04] text-[#2B1731] sm:text-6xl lg:text-7xl">
                            {{ $founders['hero']['title'] }}
                        </h1>
                        <p class="mt-6 max-w-2xl text-lg leading-8 text-[#4D3152]/80 sm:text-xl sm:leading-9">
                            {{ trans_choice('founders.hero.copy', $remainingStudios, ['count' => $remainingStudios]) }}
                        </p>

                        <div class="mt-7 inline-flex items-center gap-2 rounded-full border border-[#A78AB9]/35 bg-white/82 px-4 py-2 text-sm font-semibold text-[#3B223F] shadow-xs">
                            <span class="h-2.5 w-2.5 rounded-full bg-[#A78AB9]"></span>
                            {{ trans_choice('founders.hero.spots', $remainingStudios, ['count' => $remainingStudios]) }}
                        </div>

                        <div class="mt-8 flex flex-col items-start gap-3 sm:flex-row sm:items-center">
                            <a href="{{ $supportUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-12 items-center justify-center gap-2 rounded-lg bg-[#3B223F] px-6 text-sm font-semibold text-white shadow-[0_18px_34px_rgba(59,34,63,0.2)] transition hover:bg-[#2B1731] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2">
                                <x-ui.icon name="send" class="h-4 w-4" />
                                {{ $founders['hero']['cta'] }}
                            </a>
                            <p class="text-sm leading-6 text-[#4D3152]/68">{{ $founders['hero']['cta_note'] }}</p>
                        </div>
                    </div>

                    <div class="relative min-h-[31rem] sm:min-h-[37rem] lg:min-h-[42rem]" aria-hidden="true">
                        <div class="absolute inset-0">
                            <div class="absolute left-1/2 top-1/2 h-[29rem] w-[29rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#E7DDC9]/45 sm:h-[35rem] sm:w-[35rem]"></div>
                            <div class="absolute right-3 top-10 h-64 w-64 rounded-full border-2 border-[#A78AB9]/24 sm:right-8 sm:h-80 sm:w-80"></div>
                            <div class="absolute bottom-12 left-4 h-64 w-64 rounded-full border border-[#E7DDC9] sm:left-10 sm:h-72 sm:w-72"></div>
                            <div class="absolute left-0 top-24 grid grid-cols-3 gap-2 opacity-80 sm:left-8">
                                @foreach ([true, false, true, false, true, false, false, true, false] as $isActive)
                                    <span class="h-7 w-9 rounded-md {{ $isActive ? 'bg-[#C7B4D3]/62' : 'bg-white/76' }}"></span>
                                @endforeach
                            </div>
                            <div class="absolute bottom-6 left-1/2 h-24 w-[76%] -translate-x-1/2 rounded-[50%] bg-[#3B223F]/10 blur-2xl"></div>
                        </div>

                        <img
                            src="{{ asset('assets/brand/founders/ladna-founders-community.png') }}"
                            alt="{{ $founders['hero']['image_alt'] }}"
                            width="1536"
                            height="1024"
                            loading="eager"
                            fetchpriority="high"
                            class="absolute bottom-0 left-1/2 h-full w-auto max-w-none -translate-x-1/2 object-contain drop-shadow-[0_28px_54px_rgba(59,34,63,0.17)]"
                        >
                    </div>
                </div>
            </div>
        </section>

        <section class="relative border-y border-[#E7DDC9]/80 bg-white/62 px-5 py-20 sm:px-8 lg:px-10 lg:py-24">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_12%_18%,rgba(167,138,185,0.14),transparent_27%),radial-gradient(circle_at_87%_32%,rgba(231,221,201,0.55),transparent_30%)]" aria-hidden="true"></div>
            <div class="relative mx-auto max-w-7xl">
                <div class="max-w-3xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-[#7F6189]">{{ $founders['why']['eyebrow'] }}</p>
                    <h2 class="mt-3 text-3xl font-semibold leading-tight text-[#2B1731] sm:text-5xl">{{ $founders['why']['title'] }}</h2>
                    <p class="mt-5 text-base leading-7 text-[#4D3152]/78 sm:text-lg sm:leading-8">{{ $founders['why']['copy'] }}</p>
                </div>

                <div class="mt-10 grid gap-3 md:grid-cols-3">
                    @foreach ($founders['expectations'] as $expectation)
                        <article class="rounded-lg border border-[#E7DDC9]/85 bg-[#FAF8F5]/92 p-5 shadow-[0_18px_44px_rgba(59,34,63,0.06)]">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-[#DCCFF0] text-[#3B223F]">
                                <x-ui.icon name="check" class="h-5 w-5" />
                            </span>
                            <h3 class="mt-5 text-lg font-semibold leading-7 text-[#2B1731]">{{ $expectation['title'] }}</h3>
                            <p class="mt-3 text-sm leading-6 text-[#4D3152]/75">{{ $expectation['copy'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="relative overflow-hidden bg-[#F2ECF5] px-5 py-20 sm:px-8 lg:px-10 lg:py-24">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_14%_12%,rgba(167,138,185,0.2),transparent_28%),radial-gradient(circle_at_88%_78%,rgba(231,221,201,0.68),transparent_32%)]" aria-hidden="true"></div>
            <div class="relative mx-auto max-w-7xl">
                <div class="max-w-3xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-[#7F6189]">{{ $founders['benefits']['eyebrow'] }}</p>
                    <h2 class="mt-3 text-3xl font-semibold leading-tight text-[#2B1731] sm:text-5xl">{{ $founders['benefits']['title'] }}</h2>
                </div>

                <div class="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($founders['benefits']['items'] as $benefit)
                        <article class="flex min-h-64 flex-col rounded-lg border border-white/85 bg-white/82 p-5 shadow-[0_18px_46px_rgba(59,34,63,0.07)]">
                            <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-[#3B223F] text-white shadow-[0_12px_26px_rgba(59,34,63,0.18)]">
                                <x-ui.icon :name="$benefitIcons[$loop->index]" class="h-5 w-5" />
                            </span>
                            <h3 class="mt-5 text-xl font-semibold leading-7 text-[#2B1731]">{{ $benefit['title'] }}</h3>
                            <p class="mt-3 text-sm leading-6 text-[#4D3152]/75">{{ $benefit['copy'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="join-founders" class="relative scroll-mt-4 overflow-hidden bg-[#2B1731] px-5 py-20 text-white sm:px-8 lg:px-10 lg:py-24">
            <div class="absolute inset-0" aria-hidden="true">
                <div class="absolute left-[-10rem] top-[-12rem] h-96 w-96 rounded-full bg-[#A78AB9]/28 blur-3xl"></div>
                <div class="absolute bottom-[-12rem] right-[-10rem] h-[31rem] w-[31rem] rounded-full bg-[#E7DDC9]/18 blur-3xl"></div>
            </div>
            <div class="relative mx-auto max-w-4xl text-center">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-[#C7B4D3]">{{ $founders['join']['eyebrow'] }}</p>
                <h2 class="mt-3 text-3xl font-semibold leading-tight sm:text-5xl">{{ $founders['join']['title'] }}</h2>
                <p class="mx-auto mt-5 max-w-2xl text-base leading-7 text-white/74 sm:text-lg sm:leading-8">{{ $founders['join']['copy'] }}</p>
                <a href="{{ $supportUrl }}" target="_blank" rel="noopener noreferrer" class="mt-8 inline-flex h-12 items-center justify-center gap-2 rounded-lg bg-[#FAF8F5] px-6 text-sm font-semibold text-[#3B223F] shadow-[0_18px_34px_rgba(0,0,0,0.18)] transition hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C7B4D3] focus-visible:ring-offset-2 focus-visible:ring-offset-[#2B1731]">
                    <x-ui.icon name="send" class="h-4 w-4" />
                    {{ $founders['join']['cta'] }}
                </a>
                <p class="mt-4 text-sm leading-6 text-white/62">{{ $founders['join']['note'] }}</p>
            </div>
        </section>
    </main>
@endsection

@section('publicFooter')
    <x-marketing.footer />
@endsection
