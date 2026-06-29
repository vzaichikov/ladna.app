@extends('layouts.app')

@section('title', __('app.system_settings').' - '.__('app.app_name'))

@push('head')
    <link rel="stylesheet" href="{{ $previewFontsUrl }}">
@endpush

@section('content')
    @php
        $settingsTabs = [
            'appearance' => [
                'label' => __('app.system_settings_tab_appearance'),
                'panel_id' => 'appearance',
            ],
            'support' => [
                'label' => __('app.system_settings_tab_support'),
                'panel_id' => 'support',
            ],
            'activity-log' => [
                'label' => __('app.system_settings_tab_activity_log'),
                'panel_id' => 'activity-log',
            ],
            'ai-owner' => [
                'label' => __('app.system_settings_tab_ai_owner'),
                'panel_id' => 'ai-owner',
            ],
        ];
        $requestedSettingsTab = old('settings_tab', request('tab', 'appearance'));
        $activeSettingsTab = array_key_exists($requestedSettingsTab, $settingsTabs)
            ? $requestedSettingsTab
            : 'appearance';
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.system_settings') }}</h1>
            <p class="crm-page-copy">{{ __('app.system_settings_copy') }}</p>
        </div>
        <x-ui.button type="submit" form="platform-settings-form" class="self-start">
            {{ __('app.save') }}
        </x-ui.button>
    </div>

    <x-ui.panel class="mt-6">
        <form
            id="platform-settings-form"
            method="POST"
            action="{{ route('platform.settings.update') }}"
            class="space-y-6"
            data-platform-settings-tabs
            data-active-tab="{{ $activeSettingsTab }}"
            data-ai-models-url="{{ route('platform.settings.ai-provider-models') }}"
            data-ai-model-loading="{{ __('app.ai_model_discovery_loading') }}"
            data-ai-model-placeholder="{{ __('app.ai_model_select_placeholder') }}"
            data-ai-model-empty="{{ __('app.ai_model_discovery_empty') }}"
            data-ai-model-failed="{{ __('app.ai_model_discovery_failed') }}"
            data-ai-model-missing-secret="{{ __('app.ai_model_discovery_missing_secret') }}"
            data-telegram-webhook-status-url="{{ route('platform.settings.owner-telegram-bot.webhook-status') }}"
            data-telegram-webhook-register-url="{{ route('platform.settings.owner-telegram-bot.register-webhook') }}"
            data-telegram-webhook-delete-url="{{ route('platform.settings.owner-telegram-bot.delete-webhook') }}"
            data-telegram-webhook-loading="{{ __('app.telegram_webhook_status_loading') }}"
            data-telegram-webhook-unknown="{{ __('app.telegram_webhook_status_unknown') }}"
            data-telegram-webhook-status-failed="{{ __('app.telegram_webhook_status_failed') }}"
            data-telegram-webhook-registered="{{ __('app.telegram_webhook_registered') }}"
            data-telegram-webhook-not-registered="{{ __('app.telegram_webhook_not_registered') }}"
            data-telegram-webhook-url-mismatch="{{ __('app.telegram_webhook_url_mismatch') }}"
        >
            @csrf
            @method('PUT')
            <input type="hidden" name="settings_tab" value="{{ $activeSettingsTab }}" data-platform-settings-active-tab>

            <div class="border-b border-stone-100 pb-4">
                <div class="grid gap-1 rounded-lg bg-stone-100 p-1 sm:inline-grid sm:grid-flow-col" role="tablist" aria-label="{{ __('app.system_settings') }}">
                    @foreach ($settingsTabs as $tabKey => $tab)
                        <button
                            type="button"
                            id="platform-settings-tab-{{ $tabKey }}"
                            class="crm-tab justify-start sm:justify-center"
                            role="tab"
                            data-platform-settings-tab="{{ $tabKey }}"
                            aria-controls="{{ $tab['panel_id'] }}"
                            aria-selected="{{ $activeSettingsTab === $tabKey ? 'true' : 'false' }}"
                            tabindex="{{ $activeSettingsTab === $tabKey ? '0' : '-1' }}"
                        >
                            {{ $tab['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            <section
                id="appearance"
                data-platform-settings-panel="appearance"
                role="tabpanel"
                aria-labelledby="platform-settings-tab-appearance"
                @class(['hidden' => $activeSettingsTab !== 'appearance'])
            >
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.font_family') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.font_preview') }}</p>
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($fontOptions as $fontKey => $font)
                        <label
                            class="cursor-pointer rounded-xl border p-4 transition {{ old('font_family', $currentFontKey) === $fontKey ? 'border-brand-600 bg-brand-50 ring-1 ring-brand-600' : 'border-stone-200 bg-white hover:border-brand-100 hover:bg-brand-50' }}"
                            style="font-family: '{{ $font['css_family'] }}', ui-sans-serif, system-ui, sans-serif;"
                        >
                            <input type="radio" name="font_family" value="{{ $fontKey }}" class="sr-only" @checked(old('font_family', $currentFontKey) === $fontKey)>
                            <span class="block text-xl font-semibold leading-none text-slate-950">{{ $font['label'] }}</span>
                            <span class="mt-3 block text-sm leading-6 text-slate-600">{{ __('app.app_tagline') }}</span>
                            <span class="mt-4 block text-xs font-semibold uppercase text-slate-400">{{ __('app.google_fonts') }}</span>
                        </label>
                    @endforeach
                </div>
                @error('font_family')
                    <span class="crm-help">{{ $message }}</span>
                @enderror
            </section>

            <section
                id="support"
                data-platform-settings-panel="support"
                role="tabpanel"
                aria-labelledby="platform-settings-tab-support"
                @class(['hidden' => $activeSettingsTab !== 'support'])
            >
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.system_support') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.system_support_copy') }}</p>
                <label class="mt-4 block">
                    <span class="crm-label">{{ __('app.support_url') }}</span>
                    <input name="support_url" type="url" value="{{ old('support_url', $supportUrl) }}" class="crm-field" placeholder="https://t.me/ladna_support">
                    @error('support_url')
                        <span class="crm-help">{{ $message }}</span>
                    @enderror
                </label>
            </section>

            <section
                id="activity-log"
                data-platform-settings-panel="activity-log"
                role="tabpanel"
                aria-labelledby="platform-settings-tab-activity-log"
                @class(['hidden' => $activeSettingsTab !== 'activity-log'])
            >
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.system_activity_log_settings') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.system_activity_log_settings_copy') }}</p>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <label class="flex items-start gap-3 rounded-xl border border-stone-200 bg-white p-4">
                        <input name="activity_log_enabled" type="hidden" value="0">
                        <input name="activity_log_enabled" type="checkbox" value="1" @checked(old('activity_log_enabled', $activityLogEnabled)) class="crm-checkbox mt-1">
                        <span>
                            <span class="block text-sm font-semibold text-slate-950">{{ __('app.activity_log_enabled') }}</span>
                            <span class="mt-1 block text-sm leading-6 text-slate-500">{{ __('app.activity_log_enabled_hint') }}</span>
                        </span>
                    </label>

                    <label class="block rounded-xl border border-stone-200 bg-white p-4">
                        <span class="crm-label">{{ __('app.activity_log_retention_days') }}</span>
                        <input
                            name="activity_log_retention_days"
                            type="number"
                            min="{{ $activityLogMinRetentionDays }}"
                            max="{{ $activityLogMaxRetentionDays }}"
                            value="{{ old('activity_log_retention_days', $activityLogRetentionDays) }}"
                            class="crm-field"
                            required
                        >
                        <span class="mt-2 block text-sm leading-6 text-slate-500">{{ __('app.activity_log_retention_days_hint', ['min' => $activityLogMinRetentionDays, 'max' => $activityLogMaxRetentionDays]) }}</span>
                        @error('activity_log_retention_days')
                            <span class="crm-help">{{ $message }}</span>
                        @enderror
                    </label>
                </div>
            </section>

            <section
                id="ai-owner"
                data-platform-settings-panel="ai-owner"
                role="tabpanel"
                aria-labelledby="platform-settings-tab-ai-owner"
                @class(['hidden' => $activeSettingsTab !== 'ai-owner'])
            >
                <div class="max-w-6xl space-y-6">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.platform_ai_owner_bot') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.platform_ai_owner_bot_copy') }}</p>
                    </div>

                    <div class="grid gap-5 lg:grid-cols-2">
                        <label class="flex items-start gap-3 rounded-xl border border-stone-200 bg-white p-4">
                            <input type="hidden" name="owner_ai_assistant_enabled" value="0">
                            <input type="checkbox" name="owner_ai_assistant_enabled" value="1" class="crm-checkbox mt-1" @checked((bool) old('owner_ai_assistant_enabled', $platformAiSetting->owner_ai_assistant_enabled))>
                            <span>
                                <span class="block text-sm font-semibold text-slate-950">{{ __('app.owner_ai_assistant_enabled') }}</span>
                                <span class="mt-1 block text-sm leading-6 text-slate-500">{{ __('app.owner_ai_assistant_enabled_copy') }}</span>
                            </span>
                        </label>

                        <label class="block rounded-xl border border-stone-200 bg-white p-4">
                            <span class="crm-label">{{ __('app.ai_active_provider') }}</span>
                            <select name="ai_active_provider" class="crm-field" data-ai-active-provider>
                                <option value="">{{ __('app.none') }}</option>
                                @foreach ($aiProviders as $provider)
                                    <option value="{{ $provider->value }}" @selected(old('ai_active_provider', $platformAiSetting->active_provider?->value) === $provider->value)>
                                        {{ __($provider->labelKey()) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('ai_active_provider')
                                <span class="crm-help">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="block">
                            <span class="crm-label">{{ __('app.ai_bot_display_name') }}</span>
                            <input name="ai_bot_display_name" value="{{ old('ai_bot_display_name', $platformAiSetting->bot_display_name) }}" class="crm-field" maxlength="80" placeholder="{{ __('app.ai_bot_display_name_placeholder') }}">
                            @error('ai_bot_display_name')
                                <span class="crm-help">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="block lg:col-span-2">
                            <span class="crm-label">{{ __('app.ai_internal_instructions') }}</span>
                            <textarea name="ai_internal_instructions" rows="4" class="crm-field" maxlength="5000" placeholder="{{ __('app.ai_internal_instructions_placeholder') }}">{{ old('ai_internal_instructions', $platformAiSetting->internal_instructions) }}</textarea>
                            @error('ai_internal_instructions')
                                <span class="crm-help">{{ $message }}</span>
                            @enderror
                        </label>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-3">
                        @foreach ($aiProviders as $provider)
                            @php($credential = $platformAiProviderCredentials->get($provider->value))
                            <div class="rounded-xl border border-stone-200 bg-white p-4">
                                <h3 class="font-semibold text-slate-950">{{ __($provider->labelKey()) }}</h3>
                                <p class="mt-2 min-h-12 text-sm leading-6 text-slate-500">{{ __('app.ai_provider_'.$provider->value.'_copy') }}</p>

                                <label class="mt-4 block">
                                    <span class="crm-label">{{ __('app.model') }}</span>
                                    @php($selectedModel = old("ai_provider_models.{$provider->value}", $credential?->model))
                                    <select
                                        name="ai_provider_models[{{ $provider->value }}]"
                                        class="crm-field"
                                        data-ai-model-select="{{ $provider->value }}"
                                        data-current-model="{{ $selectedModel }}"
                                    >
                                        <option value="">{{ __('app.ai_model_select_placeholder') }}</option>
                                        @if ($selectedModel)
                                            <option value="{{ $selectedModel }}" selected>{{ $selectedModel }}</option>
                                        @endif
                                    </select>
                                    <div class="mt-2 flex items-center justify-between gap-3">
                                        <span class="text-xs text-slate-500" data-ai-model-status="{{ $provider->value }}"></span>
                                        <button type="button" class="text-xs font-semibold text-brand-700 transition hover:text-brand-900" data-ai-model-refresh="{{ $provider->value }}">
                                            {{ __('app.refresh') }}
                                        </button>
                                    </div>
                                    @error("ai_provider_models.{$provider->value}")
                                        <span class="crm-help">{{ $message }}</span>
                                    @enderror
                                </label>

                                <label class="mt-4 block">
                                    <span class="crm-label">{{ __('app.ai_provider_secret') }}</span>
                                    <input type="password" name="ai_provider_credentials[{{ $provider->value }}]" class="crm-field" maxlength="4000" autocomplete="off" placeholder="{{ $credential?->is_configured ? __('app.keep_existing_secret') : __('app.ai_provider_secret_placeholder') }}">
                                    @error("ai_provider_credentials.{$provider->value}")
                                        <span class="crm-help">{{ $message }}</span>
                                    @enderror
                                </label>
                            </div>
                        @endforeach
                    </div>

                    <div class="rounded-xl border border-stone-200 bg-white p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="font-semibold text-slate-950">{{ __('app.global_owner_telegram_bot') }}</h3>
                                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.global_owner_telegram_bot_copy') }}</p>
                            </div>
                            @if ($ownerTelegramBotInstallation?->is_enabled)
                                <span class="crm-status-active">{{ __('app.active') }}</span>
                            @else
                                <span class="crm-status-muted">{{ __('app.disabled') }}</span>
                            @endif
                        </div>

                        <div class="mt-5 grid gap-4 lg:grid-cols-2">
                            <label class="flex items-start gap-3">
                                <input type="hidden" name="owner_telegram_bot_enabled" value="0">
                                <input type="checkbox" name="owner_telegram_bot_enabled" value="1" class="crm-checkbox mt-1" @checked((bool) old('owner_telegram_bot_enabled', $ownerTelegramBotInstallation?->is_enabled ?? false))>
                                <span>
                                    <span class="block text-sm font-semibold text-slate-950">{{ __('app.enabled') }}</span>
                                    <span class="mt-1 block text-sm leading-6 text-slate-500">{{ __('app.global_owner_telegram_bot_enabled_copy') }}</span>
                                </span>
                            </label>

                            <label class="block">
                                <span class="crm-label">{{ __('app.telegram_bot_username') }}</span>
                                <input name="owner_telegram_bot_username" value="{{ old('owner_telegram_bot_username', $ownerTelegramBotInstallation?->bot_username) }}" class="crm-field" maxlength="255" placeholder="@ladna_owner_bot">
                            </label>

                            <label class="block">
                                <span class="crm-label">{{ __('app.telegram_bot_token') }}</span>
                                <input type="password" name="owner_telegram_bot_token" class="crm-field" maxlength="255" autocomplete="off" placeholder="{{ $ownerTelegramBotInstallation?->token_last_four ? __('app.keep_existing_token_last_four', ['last_four' => $ownerTelegramBotInstallation->token_last_four]) : __('app.telegram_bot_token_placeholder') }}">
                                @error('owner_telegram_bot_token')
                                    <span class="crm-help">{{ $message }}</span>
                                @enderror
                            </label>

                            @if ($ownerTelegramBotInstallation?->webhook_url)
                                <label class="block">
                                    <span class="crm-label">{{ __('app.telegram_webhook_url') }}</span>
                                    <input value="{{ $ownerTelegramBotInstallation->webhook_url }}" readonly class="crm-field font-mono text-xs">
                                </label>
                            @endif
                        </div>

                        <div class="mt-5 rounded-lg border border-stone-200 bg-stone-50 p-4" data-telegram-webhook-panel>
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-950">{{ __('app.telegram_webhook_status') }}</h4>
                                    <p class="mt-1 text-sm text-slate-500" data-telegram-webhook-summary>{{ __('app.telegram_webhook_status_unknown') }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="rounded-lg border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-brand-200 hover:text-brand-700" data-telegram-webhook-refresh>
                                        {{ __('app.refresh') }}
                                    </button>
                                    <button type="button" class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white shadow-sm shadow-brand-600/20 transition hover:bg-brand-700" data-telegram-webhook-register>
                                        {{ __('app.telegram_register_webhook') }}
                                    </button>
                                    <button type="button" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100" data-telegram-webhook-delete>
                                        {{ __('app.telegram_delete_webhook') }}
                                    </button>
                                </div>
                            </div>

                            <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
                                <div>
                                    <dt class="text-xs font-semibold uppercase text-slate-400">{{ __('app.telegram_webhook_local_status') }}</dt>
                                    <dd class="mt-1 text-slate-700" data-telegram-webhook-local>{{ $ownerTelegramBotInstallation?->status ? __('app.'.$ownerTelegramBotInstallation->status) : __('app.telegram_not_checked') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase text-slate-400">{{ __('app.telegram_webhook_live_status') }}</dt>
                                    <dd class="mt-1 text-slate-700" data-telegram-webhook-live>{{ __('app.telegram_not_checked') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase text-slate-400">{{ __('app.telegram_last_synced_at') }}</dt>
                                    <dd class="mt-1 text-slate-700" data-telegram-webhook-synced>{{ $ownerTelegramBotInstallation?->last_webhook_synced_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase text-slate-400">{{ __('app.telegram_pending_updates') }}</dt>
                                    <dd class="mt-1 text-slate-700" data-telegram-webhook-pending>—</dd>
                                </div>
                                <div class="md:col-span-2">
                                    <dt class="text-xs font-semibold uppercase text-slate-400">{{ __('app.telegram_registered_url') }}</dt>
                                    <dd class="mt-1 break-all font-mono text-xs text-slate-700" data-telegram-webhook-url>—</dd>
                                </div>
                                <div class="hidden md:col-span-2" data-telegram-webhook-error-row>
                                    <dt class="text-xs font-semibold uppercase text-rose-400">{{ __('app.telegram_last_error') }}</dt>
                                    <dd class="mt-1 text-rose-700" data-telegram-webhook-error></dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex justify-end border-t border-stone-100 pt-5">
                <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>
@endsection
