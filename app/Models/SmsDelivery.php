<?php

namespace App\Models;

use App\Enums\SmsDeliveryPurpose;
use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
use Database\Factories\SmsDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\JoinClause;

#[Fillable(['account_id', 'account_sms_wallet_id', 'subscription_plan_id', 'subscription_plan_name_snapshot', 'source_type', 'source_id', 'purpose', 'source_mode', 'provider', 'status', 'recipient_phone', 'message_preview', 'idempotency_key', 'estimated_segments', 'provider_segments', 'billed_segments', 'sms_segment_price_cents', 'reserved_amount_cents', 'amount_cents', 'wholesale_cost_cents', 'currency', 'provider_message_id', 'reserved_at', 'accepted_at', 'delivered_at', 'failed_at', 'cancelled_at', 'last_status_checked_at', 'next_status_check_at', 'status_polling_expires_at', 'error_code', 'last_error'])]
class SmsDelivery extends Model
{
    /** @use HasFactory<SmsDeliveryFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
        'estimated_segments' => 1,
        'reserved_amount_cents' => 0,
        'currency' => 'UAH',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => SmsDeliveryPurpose::class,
            'source_mode' => SmsSendingMode::class,
            'status' => SmsDeliveryStatus::class,
            'estimated_segments' => 'integer',
            'provider_segments' => 'integer',
            'billed_segments' => 'integer',
            'sms_segment_price_cents' => 'integer',
            'reserved_amount_cents' => 'integer',
            'amount_cents' => 'integer',
            'wholesale_cost_cents' => 'integer',
            'reserved_at' => 'datetime',
            'accepted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_status_checked_at' => 'datetime',
            'next_status_check_at' => 'datetime',
            'status_polling_expires_at' => 'datetime',
        ];
    }

    public function scopeDueForStatusCheck(Builder $query): Builder
    {
        return $query
            ->where('status', SmsDeliveryStatus::Accepted->value)
            ->whereNotNull('next_status_check_at')
            ->where('next_status_check_at', '<=', now())
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('status_polling_expires_at')
                    ->orWhere('status_polling_expires_at', '>', now());
            });
    }

    public function scopeWithLogDetails(Builder $query): Builder
    {
        return $query
            ->leftJoin('customer_notifications as sms_log_notification', function (JoinClause $join): void {
                $join
                    ->on('sms_log_notification.id', '=', 'sms_deliveries.source_id')
                    ->on('sms_log_notification.account_id', '=', 'sms_deliveries.account_id')
                    ->where('sms_deliveries.source_type', CustomerNotification::class);
            })
            ->leftJoin('customers as sms_log_customer', function (JoinClause $join): void {
                $join
                    ->on('sms_log_customer.account_id', '=', 'sms_deliveries.account_id')
                    ->on('sms_log_customer.phone', '=', 'sms_deliveries.recipient_phone');
            })
            ->select([
                'sms_deliveries.*',
                'sms_log_notification.customer_id as notification_customer_id',
                'sms_log_notification.recipient_name as notification_recipient_name',
                'sms_log_notification.text as notification_text',
                'sms_log_customer.id as resolved_customer_id',
                'sms_log_customer.name as resolved_customer_name',
            ]);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(AccountSmsWallet::class, 'account_sms_wallet_id');
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(SmsWalletLedgerEntry::class, 'reference');
    }
}
