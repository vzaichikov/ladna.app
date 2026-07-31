<?php

namespace App\Models;

use App\Enums\SmsTopUpKind;
use App\Enums\SmsTopUpPaymentStatus;
use Database\Factories\SmsTopUpPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable(['account_id', 'account_sms_wallet_id', 'account_subscription_payment_method_id', 'provider', 'kind', 'order_id', 'gateway_invoice_id', 'gateway_payment_id', 'gateway_status', 'status', 'amount_cents', 'currency', 'idempotency_key', 'gateway_checkout_payload', 'last_callback_payload', 'failure_reason', 'started_at', 'paid_at', 'failed_at', 'cancelled_at', 'expired_at', 'reversed_at'])]
#[Hidden(['gateway_checkout_payload', 'last_callback_payload'])]
class SmsTopUpPayment extends Model
{
    /** @use HasFactory<SmsTopUpPaymentFactory> */
    use HasFactory;

    protected $attributes = [
        'provider' => 'monopay',
        'kind' => 'manual',
        'status' => 'payment_started',
        'currency' => 'UAH',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SmsTopUpKind::class,
            'status' => SmsTopUpPaymentStatus::class,
            'amount_cents' => 'integer',
            'gateway_checkout_payload' => 'encrypted:array',
            'last_callback_payload' => 'encrypted:array',
            'started_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(AccountSmsWallet::class, 'account_sms_wallet_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(
            AccountSubscriptionPaymentMethod::class,
            'account_subscription_payment_method_id',
        );
    }

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(SmsWalletLedgerEntry::class, 'reference');
    }

    public function fiscalReceipts(): MorphMany
    {
        return $this->morphMany(FiscalReceipt::class, 'payment');
    }

    public function fiscalReceipt(): MorphOne
    {
        return $this->morphOne(FiscalReceipt::class, 'payment')->latestOfMany();
    }

    public function isPaid(): bool
    {
        return $this->status === SmsTopUpPaymentStatus::PaymentPaid;
    }
}
