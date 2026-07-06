@extends('layouts.public')

@section('title', __('app.customer_google_phone_title').' - '.$account->name)

@section('publicFooter')
    <x-ui.powered-footer class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    <main class="min-h-[calc(100vh-8rem)] bg-canvas px-5 py-8">
        <section class="mx-auto max-w-2xl">
            <div class="flex items-center gap-4">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl border border-stone-200 bg-white shadow-xs">
                    <img src="{{ $account->logoUrl() }}" alt="" class="max-h-10 max-w-10 object-contain">
                </span>
                <div>
                    <div class="text-sm font-semibold uppercase text-brand-600">{{ $account->name }}</div>
                    <h1 class="text-2xl font-semibold text-slate-950">{{ __('app.customer_google_phone_title') }}</h1>
                </div>
            </div>

            @if (session('status'))
                <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <div class="mt-6 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                @if (is_string($phone) && $phone !== '')
                    <div>
                        <h2 class="text-xl font-semibold text-slate-950">{{ __('app.enter_otp_code') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.customer_google_phone_code_copy', ['phone' => $phone]) }}</p>
                    </div>

                    <form method="POST" action="{{ route('customer.google.phone.verify', $account->slug) }}" class="mt-6 space-y-4">
                        @csrf
                        <input type="hidden" name="phone" value="{{ $phone }}">
                        <label class="block">
                            <span class="crm-label">{{ __('app.otp_code') }}</span>
                            <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required class="crm-field text-center font-mono text-2xl tracking-[0.35em]">
                            @error('code') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                        <x-ui.button type="submit" class="w-full justify-center">
                            {{ __('app.confirm') }}
                        </x-ui.button>
                    </form>

                    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <form method="POST" action="{{ route('customer.google.phone.resend', $account->slug) }}">
                            @csrf
                            <x-ui.button
                                type="submit"
                                variant="secondary"
                                data-otp-resend-button
                                data-otp-countdown="{{ session('otp_resend_seconds', config('customer_auth.otp.resend_seconds')) }}"
                                data-otp-countdown-message="{{ __('app.customer_otp_resend_countdown') }}"
                            >
                                {{ __('app.resend_code') }}
                            </x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('customer.google.phone.change', $account->slug) }}">
                            @csrf
                            <button type="submit" class="text-sm font-semibold text-slate-500 transition hover:text-slate-950">
                                {{ __('app.change_phone') }}
                            </button>
                        </form>
                    </div>
                    <div class="mt-3 text-sm text-slate-500" data-otp-countdown-label></div>
                @else
                    <div>
                        <h2 class="text-xl font-semibold text-slate-950">{{ __('app.customer_google_phone_heading') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.customer_google_phone_copy') }}</p>
                    </div>

                    <form method="POST" action="{{ route('customer.google.phone.send', $account->slug) }}" class="mt-6 space-y-4">
                        @csrf
                        <label class="block">
                            <span class="crm-label">{{ __('app.phone') }}</span>
                            <input
                                name="phone"
                                type="tel"
                                value="{{ old('phone') }}"
                                required
                                class="crm-field"
                                data-phone-mask
                                data-country-code="{{ $account->country_code ?? 'UA' }}"
                            >
                            @error('phone') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                        <x-ui.button type="submit" class="w-full justify-center">
                            {{ __('app.customer_google_phone_send_code') }}
                        </x-ui.button>
                    </form>
                @endif
            </div>
        </section>
    </main>
@endsection
