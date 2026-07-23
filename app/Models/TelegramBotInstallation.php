<?php

namespace App\Models;

use App\Enums\TelegramBotProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['account_id', 'scope_type', 'scope_id', 'profile', 'bot_username', 'encrypted_token', 'token_last_four', 'encrypted_webhook_key', 'webhook_key_hash', 'encrypted_webhook_secret', 'webhook_secret_token_hash', 'webhook_url', 'status', 'is_enabled', 'last_webhook_synced_at'])]
#[Hidden(['encrypted_token', 'encrypted_webhook_key', 'webhook_key_hash', 'encrypted_webhook_secret', 'webhook_secret_token_hash'])]
class TelegramBotInstallation extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile' => TelegramBotProfile::class,
            'encrypted_token' => 'encrypted',
            'encrypted_webhook_key' => 'encrypted',
            'encrypted_webhook_secret' => 'encrypted',
            'scope_id' => 'integer',
            'is_enabled' => 'boolean',
            'last_webhook_synced_at' => 'datetime',
        ];
    }

    public static function generateWebhookKey(): string
    {
        return 'tg_'.Str::random(48);
    }

    public static function hashWebhookSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function chatAuthorizations(): HasMany
    {
        return $this->hasMany(TelegramChatAuthorization::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(TelegramUpdate::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class);
    }

    public function authorizationSelections(): HasMany
    {
        return $this->hasMany(TelegramAuthorizationSelection::class);
    }

    public function broadcastTargets(): HasMany
    {
        return $this->hasMany(TelegramBroadcastTarget::class);
    }

    public function isPlatformScoped(): bool
    {
        return $this->scope_type === 'platform';
    }

    public function tokenValue(): ?string
    {
        return $this->encrypted_token ? (string) $this->encrypted_token : null;
    }

    public function webhookKey(): ?string
    {
        return $this->encrypted_webhook_key ? (string) $this->encrypted_webhook_key : null;
    }

    public function webhookSecret(): ?string
    {
        return $this->encrypted_webhook_secret ? (string) $this->encrypted_webhook_secret : null;
    }
}
