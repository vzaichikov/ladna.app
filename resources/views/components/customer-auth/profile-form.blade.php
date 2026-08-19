@props([
    'account',
    'customer',
    'profilePhoneMerge' => null,
    'embedded' => false,
    'showPortalLink' => true,
])

@php
    $profilePhoneChallengeActive = (bool) ($profilePhoneMerge['challenge_active'] ?? false);
    $profilePhoneValue = old('phone', $profilePhoneMerge['phone'] ?? $customer->phone);
@endphp

<form
    method="POST"
    action="{{ route('customer.profile.update', $account->slug) }}"
    @class([
        'space-y-5',
        'mt-6 rounded-xl border border-stone-200 bg-white p-6 shadow-crm' => ! $embedded,
    ])
>
    @csrf
    @method('PUT')

    <label class="block">
        <span class="crm-label">{{ __('app.full_name') }}</span>
        <input name="name" value="{{ old('name', $customer->name) }}" required class="crm-field">
        @error('name') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.phone') }}</span>
        <input
            name="phone"
            type="tel"
            value="{{ $profilePhoneValue }}"
            required
            class="crm-field"
            data-phone-mask
            data-country-code="{{ $account->country_code ?? 'UA' }}"
            @readonly($profilePhoneChallengeActive)
        >
        @error('phone') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    @if ($profilePhoneMerge)
        <div
            id="profile-phone-merge"
            data-profile-phone-merge
            class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950"
        >
            <p class="font-semibold">{{ __('app.customer_profile_phone_merge_required') }}</p>

            @if ($profilePhoneChallengeActive)
                <p class="mt-2 leading-6 text-amber-900">{{ __('app.enter_otp_code_copy', ['phone' => $profilePhoneMerge['phone']]) }}</p>

                <div class="mt-4 space-y-4">
                    <label class="block">
                        <span class="crm-label">{{ __('app.otp_code') }}</span>
                        <input
                            name="code"
                            form="profile-phone-verify-form"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            required
                            class="crm-field bg-white text-center font-mono text-2xl tracking-[0.35em]"
                        >
                        @error('code') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <x-ui.button type="submit" form="profile-phone-verify-form" class="w-full justify-center">
                        {{ __('app.confirm') }}
                    </x-ui.button>
                </div>

                <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <x-ui.button
                        type="submit"
                        form="profile-phone-resend-form"
                        variant="secondary"
                        data-otp-resend-button
                        data-otp-countdown="{{ session('otp_resend_seconds', config('customer_auth.otp.resend_seconds')) }}"
                        data-otp-countdown-message="{{ __('app.customer_otp_resend_countdown') }}"
                    >
                        {{ __('app.resend_code') }}
                    </x-ui.button>
                    <button type="submit" form="profile-phone-change-form" class="text-sm font-semibold text-amber-800 transition hover:text-amber-950">
                        {{ __('app.change_phone') }}
                    </button>
                </div>
                <div class="mt-3 text-sm text-amber-800" data-otp-countdown-label></div>
            @else
                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                    <x-ui.button type="submit" form="profile-phone-send-form">
                        {{ __('app.customer_google_phone_send_code') }}
                    </x-ui.button>
                    <button type="submit" form="profile-phone-change-form" class="text-sm font-semibold text-amber-800 transition hover:text-amber-950">
                        {{ __('app.change_phone') }}
                    </button>
                </div>
            @endif
        </div>
    @endif

    <label class="block">
        <span class="crm-label">{{ __('app.email') }}</span>
        <input name="email" type="email" value="{{ old('email', $customer->email) }}" class="crm-field">
        @error('email') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <div class="grid gap-4 sm:grid-cols-2">
        <label class="block">
            <span class="crm-label">{{ __('app.new_password') }}</span>
            <input name="password" type="password" autocomplete="new-password" class="crm-field">
            <span class="mt-1.5 block text-sm text-slate-500">{{ __('app.customer_profile_password_help') }}</span>
            @error('password') <span class="crm-help">{{ $message }}</span> @enderror
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.confirm_new_password') }}</span>
            <input name="password_confirmation" type="password" autocomplete="new-password" class="crm-field">
            @error('password_confirmation') <span class="crm-help">{{ $message }}</span> @enderror
        </label>
    </div>

    <div class="flex flex-wrap gap-3">
        <x-ui.button type="submit">
            {{ __('app.save') }}
        </x-ui.button>
        @if ($showPortalLink && $customer->profileIsComplete())
            <x-ui.button :href="route('customer.dashboard', $account->slug)" variant="secondary">
                {{ __('app.customer_portal') }}
            </x-ui.button>
        @endif
    </div>
</form>

@if ($profilePhoneMerge)
    <form id="profile-phone-verify-form" method="POST" action="{{ route('customer.profile.phone.verify', $account->slug) }}">
        @csrf
        <input type="hidden" name="phone" value="{{ $profilePhoneMerge['phone'] }}">
    </form>
    <form id="profile-phone-resend-form" method="POST" action="{{ route('customer.profile.phone.resend', $account->slug) }}">
        @csrf
    </form>
    <form id="profile-phone-change-form" method="POST" action="{{ route('customer.profile.phone.change', $account->slug) }}">
        @csrf
    </form>
    <form id="profile-phone-send-form" method="POST" action="{{ route('customer.profile.phone.send', $account->slug) }}">
        @csrf
    </form>
@endif
