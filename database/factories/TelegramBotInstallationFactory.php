<?php

namespace Database\Factories;

use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\TelegramBotInstallation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TelegramBotInstallation>
 */
class TelegramBotInstallationFactory extends Factory
{
    public function configure(): self
    {
        return $this->afterMaking(function (TelegramBotInstallation $installation): void {
            if ($installation->scope_type === 'account' && $installation->account_id && (int) $installation->scope_id === 0) {
                $installation->scope_id = $installation->account_id;
            }
        })->afterCreating(function (TelegramBotInstallation $installation): void {
            if ($installation->scope_type === 'account' && $installation->account_id && (int) $installation->scope_id === 0) {
                $installation->forceFill(['scope_id' => $installation->account_id])->save();
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = '123456:'.Str::random(32);
        $webhookKey = TelegramBotInstallation::generateWebhookKey();
        $webhookSecret = Str::random(32);

        return [
            'account_id' => Account::factory(),
            'scope_type' => 'account',
            'scope_id' => 0,
            'profile' => TelegramBotProfile::Owner->value,
            'bot_username' => fake()->userName().'_bot',
            'encrypted_token' => $token,
            'token_last_four' => substr($token, -4),
            'encrypted_webhook_key' => $webhookKey,
            'webhook_key_hash' => TelegramBotInstallation::hashWebhookSecret($webhookKey),
            'encrypted_webhook_secret' => $webhookSecret,
            'webhook_secret_token_hash' => TelegramBotInstallation::hashWebhookSecret($webhookSecret),
            'webhook_url' => null,
            'status' => 'configured',
            'is_enabled' => true,
            'last_webhook_synced_at' => null,
        ];
    }

    public function platformOwner(): self
    {
        return $this->state([
            'account_id' => null,
            'scope_type' => 'platform',
            'scope_id' => 0,
            'profile' => TelegramBotProfile::Owner->value,
        ]);
    }
}
