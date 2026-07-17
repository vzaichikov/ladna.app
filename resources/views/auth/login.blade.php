@extends('layouts.public')

@section('title', __('app.login').' - '.__('app.app_name'))

@section('content')
    @php
        $currentLoginLocale = app()->getLocale() === 'en' ? 'en' : 'uk';
        $demoLogin = $demoLogin ?? false;
        $prefillEmail = $prefillEmail ?? null;
        $prefillPassword = $prefillPassword ?? null;
        $homeHref = $currentLoginLocale === 'en' ? route('home.en') : route('home');
        $loginLocales = $demoLogin
            ? [
                'uk' => ['label' => 'UA', 'href' => route('demo.login', ['locale' => 'uk'])],
                'en' => ['label' => 'EN', 'href' => route('demo.login', ['locale' => 'en'])],
            ]
            : [
                'uk' => ['label' => 'UA', 'href' => route('login')],
                'en' => ['label' => 'EN', 'href' => route('login.en')],
            ];
    @endphp

    <main class="relative min-h-screen overflow-hidden bg-[#FAF8F5] text-[#2B2B2F]">
        <div class="absolute inset-0 overflow-hidden" aria-hidden="true">
            <div class="absolute left-[-10rem] top-[-12rem] h-[30rem] w-[30rem] rounded-full bg-[#E7DDC9]/62 blur-3xl"></div>
            <div class="absolute right-[-14rem] top-20 h-[34rem] w-[34rem] rounded-full bg-[#DCCFF0]/50 blur-3xl"></div>
            <div class="absolute bottom-[-12rem] left-[34%] h-[26rem] w-[42rem] rounded-full bg-white/72 blur-3xl"></div>
            <div class="absolute inset-x-0 top-24 h-px bg-gradient-to-r from-transparent via-[#A78AB9]/26 to-transparent"></div>
        </div>

        <div class="relative mx-auto flex min-h-screen max-w-7xl flex-col px-5 py-5 sm:px-8 lg:px-10">
            <header class="flex items-center justify-between gap-4">
                <a href="{{ $homeHref }}" class="inline-flex items-center gap-3 text-[#2B1731]">
                    <x-ui.app-logo
                        mark-class="h-10 w-10"
                        text-class="text-[#2B1731]"
                    />
                </a>

                <nav class="inline-flex h-10 items-center rounded-lg border border-[#A78AB9]/30 bg-white/70 p-1 shadow-xs" aria-label="{{ __('app.default_language') }}">
                    @foreach ($loginLocales as $locale => $localeOption)
                        <a
                            href="{{ $localeOption['href'] }}"
                            @class([
                                'flex h-8 min-w-9 items-center justify-center rounded-md px-2 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2',
                                'bg-[#3B223F] text-white shadow-[0_8px_18px_rgba(59,34,63,0.16)]' => $currentLoginLocale === $locale,
                                'text-[#4D3152] hover:bg-[#DCCFF0]/45 hover:text-[#2B1731]' => $currentLoginLocale !== $locale,
                            ])
                        >
                            {{ $localeOption['label'] }}
                        </a>
                    @endforeach
                </nav>
            </header>

            <section class="grid flex-1 items-center gap-10 py-10 lg:grid-cols-[0.94fr_1.06fr] lg:py-6">
                <div class="max-w-xl">
                    <h1 class="text-4xl font-semibold leading-[1.04] text-[#2B1731] sm:text-5xl">
                        {{ __('app.auth_welcome_back') }}
                    </h1>
                    <p class="mt-5 max-w-lg text-lg leading-8 text-[#4D3152]/76">
                        {{ __('app.auth_intro') }}
                    </p>

                    <form method="POST" action="{{ route('login') }}" class="mt-8 max-w-md rounded-lg border border-[#E7DDC9]/80 bg-white/82 p-5 shadow-[0_22px_54px_rgba(59,34,63,0.09)] backdrop-blur sm:p-6">
                        @csrf

                        @if ($demoLogin)
                            <input name="remember" type="hidden" value="0">
                        @endif

                        <div class="mb-6">
                            <h2 class="text-lg font-semibold leading-7 text-[#2B1731]">{{ __('app.staff_owner_login_heading') }}</h2>
                        </div>

                        <div class="space-y-5">
                            <label class="block">
                                <span class="block text-xs font-semibold uppercase text-[#4D3152]/72">{{ __('app.email') }}</span>
                                <input name="email" type="email" value="{{ old('email', $prefillEmail) }}" required autofocus autocomplete="email" placeholder="{{ __('app.auth_email_placeholder') }}" class="mt-2 h-12 w-full rounded-lg border border-[#E7DDC9] bg-[#FAF8F5]/70 px-4 text-sm font-semibold text-[#2B2B2F] shadow-xs outline-none transition placeholder:font-medium placeholder:text-[#4D3152]/38 focus:border-[#A78AB9] focus:bg-white focus:ring-3 focus:ring-[#DCCFF0]/65">
                                @error('email')
                                    <span class="mt-2 block text-xs font-semibold text-rose-600">{{ $message }}</span>
                                @enderror
                            </label>

                            <label class="block">
                                <span class="block text-xs font-semibold uppercase text-[#4D3152]/72">{{ __('app.password') }}</span>
                                <input name="password" type="password" value="{{ $prefillPassword }}" required autocomplete="current-password" class="mt-2 h-12 w-full rounded-lg border border-[#E7DDC9] bg-[#FAF8F5]/70 px-4 text-sm font-semibold text-[#2B2B2F] shadow-xs outline-none transition focus:border-[#A78AB9] focus:bg-white focus:ring-3 focus:ring-[#DCCFF0]/65">
                                @error('password')
                                    <span class="mt-2 block text-xs font-semibold text-rose-600">{{ $message }}</span>
                                @enderror
                            </label>

                            @unless ($demoLogin)
                                <label class="inline-flex items-center gap-2 text-xs font-semibold text-[#4D3152]/78">
                                    <input name="remember" type="checkbox" value="1" @checked(old('remember', '1')) class="h-4 w-4 rounded border-[#A78AB9]/45 text-[#3B223F] focus:ring-[#A78AB9]">
                                    {{ __('app.remember_me') }}
                                </label>
                            @endunless

                            <button type="submit" class="inline-flex h-12 w-full items-center justify-center rounded-lg bg-[#3B223F] px-4 text-sm font-semibold text-white shadow-[0_16px_32px_rgba(59,34,63,0.22)] transition hover:bg-[#2B1731] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2">
                                {{ __('app.login') }}
                            </button>

                            <a href="{{ route('customer.login') }}" class="inline-flex w-full items-center justify-center text-sm font-semibold text-[#3B223F] transition hover:text-[#2B1731] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2">
                                {{ __('app.customer_login_cta') }}
                            </a>
                        </div>
                    </form>
                </div>

                <div class="relative min-h-[28rem] lg:min-h-[42rem]" aria-hidden="true">
                    <div class="absolute inset-0">
                        <div class="absolute left-1/2 top-1/2 h-[28rem] w-[28rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#E7DDC9]/36 lg:h-[34rem] lg:w-[34rem]"></div>
                        <div class="absolute right-4 top-8 h-72 w-72 rounded-full border-2 border-[#A78AB9]/24 lg:right-10"></div>
                        <div class="absolute bottom-10 left-6 h-72 w-72 rounded-full border border-[#E7DDC9]/80"></div>
                        <div class="absolute left-4 top-20 grid grid-cols-4 gap-2 opacity-75 lg:left-12">
                            @foreach ([true, false, true, false, false, true, false, true, true, false, false, true] as $isActive)
                                <span class="h-7 w-9 rounded-md {{ $isActive ? 'bg-[#C7B4D3]/60' : 'bg-white/72' }}"></span>
                            @endforeach
                        </div>
                        <div class="absolute bottom-12 left-1/2 h-28 w-[68%] -translate-x-1/2 rounded-[50%] bg-[#3B223F]/10 blur-2xl"></div>
                        <svg class="absolute right-4 top-4 h-80 w-80 text-[#A78AB9]/35 lg:right-12" viewBox="0 0 320 320" fill="none">
                            <path d="M36 218C88 76 214 48 284 101" stroke="currentColor" stroke-linecap="round" stroke-width="3" />
                            <path d="M80 265C126 223 207 214 258 246" stroke="currentColor" stroke-linecap="round" stroke-width="2" />
                            <circle cx="284" cy="101" r="6" fill="currentColor" />
                        </svg>
                        <svg class="absolute bottom-24 right-2 h-28 w-28 text-[#3B223F]/10" viewBox="0 0 112 112" fill="none">
                            <circle cx="54" cy="22" r="7" fill="currentColor" />
                            <path d="M52 32C44 42 35 52 20 59M54 35C67 41 78 48 91 58M47 52C40 68 34 79 25 92M56 53C68 68 75 79 88 90" stroke="currentColor" stroke-linecap="round" stroke-width="8" />
                        </svg>
                    </div>

                    <img
                        src="{{ asset('assets/brand/landing/ladna-landing-mascot-cutout.png') }}"
                        alt=""
                        class="absolute bottom-0 left-1/2 h-full max-h-[42rem] w-auto max-w-none -translate-x-1/2 object-contain drop-shadow-[0_28px_54px_rgba(59,34,63,0.18)]"
                    >
                </div>
            </section>
        </div>
    </main>
@endsection
