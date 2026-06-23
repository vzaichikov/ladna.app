@extends('layouts.public')

@section('title', __('app.customer_login').' - '.$account->name)

@push('head')
    @if ($methods->google)
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@500&amp;display=swap">
    @endif

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
                    @php
                        $credentialLoginMethods = [];

                        if ($methods->otp) {
                            $credentialLoginMethods['phone'] = __('app.phone_login');
                        }

                        if ($methods->emailPassword) {
                            $credentialLoginMethods['email'] = __('app.email_login');
                        }

                        $activeLoginMethod = old('customer_auth_method', $methods->otp ? 'phone' : 'email');

                        if (! array_key_exists($activeLoginMethod, $credentialLoginMethods)) {
                            $activeLoginMethod = array_key_first($credentialLoginMethods) ?? 'google';
                        }

                        $hasCredentialLogin = count($credentialLoginMethods) > 0;
                        $hasTabbedLogin = count($credentialLoginMethods) > 1;
                    @endphp

                    <div>
                        <h2 class="text-xl font-semibold text-slate-950">{{ __('app.login_or_register') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.login_or_register_copy') }}</p>
                    </div>

                    <div class="mt-6 space-y-5">
                        @if ($hasCredentialLogin)
                            <div
                                class="space-y-5"
                                @if ($hasTabbedLogin)
                                    data-customer-auth-tabs
                                    data-active-method="{{ $activeLoginMethod }}"
                                @endif
                            >
                                @if ($hasTabbedLogin)
                                    <div class="grid grid-cols-2 gap-1 rounded-lg bg-stone-100 p-1" role="tablist" aria-label="{{ __('app.customer_login_method') }}">
                                        @foreach ($credentialLoginMethods as $method => $label)
                                            <button
                                                type="button"
                                                id="customer-auth-tab-{{ $method }}"
                                                class="customer-auth-tab"
                                                role="tab"
                                                data-customer-auth-tab="{{ $method }}"
                                                aria-controls="customer-auth-panel-{{ $method }}"
                                                aria-selected="{{ $activeLoginMethod === $method ? 'true' : 'false' }}"
                                                tabindex="{{ $activeLoginMethod === $method ? '0' : '-1' }}"
                                            >
                                                {{ $label }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endif

                                @if ($methods->otp)
                                    <section
                                        id="customer-auth-panel-phone"
                                        data-customer-auth-panel="phone"
                                        @if ($hasTabbedLogin)
                                            role="tabpanel"
                                            aria-labelledby="customer-auth-tab-phone"
                                        @endif
                                        @class(['hidden' => $hasTabbedLogin && $activeLoginMethod !== 'phone'])
                                    >
                                        <form method="POST" action="{{ route('customer.otp.send', $account->slug) }}" class="space-y-4">
                                            @csrf
                                            <input type="hidden" name="customer_auth_method" value="phone">
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
                                    </section>
                                @endif

                                @if ($methods->emailPassword)
                                    <section
                                        id="customer-auth-panel-email"
                                        data-customer-auth-panel="email"
                                        @if ($hasTabbedLogin)
                                            role="tabpanel"
                                            aria-labelledby="customer-auth-tab-email"
                                        @endif
                                        @class(['hidden' => $hasTabbedLogin && $activeLoginMethod !== 'email'])
                                    >
                                        <form method="POST" action="{{ route('customer.email.login', $account->slug) }}" class="space-y-4">
                                            @csrf
                                            <input type="hidden" name="customer_auth_method" value="email">
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
                                            <x-ui.button type="submit" class="w-full justify-center">
                                                {{ __('app.login') }}
                                            </x-ui.button>
                                        </form>
                                    </section>
                                @endif
                            </div>
                        @endif

                        @if ($methods->google)
                            <div @class(['border-t border-stone-100 pt-5' => $hasCredentialLogin])>
                                <a
                                    href="{{ route('customer.google.redirect', $account->slug) }}"
                                    class="inline-flex min-h-10 w-full items-center justify-center gap-2.5 rounded border border-[#747775] bg-white px-3 py-2 text-sm font-medium leading-5 text-[#1f1f1f] shadow-none transition hover:bg-[#f8fafd] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#1a73e8] focus-visible:ring-offset-2 active:bg-[#f1f3f4]"
                                    style="font-family: Roboto, Arial, sans-serif;"
                                    aria-label="{{ __('app.google_sign_in') }}"
                                >
                                    <x-ui.google-g class="h-[18px] w-[18px] shrink-0" />
                                    <span>{{ __('app.google_sign_in') }}</span>
                                </a>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </section>
    </main>
@endsection
