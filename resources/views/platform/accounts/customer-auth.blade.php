@extends('layouts.app')

@section('title', __('app.studio_capabilities_settings').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="crm-page-kicker">{{ __('app.platform') }}</div>
            <h1 class="crm-page-title">{{ __('app.studio_capabilities_settings') }}</h1>
            <p class="crm-page-copy">{{ $account->name }} · {{ __('app.studio_capabilities_settings_copy') }}</p>
        </div>
        <x-ui.button :href="route('platform.accounts.show', $account)" variant="secondary">
            {{ __('app.account') }}
        </x-ui.button>
    </div>

    <form method="POST" action="{{ route('platform.accounts.customer-auth.update', $account) }}" data-sms-sending-settings class="mt-6 max-w-6xl space-y-5">
        @csrf
        @method('PUT')

        <div class="grid gap-6 lg:grid-cols-2 lg:items-start">
            <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.studio_capabilities_features_title') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.studio_capabilities_features_copy') }}</p>

                <div class="mt-5 grid gap-3">
                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold text-slate-800">
                        <input type="hidden" name="allow_otp" value="0">
                        <input name="allow_otp" type="checkbox" value="1" @checked(old('allow_otp', $settings->allow_otp)) class="crm-checkbox mt-0.5">
                        <span class="grid gap-1">
                            <span>{{ __('app.enable_customer_otp_tariff') }}</span>
                            <span class="text-sm font-normal leading-6 text-slate-500">{{ __('app.studio_capability_customer_otp_hint') }}</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold text-slate-800">
                        <input type="hidden" name="allow_rtsp_cameras" value="0">
                        <input name="allow_rtsp_cameras" type="checkbox" value="1" @checked(old('allow_rtsp_cameras', $account->allow_rtsp_cameras)) class="crm-checkbox mt-0.5">
                        <span class="grid gap-1">
                            <span>{{ __('app.enable_rtsp_camera_support') }}</span>
                            <span class="text-sm font-normal leading-6 text-slate-500">{{ __('app.studio_capability_rtsp_camera_hint') }}</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold text-slate-800">
                        <input type="hidden" name="enable_people_counter" value="0">
                        <input name="enable_people_counter" type="checkbox" value="1" @checked(old('enable_people_counter', $account->enable_people_counter)) class="crm-checkbox mt-0.5">
                        <span class="grid gap-1">
                            <span>{{ __('app.enable_people_counter') }}</span>
                            <span class="text-sm font-normal leading-6 text-slate-500">{{ __('app.studio_capability_people_counter_hint') }}</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold text-slate-800">
                        <input type="hidden" name="enable_customer_notifications" value="0">
                        <input name="enable_customer_notifications" type="checkbox" value="1" @checked(old('enable_customer_notifications', $account->enable_customer_notifications)) class="crm-checkbox mt-0.5">
                        <span class="grid gap-1">
                            <span>{{ __('app.enable_customer_notifications') }}</span>
                            <span class="text-sm font-normal leading-6 text-slate-500">{{ __('app.studio_capability_customer_notifications_hint') }}</span>
                        </span>
                    </label>
                </div>
            </section>

            <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.studio_capabilities_sms_title') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.studio_capabilities_sms_copy') }}</p>

                @php
                    $selectedSmsSendingMode = old('sms_sending_mode', $settings->sms_sending_mode?->value ?? \App\Enums\SmsSendingMode::Disabled->value);
                @endphp

                <div class="mt-5 grid gap-3">
                    @foreach ($smsSendingModes as $mode)
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm has-checked:border-violet-crm-500 has-checked:bg-violet-crm-50">
                            <input
                                type="radio"
                                name="sms_sending_mode"
                                value="{{ $mode->value }}"
                                class="crm-radio mt-0.5"
                                @checked($selectedSmsSendingMode === $mode->value)
                            >
                            <span class="grid gap-1">
                                <span class="font-semibold text-slate-900">{{ __('app.sms_sending_mode_'.$mode->value) }}</span>
                                <span class="font-normal leading-6 text-slate-500">{{ __('app.sms_sending_mode_'.$mode->value.'_copy') }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('sms_sending_mode') <span class="crm-help">{{ $message }}</span> @enderror

                <div
                    data-sms-mode-panel="{{ \App\Enums\SmsSendingMode::OwnGateway->value }}"
                    class="{{ $selectedSmsSendingMode === \App\Enums\SmsSendingMode::OwnGateway->value ? '' : 'hidden' }}"
                >
                    <label class="mt-5 block">
                        <span class="crm-label">{{ __('app.sms_active_provider') }}</span>
                        <select
                            name="sms_provider"
                            class="crm-field"
                            @required($selectedSmsSendingMode === \App\Enums\SmsSendingMode::OwnGateway->value)
                            @disabled($selectedSmsSendingMode !== \App\Enums\SmsSendingMode::OwnGateway->value)
                        >
                            <option value="">{{ __('app.choose') }}</option>
                            @foreach ($smsProviders as $provider)
                                <option value="{{ $provider->value }}" @selected(old('sms_provider', $settings->sms_provider) === $provider->value)>
                                    {{ config('integrations.providers.'.$provider->value.'.label') }}
                                </option>
                            @endforeach
                        </select>
                        @error('sms_provider') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                    {{ __('app.customer_otp_tariff_settings_hint') }}
                </div>
            </section>
        </div>

        <x-ui.button type="submit">
            <x-ui.icon name="edit" class="h-4 w-4" />
            {{ __('app.save') }}
        </x-ui.button>
    </form>
@endsection
