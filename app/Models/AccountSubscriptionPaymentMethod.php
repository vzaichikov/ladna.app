<?php

namespace App\Models;

use App\Enums\SubscriptionPaymentMethodStatus;
use Database\Factories\AccountSubscriptionPaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'account_subscription_id', 'provider', 'provider_wallet_id', 'provider_card_token', 'masked_pan', 'card_brand', 'status', 'verification_reference', 'verification_invoice_id', 'last_callback_payload', 'verified_at', 'revoked_at'])]
class AccountSubscriptionPaymentMethod extends Model
{
    /** @use HasFactory<AccountSubscriptionPaymentMethodFactory> */
    use HasFactory;

    protected $attributes = [
        'provider' => 'monopay',
        'status' => 'pending_verification',
    ];

    protected $hidden = [
        'provider_wallet_id',
        'provider_card_token',
        'last_callback_payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_wallet_id' => 'encrypted',
            'provider_card_token' => 'encrypted',
            'last_callback_payload' => 'encrypted:array',
            'status' => SubscriptionPaymentMethodStatus::class,
            'verified_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(AccountSubscription::class, 'account_subscription_id');
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionPaymentMethodStatus::Active
            && filled($this->provider_card_token)
            && $this->revoked_at === null;
    }
}
