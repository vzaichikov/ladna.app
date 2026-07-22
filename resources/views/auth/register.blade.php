@extends('layouts.public')

@section('title', __('app.register').' - '.__('app.app_name'))

@if ($turnstileSiteKey)
    @push('head')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush
@endif

@section('content')
    <main class="flex min-h-screen items-center justify-center bg-canvas px-5 py-10">
        <section class="w-full max-w-lg rounded-3xl border border-white/80 bg-white p-6 shadow-crm sm:p-8">
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <x-ui.app-logo />
            </a>

            <div class="mt-8">
                <div class="crm-page-kicker">{{ __('app.onboarding.registration_kicker') }}</div>
                <h1 class="mt-2 text-2xl font-semibold text-brand-700 sm:text-3xl">{{ __('app.onboarding.registration_title') }}</h1>
                <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('app.onboarding.registration_copy') }}</p>
            </div>

            <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-5">
                @csrf
                <label class="block">
                    <span class="crm-label">{{ __('app.onboarding.owner_name_label') }}</span>
                    <input name="name" value="{{ old('name') }}" required autofocus autocomplete="name" class="crm-field">
                    @error('name') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <div class="grid gap-5 sm:grid-cols-2">
                    <label class="block">
                        <span class="crm-label">{{ __('app.email') }}</span>
                        <input name="email" type="email" value="{{ old('email') }}" required autocomplete="email" class="crm-field">
                        @error('email') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.phone') }}</span>
                        <input name="phone" value="{{ old('phone', '+380') }}" required inputmode="tel" autocomplete="tel" class="crm-field" data-phone-mask data-phone-country="UA">
                        @error('phone') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>
                <label class="block">
                    <span class="crm-label">{{ __('app.password') }}</span>
                    <input name="password" type="password" required autocomplete="new-password" class="crm-field">
                    @error('password') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.confirm_password') }}</span>
                    <input name="password_confirmation" type="password" required autocomplete="new-password" class="crm-field">
                </label>

                <label class="flex items-start gap-3 rounded-xl border border-stone-200 bg-brand-50/60 p-4">
                    <input name="legal_accepted" type="checkbox" value="1" @checked(old('legal_accepted')) required class="crm-checkbox mt-0.5">
                    <span class="text-sm leading-6 text-slate-600">
                        {{ __('app.onboarding.legal_consent_prefix') }}
                        <a href="{{ route(app()->getLocale() === 'en' ? 'terms.en' : 'terms.ua') }}" target="_blank" class="font-semibold text-brand-700 underline underline-offset-2">{{ __('app.onboarding.terms_link') }}</a>
                        {{ __('app.onboarding.legal_consent_and') }}
                        <a href="{{ route(app()->getLocale() === 'en' ? 'privacy.en' : 'privacy.ua') }}" target="_blank" class="font-semibold text-brand-700 underline underline-offset-2">{{ __('app.onboarding.privacy_link') }}</a>.
                    </span>
                </label>
                @error('legal_accepted') <span class="crm-help">{{ $message }}</span> @enderror

                @if ($turnstileSiteKey)
                    <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}"></div>
                @endif
                @error('cf-turnstile-response') <span class="crm-help">{{ $message }}</span> @enderror

                <x-ui.button type="submit" class="w-full">{{ __('app.onboarding.create_account') }}</x-ui.button>
            </form>

            <p class="mt-5 text-center text-sm text-slate-500">
                {{ __('app.onboarding.already_registered') }}
                <a href="{{ app()->getLocale() === 'en' ? route('login.en') : route('login') }}" class="font-semibold text-brand-700 hover:text-brand-600">{{ __('app.login') }}</a>
            </p>
        </section>
    </main>
@endsection
