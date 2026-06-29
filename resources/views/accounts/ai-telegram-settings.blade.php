<form method="POST" action="{{ route('dashboard.accounts.ai-telegram-settings.update', $account) }}" class="mt-6 max-w-4xl space-y-6">
    @csrf
    @method('PUT')

    <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.customer_telegram_bot_settings') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.customer_telegram_bot_settings_copy') }}</p>
        </div>

        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            @foreach ($telegramBotProfilesList as $profile)
                @php($installation = $telegramBotInstallations->get($profile->value))
                @php($profileSetting = $telegramBotProfiles->get($profile->value))
                <div class="rounded-lg border border-stone-200 bg-slate-50 p-4 lg:col-span-2">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="font-semibold text-slate-950">{{ __($profile->labelKey()) }}</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.telegram_bot_profile_'.$profile->value.'_copy') }}</p>
                        </div>
                        @if ($installation?->is_enabled)
                            <span class="crm-status-active">{{ __('app.active') }}</span>
                        @else
                            <span class="crm-status-muted">{{ __('app.disabled') }}</span>
                        @endif
                    </div>

                    <div class="mt-5 grid gap-4 lg:grid-cols-2">
                        <label class="flex items-start gap-3">
                            <input type="hidden" name="telegram_profiles[{{ $profile->value }}][enabled]" value="0">
                            <input type="checkbox" name="telegram_profiles[{{ $profile->value }}][enabled]" value="1" class="crm-checkbox mt-1" @checked((bool) old("telegram_profiles.{$profile->value}.enabled", $profileSetting?->is_enabled ?? false))>
                            <span>
                                <span class="block text-sm font-semibold text-slate-950">{{ __('app.enabled') }}</span>
                                <span class="mt-1 block text-sm leading-6 text-slate-500">{{ __('app.customer_telegram_bot_enabled_copy') }}</span>
                            </span>
                        </label>

                        <label class="block">
                            <span class="crm-label">{{ __('app.mode') }}</span>
                            <select name="telegram_profiles[{{ $profile->value }}][mode]" class="crm-field">
                                @foreach ($telegramBotModes as $mode)
                                    <option value="{{ $mode->value }}" @selected(old("telegram_profiles.{$profile->value}.mode", $profileSetting?->mode?->value ?? \App\Enums\TelegramBotMode::Disabled->value) === $mode->value)>
                                        {{ __($mode->labelKey()) }}
                                    </option>
                                @endforeach
                            </select>
                            @error("telegram_profiles.{$profile->value}.mode")
                                <span class="crm-help">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="block">
                            <span class="crm-label">{{ __('app.telegram_bot_token') }}</span>
                            <input type="password" name="telegram_bots[{{ $profile->value }}][token]" class="crm-field" maxlength="255" autocomplete="off" placeholder="{{ $installation?->token_last_four ? __('app.keep_existing_token_last_four', ['last_four' => $installation->token_last_four]) : __('app.telegram_bot_token_placeholder') }}">
                            @error("telegram_bots.{$profile->value}.token")
                                <span class="crm-help">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="block">
                            <span class="crm-label">{{ __('app.telegram_bot_username') }}</span>
                            <input name="telegram_bots[{{ $profile->value }}][bot_username]" value="{{ old("telegram_bots.{$profile->value}.bot_username", $installation?->bot_username) }}" class="crm-field" maxlength="255" placeholder="@ladna_studio_bot">
                        </label>

                        <label class="block lg:col-span-2">
                            <span class="crm-label">{{ __('app.welcome_message') }}</span>
                            <textarea name="telegram_profiles[{{ $profile->value }}][welcome_message]" rows="3" class="crm-field" maxlength="1000">{{ old("telegram_profiles.{$profile->value}.welcome_message", $profileSetting?->welcome_message) }}</textarea>
                        </label>

                        @if ($installation?->webhook_url)
                            <label class="block lg:col-span-2">
                                <span class="crm-label">{{ __('app.telegram_webhook_url') }}</span>
                                <input value="{{ $installation->webhook_url }}" readonly class="crm-field font-mono text-xs">
                            </label>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <x-ui.button type="submit">
        <x-ui.icon name="save" class="h-4 w-4" />
        {{ __('app.save_changes') }}
    </x-ui.button>
</form>
