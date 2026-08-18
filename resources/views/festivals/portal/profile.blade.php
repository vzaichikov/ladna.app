@extends('layouts.festival-portal')

@php
    $isJudge = $portalUser->role === \App\Enums\FestivalPortalRole::Judge;
    $isRegistrant = $portalUser->role === \App\Enums\FestivalPortalRole::Registrant;
    $profileRoutePrefix = match ($portalUser->role) {
        \App\Enums\FestivalPortalRole::Judge => 'festival.portal.judge.profile',
        default => 'festival.portal.profile',
    };
    $selectedRegistrantType = old('registrant_type', $portalUser->registrant_type?->value);
    $isParticipant = $selectedRegistrantType === \App\Enums\FestivalRegistrantType::AdultAthlete->value;
    $phoneChallengeActive = (bool) ($profilePhoneVerification['challenge_active'] ?? false);
    $phoneVerificationHasPhone = filled($profilePhoneVerification['phone'] ?? null);
    $phoneValue = old('phone', $profilePhoneVerification['phone'] ?? $portalUser->phone);
    $isParticipantProfileCompletion = $isParticipantProfileCompletion ?? false;
    $participantProfileStep = $participantProfileStep ?? null;
@endphp

@section('title', __('app.profile').' - '.$account->name)

@section('content')
<main class="min-h-screen bg-canvas px-4 py-6 sm:px-5 sm:py-8">
    <div class="mx-auto max-w-6xl">
        @include('festivals.portal._nav')
        <header class="mt-8">
            @if($participantProfileStep)<p class="text-sm font-semibold uppercase tracking-wide text-brand-600" data-festival-profile-step-label>{{ __('app.festival_profile_step_label', $participantProfileStep) }}</p>@endif
            <h1 @class(['mt-1' => $isParticipantProfileCompletion, 'text-3xl font-semibold sm:text-4xl'])>{{ $isRegistrant ? __('app.festival_participant_profile') : __('app.festival_profile') }}</h1>
            <p class="mt-2 text-slate-600">{{ $isJudge ? __('app.festival_role_judge') : __('app.festival_role_registrant') }}</p>
        </header>
        @if(session('status'))<div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif

        <form method="POST" action="{{ route($profileRoutePrefix.'.update', $account->slug) }}" class="mt-6 space-y-6" novalidate @unless($isJudge) data-festival-participant-profile-form data-server-validation-scroll @endunless>
            @csrf
            @method('PUT')

            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                <h2 class="text-xl font-semibold">{{ __('app.festival_profile_personal_details') }}</h2>
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    @if($isRegistrant)
                        <label for="registrant-type">
                            <span class="crm-label">{{ __('app.festival_profile_type') }}<span class="text-rose-600" aria-hidden="true" data-required-marker>*</span><span class="sr-only"> ({{ __('app.required') }})</span></span>
                            <select id="registrant-type" name="registrant_type" required class="crm-field" data-festival-registrant-type aria-invalid="{{ $errors->has('registrant_type') ? 'true' : 'false' }}">
                                @foreach(\App\Enums\FestivalRegistrantType::selectableCases($portalUser->registrant_type) as $type)
                                    <option value="{{ $type->value }}" @selected($selectedRegistrantType === $type->value)>{{ __('app.festival_registrant_'.$type->value) }}</option>
                                @endforeach
                            </select>
                            <x-ui.field-error name="registrant_type" />
                        </label>
                    @endif
                    <label for="first-name"><span class="crm-label">{{ __('app.first_name') }}<span class="text-rose-600" aria-hidden="true" data-required-marker>*</span><span class="sr-only"> ({{ __('app.required') }})</span></span><input id="first-name" name="first_name" value="{{ old('first_name', $portalUser->first_name) }}" required class="crm-field"><x-ui.field-error name="first_name" /></label>
                    <label for="last-name"><span class="crm-label">{{ __('app.last_name') }}<span class="text-rose-600" aria-hidden="true" data-required-marker>*</span><span class="sr-only"> ({{ __('app.required') }})</span></span><input id="last-name" name="last_name" value="{{ old('last_name', $portalUser->last_name) }}" required class="crm-field"><x-ui.field-error name="last_name" /></label>
                    <label for="patronymic"><span class="crm-label">{{ __('app.patronymic') }}</span><input id="patronymic" name="patronymic" value="{{ old('patronymic', $portalUser->patronymic) }}" class="crm-field"><x-ui.field-error name="patronymic" /></label>
                    <label for="stage-name"><span class="crm-label">{{ __('app.festival_stage_name') }}</span><input id="stage-name" name="stage_name" value="{{ old('stage_name', $portalUser->stage_name) }}" class="crm-field"><x-ui.field-error name="stage_name" /></label>
                    @if($isRegistrant)
                        <label for="date-of-birth" data-festival-participant-birth-date>
                            <span class="crm-label">{{ __('app.date_of_birth') }}<span class="text-rose-600 {{ $isParticipant ? '' : 'hidden' }}" aria-hidden="true" data-required-marker data-participant-required-marker>*</span></span>
                            <input id="date-of-birth" type="date" name="date_of_birth" value="{{ old('date_of_birth', $portalUser->profileParticipant?->date_of_birth?->format('Y-m-d')) }}" @required($isParticipant) class="crm-field" data-participant-required-input>
                            <x-ui.field-error name="date_of_birth" />
                        </label>
                    @endif
                </div>
            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                <h2 class="text-xl font-semibold">{{ __('app.festival_profile_contact_details') }}</h2>
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <label for="email"><span class="crm-label">{{ __('app.email') }}<span class="text-rose-600" aria-hidden="true" data-required-marker>*</span><span class="sr-only"> ({{ __('app.required') }})</span></span><input id="email" type="email" name="email" value="{{ old('email', $portalUser->email) }}" required class="crm-field"><x-ui.field-error name="email" /></label>
                    <div>
                        <label for="phone"><span class="crm-label">{{ __('app.phone') }}@if($isRegistrant)<span class="text-rose-600" aria-hidden="true" data-required-marker>*</span><span class="sr-only"> ({{ __('app.required') }})</span>@endif</span><input id="phone" name="phone" value="{{ $phoneValue }}" @required($isRegistrant) @readonly($phoneChallengeActive) class="crm-field" data-phone-mask data-country-code="{{ $account->country_code ?? 'UA' }}" @if($isRegistrant) data-festival-profile-phone data-phone-mask-validate="false" @endif><x-ui.field-error name="phone" /></label>
                        @if($profilePhoneVerification)
                            <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950" data-profile-phone-verification @if($phoneVerificationHasPhone) data-profile-phone-merge @endif>
                                <p class="font-semibold">{{ __('app.festival_phone_verification_required') }}</p>
                                @if($phoneChallengeActive)
                                    <p class="mt-2">{{ __('app.enter_otp_code_copy', ['phone' => $profilePhoneVerification['phone']]) }}</p>
                                    <label class="mt-4 block"><span class="crm-label">{{ __('app.otp_code') }}</span><input name="code" form="festival-profile-phone-verify" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required class="crm-field bg-white text-center font-mono text-2xl tracking-[0.35em]"><x-ui.field-error name="code" /></label>
                                    <div class="mt-4 flex flex-wrap gap-3"><x-ui.button type="submit" form="festival-profile-phone-verify">{{ __('app.confirm') }}</x-ui.button><x-ui.button type="submit" form="festival-profile-phone-resend" variant="secondary" data-otp-resend-button data-otp-countdown="{{ session('otp_resend_seconds', config('customer_auth.otp.resend_seconds')) }}" data-otp-countdown-message="{{ __('app.customer_otp_resend_countdown') }}">{{ __('app.resend_code') }}</x-ui.button><button type="submit" form="festival-profile-phone-change" class="text-sm font-semibold text-amber-800">{{ __('app.change_phone') }}</button></div>
                                    <div class="mt-3 text-sm text-amber-800" data-otp-countdown-label></div>
                                @else
                                    <p class="mt-2">{{ $phoneVerificationHasPhone ? __('app.festival_phone_verification_copy', ['phone' => $profilePhoneVerification['phone']]) : __('app.festival_phone_verification_empty_copy') }}</p>
                                    <div class="mt-4 flex flex-wrap gap-3">
                                        <x-ui.button type="submit" name="profile_action" value="send_phone_otp" data-festival-profile-phone-send :disabled="blank($phoneValue)">{{ __('app.festival_profile_save_and_send_code') }}</x-ui.button>
                                        @if($phoneVerificationHasPhone)<button type="submit" form="festival-profile-phone-change" class="text-sm font-semibold text-amber-800">{{ __('app.change_phone') }}</button>@endif
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                    @if($isRegistrant)
                        <label for="city"><span class="crm-label">{{ __('app.city') }}<span class="text-rose-600" aria-hidden="true" data-required-marker>*</span><span class="sr-only"> ({{ __('app.required') }})</span></span><input id="city" name="city" value="{{ old('city', $portalUser->city) }}" required class="crm-field"><x-ui.field-error name="city" /></label>
                        <label for="studio-name"><span class="crm-label">{{ __('app.festival_studio_school') }}<span class="text-rose-600" aria-hidden="true" data-required-marker>*</span><span class="sr-only"> ({{ __('app.required') }})</span></span><input id="studio-name" name="studio_name" value="{{ old('studio_name', $portalUser->studio_name) }}" required class="crm-field"><x-ui.field-error name="studio_name" /></label>
                        <label for="instagram-url"><span class="crm-label">Instagram</span><input id="instagram-url" type="url" name="instagram_url" value="{{ old('instagram_url', $portalUser->instagram_url) }}" class="crm-field"><x-ui.field-error name="instagram_url" /></label>
                        <label for="telegram-contact"><span class="crm-label">Telegram</span><input id="telegram-contact" name="telegram_contact" value="{{ old('telegram_contact', $portalUser->telegram_contact) }}" placeholder="@username / ID / t.me/username" class="crm-field"><span class="mt-1 block text-sm text-slate-500">{{ __('app.festival_telegram_contact_help') }}</span><x-ui.field-error name="telegram_contact" /></label>
                    @endif
                </div>

            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                <h2 class="text-xl font-semibold">{{ __('app.festival_profile_preferences_security') }}</h2>
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <label for="locale"><span class="crm-label">{{ __('app.language') }}<span class="text-rose-600" aria-hidden="true" data-required-marker>*</span><span class="sr-only"> ({{ __('app.required') }})</span></span><select id="locale" name="locale" required class="crm-field"><option value="uk" @selected(old('locale', $portalUser->locale)==='uk')>Українська</option><option value="en" @selected(old('locale', $portalUser->locale)==='en')>English</option></select><x-ui.field-error name="locale" /></label>
                    <div class="hidden sm:block"></div>
                    <label for="password"><span class="crm-label">{{ __('app.new_password') }}</span><input id="password" type="password" name="password" autocomplete="new-password" class="crm-field"><span class="mt-1 block text-sm text-slate-500">{{ __('app.festival_password_edit_help') }}</span><x-ui.field-error name="password" /></label>
                    <label for="password-confirmation"><span class="crm-label">{{ __('app.password_confirmation') }}</span><input id="password-confirmation" type="password" name="password_confirmation" autocomplete="new-password" class="crm-field"><x-ui.field-error name="password_confirmation" /></label>
                </div>
            </section>

            <div class="flex justify-end"><x-ui.button type="submit">{{ __('app.save') }}</x-ui.button></div>
        </form>

        @if($profilePhoneVerification && $phoneVerificationHasPhone)
            <form id="festival-profile-phone-resend" method="POST" action="{{ route($profileRoutePrefix.'.phone.resend', $account->slug) }}">@csrf</form>
            <form id="festival-profile-phone-change" method="POST" action="{{ route($profileRoutePrefix.'.phone.change', $account->slug) }}">@csrf</form>
            <form id="festival-profile-phone-verify" method="POST" action="{{ route($profileRoutePrefix.'.phone.verify', $account->slug) }}">@csrf<input type="hidden" name="phone" value="{{ $profilePhoneVerification['phone'] }}"></form>
        @endif
    </div>
</main>
@endsection
