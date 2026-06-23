@extends('layouts.public')

@section('title', __('app.customer_login').' - '.$account->name)

@push('head')
    @if ($methods->otp && $mode !== 'otp_code')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
@endpush

@section('content')
    <main class="min-h-screen bg-canvas px-5 py-8 sm:py-12">
        <section class="mx-auto grid w-full max-w-5xl gap-8 lg:grid-cols-[0.9fr_1fr] lg:items-center">
            <div class="space-y-5">
                <div class="flex items-center gap-4">
                    <span class="flex h-16 w-16 items-center justify-center rounded-2xl border border-stone-200 bg-white shadow-xs">
                        <img src="{{ $account->logoUrl() }}" alt="" class="max-h-11 max-w-11 object-contain">
                    </span>
                    <div>
                        <div class="text-sm font-semibold uppercase text-brand-600">{{ $account->name }}</div>
                        <h1 class="mt-1 text-3xl font-semibold text-slate-950">{{ __('app.customer_login') }}</h1>
                    </div>
                </div>
                <p class="max-w-xl text-base leading-7 text-slate-600">{{ __('app.customer_login_copy') }}</p>
            </div>

            <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-7">
                @if (session('status'))
                    <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                @if (! $methods->hasAnyMethod())
                    <x-ui.empty-state :title="__('app.customer_auth_unavailable')" icon="key-round" />
                @elseif ($mode === 'otp_code')
                    <div>
                        <h2 class="text-xl font-semibold text-slate-950">{{ __('app.enter_otp_code') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.enter_otp_code_copy', ['phone' => $phone]) }}</p>
                    </div>

                    <form method="POST" action="{{ route('customer.otp.verify', $account->slug) }}" class="mt-6 space-y-4">
                        @csrf
                        <input type="hidden" name="phone" value="{{ $phone }}">
                        <label class="block">
                            <span class="crm-label">{{ __('app.otp_code') }}</span>
                            <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required class="crm-field text-center font-mono text-2xl tracking-[0.35em]">
                            @error('code') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                        <x-ui.button type="submit" class="w-full justify-center">
                            {{ __('app.login') }}
                        </x-ui.button>
                    </form>

                    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <form method="POST" action="{{ route('customer.otp.resend', $account->slug) }}">
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
                        <form method="POST" action="{{ route('customer.otp.change-phone', $account->slug) }}">
                            @csrf
                            <button type="submit" class="text-sm font-semibold text-slate-500 transition hover:text-slate-950">
                                {{ __('app.change_phone') }}
                            </button>
                        </form>
                    </div>
                    <div class="mt-3 text-sm text-slate-500" data-otp-countdown-label></div>
                @else
                    <div>
                        <h2 class="text-xl font-semibold text-slate-950">{{ __('app.login_or_register') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.login_or_register_copy') }}</p>
                    </div>

                    <div class="mt-6 space-y-5">
                        @if ($methods->otp)
                            <form method="POST" action="{{ route('customer.otp.send', $account->slug) }}" class="space-y-4">
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
                                <div class="cf-turnstile" data-sitekey="{{ $methods->turnstileSiteKey }}"></div>
                                @error('cf-turnstile-response') <span class="crm-help">{{ $message }}</span> @enderror
                                <x-ui.button type="submit" class="w-full justify-center">
                                    {{ __('app.login') }}
                                </x-ui.button>
                            </form>
                        @endif

                        @if ($methods->emailPassword)
                            <form method="POST" action="{{ route('customer.email.login', $account->slug) }}" class="space-y-4 border-t border-stone-100 pt-5">
                                @csrf
                                <label class="block">
                                    <span class="crm-label">{{ __('app.email') }}</span>
                                    <input name="email" type="email" value="{{ old('email') }}" required autocomplete="email" class="crm-field">
                                    @error('email') <span class="crm-help">{{ $message }}</span> @enderror
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.password') }}</span>
                                    <input name="password" type="password" required autocomplete="current-password" class="crm-field">
                                    <span class="mt-1.5 block text-sm text-slate-500">{{ __('app.customer_password_help') }}</span>
                                    @error('password') <span class="crm-help">{{ $message }}</span> @enderror
                                </label>
                                <x-ui.button type="submit" variant="{{ $methods->otp ? 'secondary' : 'primary' }}" class="w-full justify-center">
                                    {{ __('app.login') }}
                                </x-ui.button>
                            </form>
                        @endif

                        @if ($methods->google)
                            <div class="border-t border-stone-100 pt-5">
                                <x-ui.button :href="route('customer.google.redirect', $account->slug)" variant="secondary" class="w-full justify-center">
                                    <x-ui.icon name="chrome" class="h-4 w-4" />
                                    {{ __('app.google_login') }}
                                </x-ui.button>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </section>
    </main>
@endsection
