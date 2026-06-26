@extends('layouts.public')

@section('title', __('app.demo_signup_title').' - '.__('app.app_name'))

@section('content')
    @php
        $formatMoney = fn (?int $cents, ?string $currency): string => number_format(($cents ?? 0) / 100, 2).' '.($currency ?: 'UAH');
    @endphp

    <main class="min-h-screen bg-[#FAF8F5] px-5 py-8 text-[#2B2B2F] sm:px-8 lg:px-10">
        <div class="mx-auto grid max-w-6xl gap-8 lg:grid-cols-[0.85fr_1.15fr] lg:items-start">
            <section class="pt-4 lg:sticky lg:top-8">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 text-[#2B1731]">
                    <x-ui.app-logo mark-class="h-10 w-10" text-class="text-[#2B1731]" />
                </a>
                <h1 class="mt-10 text-4xl font-semibold leading-tight text-[#2B1731] sm:text-5xl">
                    {{ __('app.demo_signup_title') }}
                </h1>
                <p class="mt-5 text-lg leading-8 text-[#4D3152]/75">
                    {{ __('app.demo_signup_copy') }}
                </p>

                <div class="mt-8 grid gap-3">
                    <div class="rounded-lg border border-[#A78AB9]/28 bg-white/75 p-5">
                        <div class="text-sm font-semibold uppercase tracking-[0.18em] text-[#A78AB9]">{{ __('app.demo_tariff') }}</div>
                        <div class="mt-3 text-3xl font-semibold text-[#2B1731]">{{ $formatMoney($demoPlan->price_cents, $demoPlan->currency) }}</div>
                        <p class="mt-3 text-sm leading-6 text-[#4D3152]/75">{{ __('app.demo_tariff_copy') }}</p>
                    </div>
                    <div class="rounded-lg border border-[#E7DDC9]/90 bg-white/60 p-5">
                        <div class="text-sm font-semibold uppercase tracking-[0.18em] text-[#A78AB9]">{{ __('app.standard_tariff') }}</div>
                        <div class="mt-3 text-3xl font-semibold text-[#2B1731]">{{ $formatMoney($standardPlan->price_cents, $standardPlan->currency) }}</div>
                        <p class="mt-3 text-sm leading-6 text-[#4D3152]/75">{{ __('app.standard_tariff_copy') }}</p>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-[#E7DDC9]/90 bg-white/80 p-6 shadow-[0_24px_60px_rgba(59,34,63,0.08)]">
                @if (session('status'))
                    <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                        {{ session('status') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('demo.signup.store') }}" class="space-y-5">
                    @csrf

                    <label class="block">
                        <span class="crm-label">{{ __('app.studio_name') }}</span>
                        <input name="studio_name" value="{{ old('studio_name') }}" required class="crm-field">
                        @error('studio_name') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="crm-label">{{ __('app.owner_name') }}</span>
                            <input name="owner_name" value="{{ old('owner_name') }}" required class="crm-field">
                            @error('owner_name') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="crm-label">{{ __('app.owner_phone') }}</span>
                            <input name="owner_phone" type="tel" value="{{ old('owner_phone') }}" class="crm-field" autocomplete="tel" data-phone-mask data-country-code="UA">
                            @error('owner_phone') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                    </div>

                    <label class="block">
                        <span class="crm-label">{{ __('app.owner_email') }}</span>
                        <input name="owner_email" type="email" value="{{ old('owner_email') }}" required class="crm-field">
                        @error('owner_email') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="crm-label">{{ __('app.password') }}</span>
                            <input name="owner_password" type="password" required class="crm-field">
                            @error('owner_password') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="crm-label">{{ __('app.password_confirmation') }}</span>
                            <input name="owner_password_confirmation" type="password" required class="crm-field">
                        </label>
                    </div>

                    @error('provider')
                        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-900">{{ $message }}</div>
                    @enderror

                    <div class="flex flex-col gap-3 border-t border-[#E7DDC9]/80 pt-5 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm leading-6 text-[#4D3152]/70">{{ __('app.demo_signup_payment_note') }}</p>
                        <x-ui.button type="submit">
                            <x-ui.icon name="payments" class="h-4 w-4" />
                            {{ __('app.pay_demo_now') }}
                        </x-ui.button>
                    </div>
                </form>
            </section>
        </div>
    </main>
@endsection
