<?php

namespace App\Models;

use App\Enums\AccountSignupStatus;
use Database\Factories\AccountSignupRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['subscription_plan_id', 'account_id', 'status', 'provider', 'order_id', 'gateway_invoice_id', 'gateway_status', 'studio_name', 'account_slug', 'owner_name', 'owner_email', 'owner_phone', 'owner_password', 'default_language', 'timezone', 'amount_cents', 'currency', 'gateway_checkout_payload', 'last_callback_payload', 'failure_reason', 'paid_at', 'expires_at'])]
class AccountSignupRequest extends Model
{
    /** @use HasFactory<AccountSignupRequestFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending_payment',
        'provider' => 'monopay',
        'default_language' => 'uk',
        'timezone' => 'Europe/Kyiv',
        'currency' => 'UAH',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountSignupStatus::class,
            'gateway_checkout_payload' => 'array',
            'last_callback_payload' => 'array',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AccountSubscriptionPayment::class);
    }
}
