<?php

namespace App\Http\Controllers;

use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use App\Http\Requests\UpdateAccountAiTelegramSettingsRequest;
use App\Models\Account;
use App\Models\TelegramBotInstallation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountAiTelegramSettingsController extends Controller
{
    public function update(UpdateAccountAiTelegramSettingsRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($account, $validated): void {
            $profile = TelegramBotProfile::Customer;
            $installation = $this->telegramInstallation($account, $profile);
            $token = data_get($validated, "telegram_bots.{$profile->value}.token");

            if (filled($token)) {
                $installation->encrypted_token = $token;
                $installation->token_last_four = substr((string) $token, -4);
            }

            $installation->fill([
                'account_id' => $account->id,
                'scope_type' => 'account',
                'scope_id' => $account->id,
                'profile' => $profile->value,
                'bot_username' => data_get($validated, "telegram_bots.{$profile->value}.bot_username") ?: $installation->bot_username,
                'is_enabled' => (bool) data_get($validated, "telegram_profiles.{$profile->value}.enabled", false),
                'status' => $installation->tokenValue() || filled($token) ? 'configured' : 'pending',
            ]);

            $webhookKey = $installation->webhookKey();

            if ($webhookKey) {
                $installation->webhook_url = route('api.v1.telegram.webhooks.handle', $webhookKey);
            }

            $installation->save();

            $mode = data_get($validated, "telegram_profiles.{$profile->value}.mode", TelegramBotMode::Disabled->value);

            $account->telegramBotProfiles()->updateOrCreate(
                ['profile' => $profile->value],
                [
                    'account_id' => $account->id,
                    'profile' => $profile->value,
                    'mode' => $mode,
                    'is_enabled' => (bool) data_get($validated, "telegram_profiles.{$profile->value}.enabled", false),
                    'welcome_message' => data_get($validated, "telegram_profiles.{$profile->value}.welcome_message"),
                ],
            );
        });

        return redirect()->route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'ai'])
            ->with('status', __('app.ai_telegram_settings_updated'));
    }

    private function telegramInstallation(Account $account, TelegramBotProfile $profile): TelegramBotInstallation
    {
        $installation = $account->telegramBotInstallations()->firstOrNew([
            'scope_type' => 'account',
            'scope_id' => $account->id,
            'profile' => $profile->value,
        ]);

        if (! $installation->exists || ! $installation->webhookKey()) {
            $webhookKey = TelegramBotInstallation::generateWebhookKey();
            $webhookSecret = Str::random(32);

            $installation->fill([
                'account_id' => $account->id,
                'scope_type' => 'account',
                'scope_id' => $account->id,
                'profile' => $profile->value,
                'encrypted_webhook_key' => $webhookKey,
                'webhook_key_hash' => TelegramBotInstallation::hashWebhookSecret($webhookKey),
                'encrypted_webhook_secret' => $webhookSecret,
                'webhook_secret_token_hash' => TelegramBotInstallation::hashWebhookSecret($webhookSecret),
            ]);
        }

        return $installation;
    }
}
