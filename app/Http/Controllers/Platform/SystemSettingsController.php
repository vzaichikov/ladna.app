<?php

namespace App\Http\Controllers\Platform;

use App\Enums\AiProvider;
use App\Enums\TelegramBotProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Models\PlatformAiProviderCredential;
use App\Models\PlatformAiSetting;
use App\Models\SystemSetting;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramBroadcastTarget;
use App\Support\AccountActivityLogSettings;
use App\Support\SystemAppearance;
use App\Support\Telegram\Announcements\TelegramBroadcastTargetVerifier;
use App\Support\Telegram\TelegramWebhookManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

class SystemSettingsController extends Controller
{
    public function edit(): View
    {
        $fontOptions = SystemAppearance::fontOptions();
        $ownerTelegramBotInstallation = TelegramBotInstallation::query()
            ->where('scope_type', 'platform')
            ->where('scope_id', 0)
            ->where('profile', TelegramBotProfile::Owner->value)
            ->first();

        return view('platform.settings.edit', [
            'fontOptions' => $fontOptions,
            'currentFontKey' => SystemAppearance::currentFontKey(),
            'previewFontsUrl' => SystemAppearance::googleFontsUrl($fontOptions),
            'supportUrl' => SystemSetting::stringValue(SystemSetting::SupportUrlKey),
            'activityLogEnabled' => AccountActivityLogSettings::enabled(),
            'activityLogRetentionDays' => AccountActivityLogSettings::retentionDays(),
            'activityLogMinRetentionDays' => AccountActivityLogSettings::MinRetentionDays,
            'activityLogMaxRetentionDays' => AccountActivityLogSettings::MaxRetentionDays,
            'aiProviders' => AiProvider::cases(),
            'platformAiSetting' => PlatformAiSetting::current(),
            'platformAiProviderCredentials' => PlatformAiProviderCredential::query()
                ->get()
                ->keyBy(fn (PlatformAiProviderCredential $credential): string => $credential->provider->value),
            'ownerTelegramBotInstallation' => $ownerTelegramBotInstallation,
            'foundersTelegramTarget' => $ownerTelegramBotInstallation?->broadcastTargets()
                ->where('purpose', TelegramBroadcastTarget::PurposeLadnaFounders)
                ->first(),
        ]);
    }

