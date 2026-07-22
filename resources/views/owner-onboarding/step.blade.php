@extends('layouts.public')

@section('title', __('app.onboarding.steps.'.$step.'.title').' - '.__('app.app_name'))

@if ($step === 6 && $turnstileSiteKey)
    @push('head')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush
@endif

@section('content')
    @php
        $stage = $allStepAnswers[1]['studio_stage'] ?? 'operating';
        $locationCount = (int) ($allStepAnswers[1]['location_count'] ?? 1);
        $stepTwo = $allStepAnswers[2] ?? [];
        $stepThree = $allStepAnswers[3] ?? [];
        $stepFour = $allStepAnswers[4] ?? [];
        $stepFive = $allStepAnswers[5] ?? [];
        $weekdayLabels = __('app.onboarding.weekdays');
    @endphp

    <main class="min-h-screen bg-canvas text-slate-900">
        <div class="mx-auto flex min-h-screen w-full max-w-6xl flex-col px-4 py-4 sm:px-6 sm:py-6 lg:px-8">
            <header class="flex flex-wrap items-center justify-between gap-3">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 text-brand-700">
                    <x-ui.app-logo mark-class="h-10 w-10" text-class="text-brand-700" />
                </a>

                <nav class="flex items-center gap-2 text-sm font-semibold text-slate-600" aria-label="{{ __('app.onboarding.utility_navigation') }}">
                    <a href="{{ route('help.index') }}" class="rounded-lg px-3 py-2 transition hover:bg-white hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                        {{ __('app.help') }}
                    </a>
                    <a href="{{ app()->getLocale() === 'en' ? route('home') : route('home.en') }}" class="rounded-lg px-3 py-2 transition hover:bg-white hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                        {{ app()->getLocale() === 'en' ? 'UA' : 'EN' }}
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-lg px-3 py-2 transition hover:bg-white hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                            {{ __('app.logout') }}
                        </button>
                    </form>
                </nav>
            </header>

            <div class="mx-auto mt-8 w-full max-w-3xl flex-1 pb-10 sm:mt-12">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-sm font-semibold text-brand-700">
                        {{ __('app.onboarding.step_progress', ['step' => $step, 'total' => 6]) }}
                    </p>
                    <p class="text-xs font-semibold text-slate-500">{{ __('app.onboarding.saved_automatically') }}</p>
                </div>
                <div class="mt-3 grid grid-cols-6 gap-1.5" aria-hidden="true">
                    @foreach (range(1, 6) as $progressStep)
                        <span @class([
                            'h-1.5 rounded-full',
                            'bg-brand-600' => $progressStep <= $step,
                            'bg-brand-100' => $progressStep > $step,
                        ])></span>
                    @endforeach
                </div>

                @if (session('status'))
                    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                        {{ session('status') }}
                    </div>
                @endif

                @error('onboarding')
                    <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                        {{ $message }}
                    </div>
                @enderror

                <section class="relative mt-6 overflow-hidden rounded-3xl border border-white/80 bg-white p-5 shadow-crm sm:p-8">
                    @if ($step === 1)
                        <div class="pointer-events-none absolute -right-6 -top-8 hidden h-40 w-40 rounded-full bg-violet-crm-100/45 sm:block" aria-hidden="true"></div>
                        <img src="{{ asset('assets/brand/mascot/ladna-mascot-sporty-cutout.png') }}" alt="" class="pointer-events-none absolute -right-3 top-3 hidden h-32 w-auto object-contain sm:block" aria-hidden="true">
                    @endif

                    <div class="relative {{ $step === 1 ? 'sm:pr-32' : '' }}">
                        <p class="crm-page-kicker">{{ __('app.onboarding.kicker') }}</p>
                        <h1 class="mt-2 text-2xl font-semibold leading-tight text-brand-700 sm:text-3xl">
                            {{ __('app.onboarding.steps.'.$step.'.title') }}
                        </h1>
                        <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600 sm:text-base">
                            {{ __('app.onboarding.steps.'.$step.'.copy') }}
                        </p>
                    </div>

                    @if ($step < 6)
                        <form method="POST" action="{{ route('onboarding.store', ['step' => $step]) }}" class="relative mt-7 space-y-6" @if ($step === 1) enctype="multipart/form-data" @endif>
                            @csrf

                            @if ($step === 1)
                                <fieldset>
                                    <legend class="crm-label">{{ __('app.onboarding.studio_stage_label') }}</legend>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                        @foreach (['operating', 'preparing'] as $option)
                                            <label class="cursor-pointer rounded-xl border border-stone-200 bg-brand-50/60 p-4 transition has-checked:border-brand-500 has-checked:bg-violet-crm-100/45 has-focus-visible:ring-2 has-focus-visible:ring-brand-500">
                                                <input type="radio" name="studio_stage" value="{{ $option }}" class="sr-only" @checked(old('studio_stage', $values['studio_stage']) === $option) required>
                                                <span class="block font-semibold text-brand-700">{{ __('app.onboarding.studio_stage_'.$option) }}</span>
                                                <span class="mt-1 block text-sm leading-5 text-slate-600">{{ __('app.onboarding.studio_stage_'.$option.'_copy') }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('studio_stage') <span class="crm-help">{{ $message }}</span> @enderror
                                </fieldset>

                                <label class="block">
                                    <span class="crm-label">{{ __('app.onboarding.studio_name_label') }}</span>
                                    <input name="studio_name" value="{{ old('studio_name', $values['studio_name']) }}" required autofocus autocomplete="organization" class="crm-field" placeholder="{{ __('app.onboarding.studio_name_placeholder') }}">
                                    @error('studio_name') <span class="crm-help">{{ $message }}</span> @enderror
                                </label>

                                <label class="block">
                                    <span class="crm-label">{{ __('app.onboarding.location_count_label') }}</span>
                                    <span class="mt-1 block text-sm leading-5 text-slate-500">{{ __('app.onboarding.location_count_help') }}</span>
                                    <input name="location_count" type="number" inputmode="numeric" min="1" max="20" value="{{ old('location_count', $values['location_count']) }}" required class="crm-field">
                                    @error('location_count') <span class="crm-help">{{ $message }}</span> @enderror
                                </label>

                                <div>
                                    <label for="onboarding-logo" class="crm-label">
                                        {{ __('app.onboarding.logo_label') }}
                                        <span class="font-normal text-slate-500">({{ __('app.optional') }})</span>
                                    </label>
                                    <div class="mt-3 flex items-center gap-4">
                                        <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-dashed border-brand-100 bg-brand-50">
                                            <img
                                                @if ($account?->logo_path) src="{{ $account->logoUrl() }}" @endif
                                                alt=""
                                                @class(['h-full w-full object-contain p-2', 'hidden' => blank($account?->logo_path)])
                                                data-onboarding-logo-preview
                                            >
                                            <x-ui.icon name="image" @class(['h-6 w-6 text-brand-500', 'hidden' => filled($account?->logo_path)]) data-onboarding-logo-placeholder />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <input id="onboarding-logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-100 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-violet-crm-100" data-onboarding-logo-input>
                                            <p class="mt-2 text-xs leading-5 text-slate-500">{{ __('app.onboarding.logo_help') }}</p>
                                        </div>
                                    </div>
                                    @error('logo') <span class="crm-help">{{ $message }}</span> @enderror
                                </div>

                                @if ($trialEndsAt)
                                    <div class="rounded-xl border border-brand-100 bg-brand-50 px-4 py-3 text-sm leading-6 text-brand-700">
                                        <span class="font-semibold">{{ __('app.onboarding.trial_title') }}</span>
                                        {{ __('app.onboarding.trial_ends_on', ['date' => $trialEndsAt->timezone('Europe/Kyiv')->translatedFormat('j F Y')]) }}
                                    </div>
                                @endif
                            @elseif ($step === 2)
                                <label class="block">
                                    <span class="crm-label">{{ __('app.onboarding.location_name_label') }}</span>
                                    <input name="location_name" value="{{ old('location_name', $values['location_name']) }}" required autofocus class="crm-field">
                                    @error('location_name') <span class="crm-help">{{ $message }}</span> @enderror
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.onboarding.address_label') }}</span>
                                    <input name="address" value="{{ old('address', $values['address']) }}" required autocomplete="street-address" class="crm-field" placeholder="{{ __('app.onboarding.address_placeholder') }}">
                                    @error('address') <span class="crm-help">{{ $message }}</span> @enderror
                                </label>
                                <div class="grid gap-5 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.onboarding.room_name_label') }}</span>
                                        <input name="room_name" value="{{ old('room_name', $values['room_name']) }}" required class="crm-field">
                                        @error('room_name') <span class="crm-help">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.onboarding.capacity_label') }}</span>
                                        <input name="capacity" type="number" inputmode="numeric" min="1" max="999" value="{{ old('capacity', $values['capacity']) }}" required class="crm-field">
                                        @error('capacity') <span class="crm-help">{{ $message }}</span> @enderror
                                    </label>
                                </div>
                                @if ($locationCount > 1)
                                    <div class="rounded-xl border border-brand-100 bg-brand-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                        {{ trans_choice('app.onboarding.placeholder_locations_note', $locationCount - 1, ['count' => $locationCount - 1]) }}
                                    </div>
                                @endif
                            @elseif ($step === 3)
                                <fieldset>
                                    <legend class="crm-label">{{ __('app.onboarding.teaching_mode_label') }}</legend>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                        @foreach (['owner', 'another'] as $option)
                                            <label class="cursor-pointer rounded-xl border border-stone-200 bg-brand-50/60 p-4 transition has-checked:border-brand-500 has-checked:bg-violet-crm-100/45 has-focus-visible:ring-2 has-focus-visible:ring-brand-500">
                                                <input type="radio" name="teaching_mode" value="{{ $option }}" class="sr-only" @checked(old('teaching_mode', $values['teaching_mode']) === $option) required>
                                                <span class="block font-semibold text-brand-700">{{ __('app.onboarding.teaching_mode_'.$option) }}</span>
                                                <span class="mt-1 block text-sm leading-5 text-slate-600">{{ __('app.onboarding.teaching_mode_'.$option.'_copy') }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('teaching_mode') <span class="crm-help">{{ $message }}</span> @enderror
                                </fieldset>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.onboarding.trainer_name_label') }}</span>
                                    <input name="trainer_name" value="{{ old('trainer_name', $values['trainer_name']) }}" required autofocus class="crm-field">
                                    <span class="mt-1 block text-sm leading-5 text-slate-500">{{ __('app.onboarding.trainer_login_help') }}</span>
                                    @error('trainer_name') <span class="crm-help">{{ $message }}</span> @enderror
                                </label>
                            @elseif ($step === 4)
                                <label class="block">
                                    <span class="crm-label">{{ __('app.onboarding.direction_name_label') }}</span>
                                    <input name="direction_name" value="{{ old('direction_name', $values['direction_name']) }}" required autofocus list="onboarding-directions" class="crm-field" placeholder="{{ __('app.onboarding.direction_name_placeholder') }}">
                                    <datalist id="onboarding-directions">
                                        @foreach (__('app.onboarding.direction_suggestions') as $suggestion)
                                            <option value="{{ $suggestion }}"></option>
                                        @endforeach
                                    </datalist>
                                    @error('direction_name') <span class="crm-help">{{ $message }}</span> @enderror
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.onboarding.class_name_label') }}</span>
                                    <input name="class_name" value="{{ old('class_name', $values['class_name']) }}" required class="crm-field" placeholder="{{ __('app.onboarding.class_name_placeholder') }}">
                                    @error('class_name') <span class="crm-help">{{ $message }}</span> @enderror
                                </label>
                                <div class="grid gap-5 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.onboarding.duration_label') }}</span>
                                        <select name="duration_minutes" required class="crm-field">
                                            @foreach ([30, 45, 60, 75, 90, 120] as $duration)
                                                <option value="{{ $duration }}" @selected((int) old('duration_minutes', $values['duration_minutes']) === $duration)>
                                                    {{ __('app.onboarding.duration_minutes', ['minutes' => $duration]) }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('duration_minutes') <span class="crm-help">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.onboarding.class_capacity_label') }}</span>
                                        <input name="capacity" type="number" inputmode="numeric" min="1" max="999" value="{{ old('capacity', $values['capacity']) }}" required class="crm-field">
                                        @error('capacity') <span class="crm-help">{{ $message }}</span> @enderror
                                    </label>
                                </div>
                                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                                    {{ __('app.onboarding.pricing_later_note') }}
                                </div>
                            @elseif ($step === 5)
                                <p class="rounded-xl border border-brand-100 bg-brand-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                    {{ $stage === 'preparing' ? __('app.onboarding.schedule_preparing_note') : __('app.onboarding.schedule_operating_note') }}
                                </p>
                                <div class="grid gap-5 sm:grid-cols-3">
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.onboarding.weekday_label') }}</span>
                                        <select name="weekday" required class="crm-field">
                                            @foreach ($weekdayLabels as $number => $label)
                                                <option value="{{ $number }}" @selected((int) old('weekday', $values['weekday']) === (int) $number)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @error('weekday') <span class="crm-help">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.onboarding.start_time_label') }}</span>
                                        <input name="start_time" type="time" value="{{ old('start_time', $values['start_time']) }}" required class="crm-field">
                                        @error('start_time') <span class="crm-help">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.onboarding.first_date_label') }}</span>
                                        <input name="start_date" type="date" min="{{ now('Europe/Kyiv')->toDateString() }}" value="{{ old('start_date', $values['start_date']) }}" required class="crm-field">
                                        @error('start_date') <span class="crm-help">{{ $message }}</span> @enderror
                                    </label>
                                </div>
                                <p class="text-sm leading-6 text-slate-500">{{ __('app.onboarding.weekly_no_end_note') }}</p>
                            @endif

                            <div class="flex flex-col-reverse gap-3 border-t border-stone-100 pt-6 sm:flex-row sm:items-center sm:justify-between">
                                @if ($step > 1)
                                    <a href="{{ route('onboarding.show', ['step' => $step - 1]) }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold text-slate-600 transition hover:bg-brand-50 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                                        <x-ui.icon name="arrow-left" class="h-4 w-4" />
                                        {{ __('app.back') }}
                                    </a>
                                @else
                                    <span></span>
                                @endif
                                <x-ui.button type="submit" class="h-11 min-w-40">
                                    {{ __('app.continue') }}
                                    <x-ui.icon name="arrow-right" class="h-4 w-4" />
                                </x-ui.button>
                            </div>
                        </form>
                    @else
                        <div class="mt-7 space-y-5">
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach ([
                                    ['icon' => 'building-2', 'label' => __('app.onboarding.review_studio'), 'value' => $account?->name],
                                    ['icon' => 'map-pin', 'label' => __('app.onboarding.review_location'), 'value' => $stepTwo['location_name'] ?? ''],
                                    ['icon' => 'user-round', 'label' => __('app.onboarding.review_trainer'), 'value' => $stepThree['trainer_name'] ?? ''],
                                    ['icon' => 'sparkles', 'label' => __('app.onboarding.review_class'), 'value' => $stepFour['class_name'] ?? ''],
                                ] as $reviewItem)
                                    <div class="rounded-xl border border-stone-200 bg-brand-50/50 p-4">
                                        <div class="flex items-start gap-3">
                                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-violet-crm-100 text-brand-700">
                                                <x-ui.icon :name="$reviewItem['icon']" class="h-4 w-4" />
                                            </span>
                                            <div class="min-w-0">
                                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $reviewItem['label'] }}</p>
                                                <p class="mt-1 truncate font-semibold text-brand-700">{{ $reviewItem['value'] }}</p>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="rounded-xl border border-stone-200 p-4 text-sm leading-6 text-slate-600">
                                <div class="font-semibold text-brand-700">
                                    {{ $weekdayLabels[$stepFive['weekday'] ?? 1] ?? '' }},
                                    {{ $stepFive['start_time'] ?? '' }} · {{ $stepFive['start_date'] ?? '' }}
                                </div>
                                <p class="mt-2">{{ __('app.onboarding.guest_booking_note') }}</p>
                                <p class="mt-1">{{ __('app.onboarding.unpaid_booking_note') }}</p>
                            </div>

                            <div class="rounded-xl border border-brand-100 bg-brand-50 p-4 text-sm leading-6 text-slate-600">
                                <p class="font-semibold text-brand-700">{{ __('app.onboarding.locations_review_title') }}</p>
                                <p class="mt-1">{{ __('app.onboarding.active_location_review', ['name' => $stepTwo['location_name'] ?? '']) }}</p>
                                @if ($locationCount > 1)
                                    <ul class="mt-2 list-disc space-y-1 pl-5">
                                        @foreach (range(2, $locationCount) as $number)
                                            <li>{{ $account?->name }} — {{ $number }} · {{ __('app.onboarding.inactive_badge') }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                <p class="mt-2 text-xs">{{ __('app.onboarding.active_billing_note') }}</p>
                            </div>

                            <section class="rounded-2xl border border-violet-crm-100 bg-violet-crm-50 p-5">
                                <div class="flex items-start gap-3">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white">
                                        <x-ui.icon name="shield-check" class="h-5 w-5" />
                                    </span>
                                    <div>
                                        <h2 class="font-semibold text-brand-700">{{ __('app.onboarding.verify_phone_title') }}</h2>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.onboarding.verify_phone_copy') }}</p>
                                    </div>
                                </div>

                                @if (auth()->user()->phone_verified_at)
                                    <div class="mt-4 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                                        <x-ui.icon name="badge-check" class="h-5 w-5" />
                                        {{ __('app.onboarding.verified_phone', ['phone' => auth()->user()->phone]) }}
                                    </div>
                                @else
                                    <form method="POST" action="{{ route('onboarding.otp.send') }}" class="mt-5 space-y-4">
                                        @csrf
                                        <label class="block">
                                            <span class="crm-label">{{ __('app.phone') }}</span>
                                            <input name="phone" value="{{ old('phone', auth()->user()->phone) }}" required inputmode="tel" autocomplete="tel" class="crm-field" data-phone-mask data-phone-country="UA">
                                            @error('phone') <span class="crm-help">{{ $message }}</span> @enderror
                                        </label>
                                        @if ($turnstileSiteKey)
                                            <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}"></div>
                                        @endif
                                        @error('cf-turnstile-response') <span class="crm-help">{{ $message }}</span> @enderror
                                        @if ($otpSent)
                                            <x-ui.button
                                                type="submit"
                                                variant="secondary"
                                                data-otp-resend-button
                                                data-otp-countdown="{{ session('otp_resend_seconds', $otpResendSeconds) }}"
                                                data-otp-countdown-message="{{ __('app.onboarding.otp_resend_countdown') }}"
                                            >
                                                {{ __('app.onboarding.resend_code') }}
                                            </x-ui.button>
                                        @else
                                            <x-ui.button type="submit" variant="secondary">
                                                {{ __('app.onboarding.send_code') }}
                                            </x-ui.button>
                                        @endif
                                        <div class="text-sm text-slate-500" data-otp-countdown-label></div>
                                    </form>

                                    @if ($otpSent)
                                        <form method="POST" action="{{ route('onboarding.otp.verify') }}" class="mt-5 border-t border-violet-crm-100 pt-5">
                                            @csrf
                                            <label class="block">
                                                <span class="crm-label">{{ __('app.onboarding.otp_code_label') }}</span>
                                                <input name="otp_code" inputmode="numeric" autocomplete="one-time-code" maxlength="{{ config('customer_auth.otp.code_digits') }}" required class="crm-field text-center font-mono text-xl tracking-[0.35em]">
                                                @error('otp_code') <span class="crm-help">{{ $message }}</span> @enderror
                                            </label>
                                            @if (session('otp_debug_code'))
                                                <p class="mt-2 text-xs text-slate-500">{{ __('app.onboarding.testing_code', ['code' => session('otp_debug_code')]) }}</p>
                                            @endif
                                            <x-ui.button type="submit" class="mt-4">{{ __('app.onboarding.verify_code') }}</x-ui.button>
                                        </form>
                                    @endif
                                @endif
                            </section>

                            <div class="flex flex-col-reverse gap-3 border-t border-stone-100 pt-6 sm:flex-row sm:items-center sm:justify-between">
                                <a href="{{ route('onboarding.show', ['step' => 5]) }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold text-slate-600 transition hover:bg-brand-50 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                                    <x-ui.icon name="arrow-left" class="h-4 w-4" />
                                    {{ __('app.back') }}
                                </a>
                                <form method="POST" action="{{ route('onboarding.publish') }}">
                                    @csrf
                                    <x-ui.button type="submit" class="h-11 min-w-48" :disabled="! auth()->user()->phone_verified_at">
                                        {{ __('app.onboarding.publish') }}
                                        <x-ui.icon name="rocket" class="h-4 w-4" />
                                    </x-ui.button>
                                </form>
                            </div>
                            @if (! auth()->user()->phone_verified_at)
                                <p class="text-right text-xs leading-5 text-slate-500">{{ __('app.onboarding.publish_requires_verification') }}</p>
                            @endif
                        </div>
                    @endif
                </section>
            </div>
        </div>
    </main>
@endsection
