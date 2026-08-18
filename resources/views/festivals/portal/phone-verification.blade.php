@extends('layouts.festival-portal')

@php
    $phoneChallengeActive = (bool) $profilePhoneVerification['challenge_active'];
    $phoneValue = old('phone', $profilePhoneVerification['phone']);
@endphp

@section('title', __('app.festival_phone_verification_required').' - '.$account->name)

@section('content')
<main class="min-h-[calc(100vh-8rem)] bg-canvas px-5 py-8">
    <section class="mx-auto max-w-2xl" data-festival-profile-phone-step data-server-validation-scroll>
        <header class="flex items-start justify-between gap-4">
            <div class="flex min-w-0 items-center gap-4">
                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl border border-stone-200 bg-white shadow-xs">
                    <img src="{{ $account->logoUrl() }}" alt="" class="max-h-10 max-w-10 object-contain">
                </span>
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold uppercase text-brand-600">{{ $account->name }}</div>
                    <p class="mt-0.5 text-sm font-semibold text-slate-500" data-festival-profile-step-label>{{ __('app.festival_profile_step_label', ['current' => 2, 'total' => 3]) }}</p>
                </div>
            </div>
            <form method="POST" action="{{ route('festival.portal.logout', $account->slug) }}">
                @csrf
                <button type="submit" class="px-2 py-2 text-sm font-semibold text-slate-500 transition hover:text-slate-950">{{ __('app.logout') }}</button>
            </form>
        </header>

        <div class="mt-6">
            <h1 class="text-2xl font-semibold text-slate-950 sm:text-3xl">{{ __('app.festival_phone_verification_required') }}</h1>
            <p class="mt-2 leading-7 text-slate-600">{{ __('app.festival_phone_verification_step_copy') }}</p>

            @if(session('status'))
                <div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>
            @endif

            <section class="mt-6 rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                @if($phoneChallengeActive)
                    <p class="font-semibold text-slate-950">{{ __('app.enter_otp_code') }}</p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('app.enter_otp_code_copy', ['phone' => $profilePhoneVerification['phone']]) }}</p>

                    <form id="festival-profile-phone-verify" method="POST" action="{{ route('festival.portal.profile.phone.verify', $account->slug) }}" class="mt-5 space-y-4">
                        @csrf
                        <input type="hidden" name="phone" value="{{ $profilePhoneVerification['phone'] }}">
                        <label class="block">
                            <span class="crm-label">{{ __('app.otp_code') }}</span>
                            <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus class="crm-field text-center font-mono text-2xl tracking-[0.35em]">
                            <x-ui.field-error name="code" />
                        </label>
                        <x-ui.button type="submit" class="w-full justify-center">{{ __('app.confirm') }}</x-ui.button>
                    </form>

                    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <form method="POST" action="{{ route('festival.portal.profile.phone.resend', $account->slug) }}">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" data-otp-resend-button data-otp-countdown="{{ session('otp_resend_seconds', config('customer_auth.otp.resend_seconds')) }}" data-otp-countdown-message="{{ __('app.customer_otp_resend_countdown') }}">{{ __('app.resend_code') }}</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('festival.portal.profile.phone.change', $account->slug) }}">
                            @csrf
                            <button type="submit" class="text-sm font-semibold text-slate-500 transition hover:text-slate-950">{{ __('app.change_phone') }}</button>
                        </form>
                    </div>
                    <div class="mt-3 text-sm text-slate-500" data-otp-countdown-label></div>
                @else
                    <p class="font-semibold text-slate-950">{{ __('app.customer_google_phone_heading') }}</p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('app.festival_phone_verification_send_copy') }}</p>

                    <form method="POST" action="{{ route('festival.portal.profile.phone.send', $account->slug) }}" class="mt-5 space-y-4">
                        @csrf
                        <label class="block">
                            <span class="crm-label">{{ __('app.phone') }}</span>
                            <input name="phone" type="tel" value="{{ $phoneValue }}" required autofocus class="crm-field" data-phone-mask data-phone-mask-validate="false" data-country-code="{{ $account->country_code ?? 'UA' }}">
                            <x-ui.field-error name="phone" />
                        </label>
                        <x-ui.button type="submit" class="w-full justify-center">{{ __('app.customer_google_phone_send_code') }}</x-ui.button>
                    </form>
                @endif
            </section>
        </div>
    </section>
</main>
@endsection
