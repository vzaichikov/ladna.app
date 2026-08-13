@extends('layouts.festival-portal')

@php
    $isJudge = $role === \App\Enums\FestivalPortalRole::Judge;
    $isGuest = $role === \App\Enums\FestivalPortalRole::Guest;
    $isRegistrant = $role === \App\Enums\FestivalPortalRole::Registrant;
    $routePrefix = match ($role) {
        \App\Enums\FestivalPortalRole::Judge => 'festival.judge.login',
        \App\Enums\FestivalPortalRole::Guest => 'festival.guest.login',
        default => 'festival.login',
    };
    $mascotAsset = $isJudge
        ? 'assets/brand/mascot/ladna-mascot-festival-judge-cutout.png'
        : 'assets/brand/mascot/ladna-mascot-festival-champion-cutout.png';
@endphp

@section('title', ($isJudge ? __('app.festival_judge_login') : ($isGuest ? __('app.festival_guest_login') : __('app.festival_participant_login'))).' - '.$account->name)

@push('head')
    @if ($methods->otp && $stage !== 'otp_code')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
@endpush

@section('content')
<main @class(['min-h-screen bg-canvas px-5', 'py-10' => $isJudge, 'py-6 sm:py-10' => ! $isJudge])>
    <div class="mx-auto max-w-lg">
        <x-ui.public-studio-header :account="$account" />
        <section @class(['overflow-hidden rounded-3xl border border-stone-200 bg-white p-6 shadow-crm sm:mt-8 sm:p-8', 'mt-8' => $isJudge, 'mt-6' => ! $isJudge]) @unless($isJudge) data-server-validation-scroll @endunless>
            <div @class(['relative -mx-6 -mt-6 mb-6 overflow-hidden bg-[#F6F0F8] sm:-mx-8 sm:-mt-8 sm:h-56', 'h-48' => $isJudge, 'h-36' => ! $isJudge])>
                <span aria-hidden="true" class="absolute -left-8 top-8 h-32 w-32 rounded-full bg-white/50"></span>
                <span aria-hidden="true" class="absolute -right-10 -top-8 h-40 w-40 rounded-full {{ $isJudge ? 'bg-slate-200/60' : 'bg-amber-100/70' }}"></span>
                <img
                    src="{{ asset($mascotAsset) }}"
                    alt=""
                    width="1024"
                    height="1536"
                    class="relative mx-auto h-full w-full object-contain object-center"
                    aria-hidden="true"
                >
            </div>
            @if($isRegistrant)
                <p class="text-sm font-semibold uppercase tracking-wide text-brand-600" data-festival-profile-step-label>{{ __('app.festival_profile_step_label', ['current' => $stage === 'otp_code' ? 2 : 1, 'total' => $methods->otp ? 3 : 2]) }}</p>
            @endif
            <h1 class="text-3xl font-semibold">{{ $isJudge ? __('app.festival_judge_login') : ($isGuest ? __('app.festival_guest_login') : __('app.festival_participant_login')) }}</h1>
            <p class="mt-3 leading-7 text-slate-600">{{ $isJudge ? __('app.festival_judge_login_copy') : ($isGuest ? __('app.festival_guest_login_copy') : __('app.festival_participant_login_copy')) }}</p>

            @if (session('status'))<div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif
            @if ($errors->any() && ($isJudge || ! $errors->hasAny(['code', 'email', 'password', 'phone', 'cf-turnstile-response'])))<div class="mt-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ $errors->first() }}</div>@endif

            @if ($stage === 'otp_code')
                <form method="POST" action="{{ route($routePrefix.'.otp.verify', $account->slug) }}" class="mt-6 space-y-4">
                    @csrf
                    <input type="hidden" name="phone" value="{{ $phone }}">
                    <label class="block"><span class="crm-label">{{ __('app.otp_code') }}</span><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required class="crm-field text-center font-mono text-2xl tracking-[0.35em]">@unless($isJudge)<x-ui.field-error name="code" />@endunless</label>
                    <x-ui.button type="submit" class="w-full">{{ __('app.login') }}</x-ui.button>
                </form>
                <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                    <form method="POST" action="{{ route($routePrefix.'.otp.resend', $account->slug) }}">@csrf<x-ui.button type="submit" variant="secondary" data-otp-resend-button data-otp-countdown="{{ session('otp_resend_seconds', config('customer_auth.otp.resend_seconds')) }}" data-otp-countdown-message="{{ __('app.customer_otp_resend_countdown') }}">{{ __('app.resend_code') }}</x-ui.button></form>
                    <form method="POST" action="{{ route($routePrefix.'.otp.change-phone', $account->slug) }}">@csrf<button type="submit" class="text-sm font-semibold text-slate-500 hover:text-slate-950">{{ __('app.change_phone') }}</button></form>
                </div>
                <div class="mt-3 text-sm text-slate-500" data-otp-countdown-label></div>
            @else
                @if($isJudge)
                    @if ($methods->emailPassword)
                        <form method="POST" action="{{ route($routePrefix.'.email', $account->slug) }}" class="mt-6 space-y-4">
                            @csrf
                            <label class="block"><span class="crm-label">{{ __('app.email') }}</span><input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="crm-field"></label>
                            <label class="block"><span class="crm-label">{{ __('app.password') }}</span><input type="password" name="password" required autocomplete="current-password" class="crm-field"></label>
                            <x-ui.button type="submit" class="w-full">{{ __('app.login') }}</x-ui.button>
                        </form>
                    @endif

                    @if ($methods->google)
                        <a href="{{ route($routePrefix.'.google', $account->slug) }}" class="mt-4 inline-flex min-h-10 w-full items-center justify-center gap-2.5 rounded border border-[#747775] bg-white px-3 py-2 text-sm font-medium text-[#1f1f1f] transition hover:bg-[#f8fafd] focus-visible:ring-2 focus-visible:ring-[#1a73e8] focus-visible:ring-offset-2">
                            <x-ui.google-g class="h-[18px] w-[18px]" />
                            {{ __('app.google_sign_in') }}
                        </a>
                    @endif

                    @if ($methods->otp)
                        <div class="my-5 flex items-center gap-3 text-xs uppercase tracking-wide text-slate-400"><span class="h-px flex-1 bg-stone-200"></span>{{ __('app.or') }}<span class="h-px flex-1 bg-stone-200"></span></div>
                        <form method="POST" action="{{ route($routePrefix.'.otp.send', $account->slug) }}" class="space-y-4">
                            @csrf
                            <label class="block"><span class="crm-label">{{ __('app.phone') }}</span><input name="phone" type="tel" value="{{ old('phone') }}" required class="crm-field" data-phone-mask data-country-code="{{ $account->country_code ?? 'UA' }}"></label>
                            <div class="cf-turnstile" data-sitekey="{{ $methods->turnstileSiteKey }}"></div>
                            <x-ui.button type="submit" variant="secondary" class="w-full">{{ __('app.send_code') }}</x-ui.button>
                        </form>
                    @endif
                @else
                    @php
                        $credentialLoginMethods = [];

                        if ($methods->otp) {
                            $credentialLoginMethods['phone'] = __('app.phone_login');
                        }

                        if ($methods->emailPassword) {
                            $credentialLoginMethods['email'] = __('app.email_login');
                        }

                        $activeLoginMethod = $errors->hasAny(['email', 'password']) ? 'email' : 'phone';

                        if (! array_key_exists($activeLoginMethod, $credentialLoginMethods)) {
                            $activeLoginMethod = array_key_first($credentialLoginMethods) ?? 'google';
                        }

                        $hasCredentialLogin = count($credentialLoginMethods) > 0;
                        $hasTabbedLogin = count($credentialLoginMethods) > 1;
                    @endphp

                    @if($hasCredentialLogin)
                        <div class="mt-6 space-y-5" @if($hasTabbedLogin) data-customer-auth-tabs data-active-method="{{ $activeLoginMethod }}" @endif>
                            @if($hasTabbedLogin)
                                <div class="grid grid-cols-2 gap-1 rounded-lg bg-stone-100 p-1" role="tablist" aria-label="{{ __('app.customer_login_method') }}">
                                    @foreach($credentialLoginMethods as $method => $label)
                                        <button type="button" id="festival-auth-tab-{{ $method }}" class="customer-auth-tab" role="tab" data-customer-auth-tab="{{ $method }}" aria-controls="festival-auth-panel-{{ $method }}" aria-selected="{{ $activeLoginMethod === $method ? 'true' : 'false' }}" tabindex="{{ $activeLoginMethod === $method ? '0' : '-1' }}">{{ $label }}</button>
                                    @endforeach
                                </div>
                            @endif

                            @if($methods->otp)
                                <section id="festival-auth-panel-phone" data-customer-auth-panel="phone" @if($hasTabbedLogin) role="tabpanel" aria-labelledby="festival-auth-tab-phone" @endif @class(['hidden' => $hasTabbedLogin && $activeLoginMethod !== 'phone'])>
                                    <form method="POST" action="{{ route($routePrefix.'.otp.send', $account->slug) }}" class="space-y-4">
                                        @csrf
                                        <label class="block"><span class="crm-label">{{ __('app.phone') }}</span><input name="phone" type="tel" value="{{ old('phone') }}" required class="crm-field" data-phone-mask data-country-code="{{ $account->country_code ?? 'UA' }}"><x-ui.field-error name="phone" /></label>
                                        <div class="cf-turnstile" data-sitekey="{{ $methods->turnstileSiteKey }}"></div>
                                        <x-ui.field-error name="cf-turnstile-response" />
                                        <x-ui.button type="submit" class="w-full">{{ __('app.login') }}</x-ui.button>
                                    </form>
                                </section>
                            @endif

                            @if($methods->emailPassword)
                                <section id="festival-auth-panel-email" data-customer-auth-panel="email" @if($hasTabbedLogin) role="tabpanel" aria-labelledby="festival-auth-tab-email" @endif @class(['hidden' => $hasTabbedLogin && $activeLoginMethod !== 'email'])>
                                    <form method="POST" action="{{ route($routePrefix.'.email', $account->slug) }}" class="space-y-4">
                                        @csrf
                                        <label class="block"><span class="crm-label">{{ __('app.email') }}</span><input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="crm-field"><x-ui.field-error name="email" /></label>
                                        <label class="block"><span class="crm-label">{{ __('app.password') }}</span><input type="password" name="password" required autocomplete="current-password" class="crm-field"><x-ui.field-error name="password" /></label>
                                        <x-ui.button type="submit" class="w-full">{{ __('app.login_or_register') }}</x-ui.button>
                                    </form>
                                </section>
                            @endif
                        </div>
                    @endif

                    @if($methods->google)
                        <div @class(['mt-5 border-t border-stone-100 pt-5' => $hasCredentialLogin, 'mt-6' => ! $hasCredentialLogin])>
                            <a href="{{ route($routePrefix.'.google', $account->slug) }}" class="inline-flex min-h-10 w-full items-center justify-center gap-2.5 rounded border border-[#747775] bg-white px-3 py-2 text-sm font-medium text-[#1f1f1f] transition hover:bg-[#f8fafd] focus-visible:ring-2 focus-visible:ring-[#1a73e8] focus-visible:ring-offset-2">
                                <x-ui.google-g class="h-[18px] w-[18px]" />
                                {{ __('app.google_sign_in') }}
                            </a>
                        </div>
                    @endif
                @endif
            @endif

            <div class="mt-6 border-t border-stone-100 pt-5 text-sm">
                <a href="{{ route('public.festivals.index', $account->slug) }}" class="font-semibold text-slate-500 hover:text-slate-950">← {{ __('app.festivals') }}</a>
            </div>
        </section>
    </div>
</main>
@endsection