    public function update(
        UpdateSystemSettingsRequest $request,
        TelegramWebhookManager $telegramWebhooks,
        TelegramBroadcastTargetVerifier $targetVerifier,
    ): RedirectResponse {
        $validated = $request->validated();

        $ownerTelegramBotInstallation = DB::transaction(function () use ($request, $validated): TelegramBotInstallation {
            SystemSetting::setValue(SystemAppearance::FontSettingKey, $validated['font_family']);
            SystemSetting::setValue(SystemSetting::SupportUrlKey, $validated['support_url'] ?? null);
            AccountActivityLogSettings::setEnabled(
                $request->has('activity_log_enabled')
                    ? $request->boolean('activity_log_enabled')
                    : AccountActivityLogSettings::enabled()
            );
            AccountActivityLogSettings::setRetentionDays(
                (int) ($validated['activity_log_retention_days'] ?? AccountActivityLogSettings::retentionDays())
            );

            $this->savePlatformAi($validated);

            return $this->saveOwnerTelegramBot($validated);
        });

        $activeTab = $validated['settings_tab'] ?? null;
        $foundersWarning = $this->saveFoundersTelegramTarget(
            $validated,
            $ownerTelegramBotInstallation,
            $targetVerifier,
        );
        $telegramWebhookResult = null;

        if ($ownerTelegramBotInstallation->is_enabled && $ownerTelegramBotInstallation->tokenValue()) {
            $telegramWebhookResult = $telegramWebhooks->register($ownerTelegramBotInstallation);
        }

        $redirect = redirect()
            ->route('platform.settings.edit', $activeTab ? ['tab' => $activeTab] : [])
            ->with('status', __('app.system_settings_updated'));

        $warnings = array_values(array_filter([
            $foundersWarning,
            $telegramWebhookResult && ! $telegramWebhookResult['ok']
                ? $telegramWebhookResult['message']
                : null,
        ]));

        if ($warnings !== []) {
            $redirect->with('warning', implode(' ', $warnings));
        }

        return $redirect;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function savePlatformAi(array $validated): void
    {
        $activeProvider = $validated['ai_active_provider'] ?? null;
        $activeModel = $activeProvider ? (string) data_get($validated, "ai_provider_models.{$activeProvider}", '') : null;

        PlatformAiSetting::current()->fill([
            'owner_ai_assistant_enabled' => (bool) ($validated['owner_ai_assistant_enabled'] ?? false),
            'active_provider' => $activeProvider ?: null,
            'active_model' => filled($activeModel) ? $activeModel : null,
            'bot_display_name' => $validated['ai_bot_display_name'] ?? null,
            'internal_instructions' => $validated['ai_internal_instructions'] ?? null,
        ])->save();

        foreach (AiProvider::cases() as $provider) {
            $credential = PlatformAiProviderCredential::query()->firstOrNew([
                'provider' => $provider->value,
            ]);
            $credentials = is_array($credential->credentials) ? $credential->credentials : [];
            $secret = data_get($validated, "ai_provider_credentials.{$provider->value}");

            if (filled($secret)) {
                $credentials[$this->credentialKey($provider)] = $secret;
            }

            $model = data_get($validated, "ai_provider_models.{$provider->value}");

            $credential->fill([
                'provider' => $provider->value,
                'model' => filled($model) ? $model : null,
                'credentials' => $credentials ?: null,
                'is_configured' => filled($model) || $credentials !== [],
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function saveOwnerTelegramBot(array $validated): TelegramBotInstallation
    {
        $installation = TelegramBotInstallation::query()->firstOrNew([
            'scope_type' => 'platform',
            'scope_id' => 0,
            'profile' => TelegramBotProfile::Owner->value,
        ]);

        if (! $installation->exists || ! $installation->webhookKey()) {
            $webhookKey = TelegramBotInstallation::generateWebhookKey();
            $webhookSecret = Str::random(32);

            $installation->fill([
                'account_id' => null,
                'scope_type' => 'platform',
                'scope_id' => 0,
                'profile' => TelegramBotProfile::Owner->value,
                'encrypted_webhook_key' => $webhookKey,
                'webhook_key_hash' => TelegramBotInstallation::hashWebhookSecret($webhookKey),
                'encrypted_webhook_secret' => $webhookSecret,
                'webhook_secret_token_hash' => TelegramBotInstallation::hashWebhookSecret($webhookSecret),
            ]);
        }

        $token = $validated['owner_telegram_bot_token'] ?? null;

        if (filled($token)) {
            $installation->encrypted_token = $token;
            $installation->token_last_four = substr((string) $token, -4);
        }

        $webhookKey = $installation->webhookKey();

        $installation->fill([
            'account_id' => null,
            'scope_type' => 'platform',
            'scope_id' => 0,
            'profile' => TelegramBotProfile::Owner->value,
            'bot_username' => ($validated['owner_telegram_bot_username'] ?? null) ?: $installation->bot_username,
            'is_enabled' => (bool) ($validated['owner_telegram_bot_enabled'] ?? false),
            'status' => $installation->tokenValue() || filled($token) ? 'configured' : 'pending',
            'webhook_url' => $webhookKey ? route('api.v1.telegram.webhooks.handle', $webhookKey) : null,
        ])->save();

        return $installation;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function saveFoundersTelegramTarget(
        array $validated,
        TelegramBotInstallation $installation,
        TelegramBroadcastTargetVerifier $verifier,
    ): ?string {
        $chatId = trim((string) ($validated['founders_telegram_chat_id'] ?? ''));
        $title = trim((string) ($validated['founders_telegram_title'] ?? ''));
        $shouldEnable = (bool) ($validated['founders_telegram_enabled'] ?? false);
        $target = TelegramBroadcastTarget::query()->firstOrNew([
            'telegram_bot_installation_id' => $installation->id,
            'purpose' => TelegramBroadcastTarget::PurposeLadnaFounders,
        ]);

        if ($chatId === '') {
            if ($target->exists) {
                $target->forceFill(['is_enabled' => false])->save();
            }

            return null;
        }

        $changed = ! $target->exists
            || $target->telegram_chat_id !== $chatId
            || $target->title !== $title;

        $target->fill([
            'telegram_chat_id' => $chatId,
            'title' => $title,
        ]);

        if ($changed) {
            $target->forceFill([
                'chat_type' => null,
                'verified_at' => null,
                'is_enabled' => false,
            ]);
        }

        if (! $shouldEnable) {
            $target->forceFill(['is_enabled' => false])->save();

            return null;
        }

        if (! $changed && $target->is_enabled && $target->verified_at) {
            return null;
        }

        try {
            $verified = $verifier->verify($installation, $chatId, $title);
        } catch (InvalidArgumentException $exception) {
            $target->forceFill(['is_enabled' => false])->save();

            return __('app.telegram_founders_verification_failed', [
                'error' => $exception->getMessage(),
            ]);
        }

        $target->forceFill([
            'telegram_chat_id' => $verified->chatId,
            'title' => $verified->title,
            'chat_type' => $verified->chatType,
            'is_enabled' => true,
            'verified_at' => now(),
        ])->save();

        return null;
    }

    private function credentialKey(AiProvider $provider): string
    {
        return match ($provider) {
            AiProvider::OpenAiApiKey, AiProvider::OllamaCloud => 'api_key',
            AiProvider::OpenAiDeviceCode => 'device_one_time_code',
        };
    }
}
