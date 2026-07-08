@extends('layouts.public')

@section('title', __('app.customer_studio_login_title').' - '.__('app.app_name'))

@section('publicFooter')
    <x-ui.powered-footer class="mx-auto max-w-6xl bg-[#FAF8F5] px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    @php
        $studios = collect($studios ?? []);
    @endphp

    <main class="relative min-h-[calc(100vh-8rem)] overflow-hidden bg-[#FAF8F5] text-[#2B2B2F]">
        <div class="absolute inset-0 overflow-hidden" aria-hidden="true">
            <div class="absolute left-[-10rem] top-[-12rem] h-[30rem] w-[30rem] rounded-full bg-[#E7DDC9]/62 blur-3xl"></div>
            <div class="absolute right-[-14rem] top-12 h-[34rem] w-[34rem] rounded-full bg-[#DCCFF0]/50 blur-3xl"></div>
            <div class="absolute bottom-[-12rem] left-[34%] h-[26rem] w-[42rem] rounded-full bg-white/72 blur-3xl"></div>
            <div class="absolute inset-x-0 top-24 h-px bg-gradient-to-r from-transparent via-[#A78AB9]/26 to-transparent"></div>
        </div>

        <section class="relative mx-auto max-w-7xl px-5 py-5 pb-10 sm:px-8 lg:px-10">
            <header class="flex items-center justify-between gap-4">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 text-[#2B1731]">
                    <x-ui.app-logo
                        mark-class="h-10 w-10"
                        text-class="text-[#2B1731]"
                    />
                </a>

                <a href="{{ route('login') }}" class="inline-flex h-10 items-center justify-center rounded-lg border border-[#A78AB9]/30 bg-white/70 px-4 text-sm font-semibold text-[#3B223F] shadow-xs transition hover:border-[#A78AB9]/60 hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2">
                    {{ __('app.staff_owner_login_cta') }}
                </a>
            </header>

            <div class="grid gap-8 py-10 lg:grid-cols-[0.92fr_1.08fr] lg:items-center lg:py-8">
                <div class="max-w-2xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-[#A78AB9]">{{ __('app.customer_studio_login_eyebrow') }}</p>
                    <h1 class="mt-4 text-4xl font-semibold leading-[1.04] text-[#2B1731] sm:text-6xl">
                        {{ __('app.customer_studio_login_title') }}
                    </h1>
                    <p class="mt-5 max-w-xl text-lg leading-8 text-[#4D3152]/76">
                        {{ __('app.customer_studio_login_copy') }}
                    </p>

                    <div
                        class="relative mt-8 max-w-xl"
                        data-studio-login-picker
                    >
                        <label for="customer-studio-search" class="block text-sm font-semibold text-[#4D3152]">
                            {{ __('app.customer_studio_search_label') }}
                        </label>
                        <div class="relative mt-2">
                            <x-ui.icon name="search" class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-[#A78AB9]" />
                            <input
                                id="customer-studio-search"
                                type="search"
                                autocomplete="off"
                                data-studio-login-picker-input
                                aria-controls="customer-studio-search-results"
                                aria-expanded="false"
                                placeholder="{{ __('app.customer_studio_search_placeholder') }}"
                                class="h-12 w-full rounded-lg border border-[#E7DDC9] bg-white/86 py-3 pl-12 pr-4 text-base font-semibold text-[#2B2B2F] shadow-[0_18px_44px_rgba(59,34,63,0.08)] outline-none transition placeholder:font-medium placeholder:text-[#4D3152]/42 focus:border-[#A78AB9] focus:bg-white focus:ring-3 focus:ring-[#DCCFF0]/65"
                            >
                        </div>

                        <div
                            id="customer-studio-search-results"
                            class="absolute left-0 right-0 top-full z-20 mt-2 hidden overflow-hidden rounded-lg border border-[#E7DDC9]/90 bg-white shadow-[0_22px_54px_rgba(59,34,63,0.16)]"
                            data-studio-login-picker-results
                            role="listbox"
                        >
                            @foreach ($studios as $studio)
                                <a
                                    href="{{ route('customer.studio.login', $studio->slug) }}"
                                    class="flex items-center gap-3 border-b border-[#E7DDC9]/70 px-3 py-3 text-left transition last:border-b-0 hover:bg-[#FAF8F5] focus:bg-[#FAF8F5] focus:outline-none"
                                    data-studio-login-picker-option
                                    data-studio-search-text="{{ \Illuminate\Support\Str::lower($studio->name.' '.$studio->studio_slogan.' '.$studio->slug) }}"
                                    role="option"
                                >
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-[#E7DDC9] bg-[#FAF8F5] p-2">
                                        <img src="{{ $studio->logoUrl() }}" alt="" class="max-h-8 max-w-8 object-contain">
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-semibold text-[#2B1731]">{{ $studio->name }}</span>
                                        <span class="mt-0.5 block truncate text-xs font-medium text-[#4D3152]/65">{{ $studio->studio_slogan ?: __('app.studio_public_landing') }}</span>
                                    </span>
                                </a>
                            @endforeach

                            <div class="hidden px-3 py-3 text-sm font-semibold text-[#4D3152]/70" data-studio-login-picker-empty>
                                {{ __('app.customer_studio_search_no_results') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="relative hidden min-h-[22rem] lg:block lg:min-h-[34rem]" aria-hidden="true">
                    <div class="absolute inset-0">
                        <div class="absolute left-1/2 top-1/2 h-[24rem] w-[24rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#E7DDC9]/38 lg:h-[32rem] lg:w-[32rem]"></div>
                        <div class="absolute right-4 top-8 h-64 w-64 rounded-full border-2 border-[#A78AB9]/24 lg:right-10"></div>
                        <div class="absolute bottom-10 left-6 h-56 w-56 rounded-full border border-[#E7DDC9]/80"></div>
                        <div class="absolute left-4 top-20 grid grid-cols-4 gap-2 opacity-75 lg:left-12">
                            @foreach ([true, false, true, false, false, true, false, true, true, false, false, true] as $isActive)
                                <span class="h-7 w-9 rounded-md {{ $isActive ? 'bg-[#C7B4D3]/60' : 'bg-white/72' }}"></span>
                            @endforeach
                        </div>
                        <div class="absolute bottom-12 left-1/2 h-28 w-[68%] -translate-x-1/2 rounded-[50%] bg-[#3B223F]/10 blur-2xl"></div>
                    </div>

                    <img
                        src="{{ asset('assets/brand/landing/ladna-landing-mascot-cutout.png') }}"
                        alt=""
                        class="absolute bottom-0 left-1/2 h-full max-h-[36rem] w-auto max-w-none -translate-x-1/2 object-contain drop-shadow-[0_28px_54px_rgba(59,34,63,0.18)]"
                    >
                </div>
            </div>

            <section class="relative mt-2 overflow-hidden rounded-lg bg-[#2B1731] px-5 py-8 text-white shadow-[0_24px_60px_rgba(59,34,63,0.18)] sm:px-7 lg:px-8">
                <div class="absolute inset-0" aria-hidden="true">
                    <div class="absolute left-[-10rem] top-[-12rem] h-96 w-96 rounded-full bg-[#A78AB9]/25 blur-3xl"></div>
                    <div class="absolute bottom-[-11rem] right-[-10rem] h-[30rem] w-[30rem] rounded-full bg-[#E7DDC9]/18 blur-3xl"></div>
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/28 to-transparent"></div>
                    <div class="absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-white/18 to-transparent"></div>
                </div>

                <div class="relative">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-[#C7B4D3]">{{ __('app.customer_available_studios_eyebrow') }}</p>
                            <h2 class="mt-2 text-2xl font-semibold leading-tight sm:text-3xl">{{ __('app.customer_available_studios_title') }}</h2>
                        </div>
                        <p class="max-w-lg text-sm leading-6 text-white/72">{{ __('app.customer_available_studios_copy') }}</p>
                    </div>

                    <div class="mt-7 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-studio-login-picker-grid>
                        @forelse ($studios as $studio)
                            <a
                                href="{{ route('customer.studio.login', $studio->slug) }}"
                                class="group flex min-h-44 flex-col justify-between rounded-lg border border-white/12 bg-white/[0.08] p-5 shadow-[0_20px_54px_rgba(0,0,0,0.16)] transition hover:-translate-y-0.5 hover:border-[#C7B4D3]/60 hover:bg-white/[0.12] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C7B4D3] focus-visible:ring-offset-2 focus-visible:ring-offset-[#2B1731]"
                                data-studio-login-picker-card
                                data-studio-search-text="{{ \Illuminate\Support\Str::lower($studio->name.' '.$studio->studio_slogan.' '.$studio->slug) }}"
                            >
                                <span class="flex items-center gap-4">
                                    <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg bg-white p-2 shadow-[0_12px_28px_rgba(0,0,0,0.12)]">
                                        <img src="{{ $studio->logoUrl() }}" alt="{{ $studio->name }}" class="max-h-12 max-w-12 object-contain">
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-lg font-semibold leading-6 text-white">{{ $studio->name }}</span>
                                        <span class="mt-1 block text-sm leading-5 text-[#DCCFF0]/82">{{ $studio->studio_slogan ?: __('app.studio_public_landing') }}</span>
                                    </span>
                                </span>

                                <span class="mt-8 inline-flex items-center gap-2 text-sm font-semibold text-[#E7DDC9] transition group-hover:text-white">
                                    {{ __('app.customer_open_studio_login') }}
                                    <x-ui.icon name="log-in" class="h-4 w-4" />
                                </span>
                            </a>
                        @empty
                            <x-ui.empty-state :title="__('app.customer_studio_selection_empty')" icon="locations" class="bg-white text-slate-700 sm:col-span-2 lg:col-span-4" />
                        @endforelse
                    </div>

                    @if ($studios->isNotEmpty())
                        <div class="mt-4 hidden rounded-lg border border-white/12 bg-white/[0.08] px-4 py-3 text-sm font-semibold text-white/78" data-studio-login-picker-grid-empty>
                            {{ __('app.customer_studio_search_no_results') }}
                        </div>
                    @endif
                </div>
            </section>
        </section>
    </main>
@endsection
