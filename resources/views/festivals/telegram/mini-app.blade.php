@extends('layouts.public', ['hideAppFooter' => true, 'disablePublicPwa' => true])

@php
    $hero = $initialData['hero'] ?? null;
    $brandColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $series->brand_color) === 1
        ? $series->brand_color
        : (preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $account->brand_color) === 1 ? $account->brand_color : '#d946ef');
@endphp

@section('title', $series->name)

@push('head')
    <script src="https://telegram.org/js/telegram-web-app.js?59"></script>
@endpush

@section('content')
    <main
        class="min-h-dvh bg-slate-950 text-white"
        data-festival-telegram-mini-app
        data-bootstrap-url="{{ route('public.festival-telegram.bootstrap', [$account->slug, $series->slug]) }}"
        data-action-url="{{ route('public.festival-telegram.action', [$account->slug, $series->slug]) }}"
        data-unlink-url="{{ route('public.festival-telegram.unlink', [$account->slug, $series->slug]) }}"
        data-csrf-token="{{ csrf_token() }}"
        style="--festival-telegram-accent: {{ $brandColor }};"
    >
        <script type="application/json" data-festival-telegram-initial>@json($initialData)</script>
        <script type="application/json" data-festival-telegram-labels>@json($labels)</script>

        <div class="mx-auto max-w-3xl pb-[max(2rem,env(safe-area-inset-bottom))]">
            <header
                class="relative flex overflow-hidden border-b border-white/10 bg-gradient-to-br from-violet-950 via-slate-950 to-fuchsia-950 px-5 pb-6 pt-[max(1.25rem,env(safe-area-inset-top))] {{ $hero ? 'min-h-52 items-end' : '' }}"
                data-festival-telegram-series-hero
                @if ($hero) data-festival-telegram-hero-edition="{{ $hero['edition_id'] }}" @endif
            >
                @if ($hero)
                    <picture class="absolute inset-0">
                        @if ($hero['mobile_url'] && $hero['mobile_url'] !== $hero['desktop_url'])
                            <source media="(max-width: 767px)" srcset="{{ $hero['mobile_url'] }}">
                        @endif
                        <img src="{{ $hero['desktop_url'] }}" alt="{{ $hero['alt'] }}" class="h-full w-full object-cover" loading="eager" decoding="async" fetchpriority="high">
                    </picture>
                    <div class="absolute inset-0 bg-gradient-to-b from-slate-950/25 via-slate-950/45 to-slate-950"></div>
                    <div class="absolute -right-16 -top-20 h-52 w-52 rounded-full opacity-30 blur-3xl" style="background-color: var(--festival-telegram-accent);"></div>
                @else
                    <div class="absolute -right-16 -top-20 h-52 w-52 rounded-full opacity-20 blur-3xl" style="background-color: var(--festival-telegram-accent);"></div>
                @endif
                <div class="relative z-10">
                    <p class="festival-telegram-accent-text text-xs font-semibold uppercase tracking-[0.22em]">{{ __('app.festival_telegram_companion') }}</p>
                    <h1 class="mt-3 text-3xl font-semibold leading-tight">{{ $series->name }}</h1>
                    @if ($hero)
                        <p class="mt-2 text-sm font-semibold text-white/90">{{ $hero['title'] }}</p>
                    @endif
                </div>
            </header>

            <div class="space-y-5 px-4 py-5 sm:px-5">
                <div class="hidden rounded-2xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100" data-festival-telegram-error role="alert"></div>

                <section class="rounded-2xl border border-white/10 bg-white/[0.06] p-5 shadow-2xl shadow-black/20 backdrop-blur" data-festival-telegram-authorization>
                    <div class="flex items-start gap-4">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-fuchsia-500/20 text-fuchsia-200">
                            <x-ui.icon name="shield-check" class="h-6 w-6" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="font-semibold" data-festival-telegram-auth-title>{{ __('app.festival_telegram_authorization_title') }}</h2>
                            <p class="mt-1 text-sm leading-6 text-slate-300" data-festival-telegram-auth-copy>{{ __('app.festival_telegram_authorization_help') }}</p>
                            <button type="button" class="festival-telegram-accent-button mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white transition" data-festival-telegram-contact>
                                <x-ui.icon name="phone" class="h-4 w-4" />
                                {{ __('app.festival_telegram_share_phone') }}
                            </button>
                        </div>
                    </div>
                </section>

                <nav class="hidden -mx-1 overflow-x-auto px-1 pb-1" data-festival-telegram-nav aria-label="{{ __('app.festival_navigation') }}">
                    <div class="flex min-w-max gap-2">
                        @foreach ([
                            'calendar' => __('app.festival_telegram_calendar'),
                            'mine' => __('app.festival_telegram_my_festival'),
                            'tickets' => __('app.festival_telegram_my_tickets'),
                            'statistics' => __('app.statistics'),
                            'preferences' => __('app.notification_preferences'),
                            'contacts' => __('app.contacts'),
                        ] as $tab => $label)
                            <button type="button" class="festival-telegram-tab rounded-full border border-white/10 bg-white/[0.06] px-4 py-2 text-sm font-semibold text-slate-300 transition" data-festival-telegram-tab="{{ $tab }}" data-active="{{ $tab === 'calendar' ? 'true' : 'false' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                </nav>

                <section data-festival-telegram-panel="calendar">
                    <div class="space-y-3" data-festival-telegram-editions></div>
                    <div class="hidden" data-festival-telegram-edition-detail></div>
                </section>
                <section class="hidden" data-festival-telegram-panel="mine"><div class="space-y-4" data-festival-telegram-mine></div></section>
                <section class="hidden" data-festival-telegram-panel="tickets"><div class="space-y-4" data-festival-telegram-tickets></div></section>
                <section class="hidden" data-festival-telegram-panel="statistics"><div class="space-y-4" data-festival-telegram-statistics></div></section>
                <section class="hidden" data-festival-telegram-panel="preferences"><div class="space-y-4" data-festival-telegram-preferences></div></section>
                <section class="hidden" data-festival-telegram-panel="contacts"><div class="space-y-4" data-festival-telegram-contacts></div></section>
            </div>
        </div>
    </main>
@endsection
