<?php

namespace App\Models;

use Database\Factories\AccountSmsWalletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'balance_cents', 'reserved_cents', 'outstanding_cents', 'currency', 'auto_top_up_enabled', 'auto_top_up_threshold_cents', 'auto_top_up_target_cents', 'auto_top_up_monthly_cap_cents', 'auto_top_up_monthly_spent_cents', 'auto_top_up_monthly_period', 'auto_top_up_suspended_at', 'last_low_balance_warning_at', 'last_auto_top_up_failure_warning_at', 'last_outstanding_warning_at'])]
class AccountSmsWallet extends Model
{
    /** @use HasFactory<AccountSmsWalletFactory> */
    use HasFactory;

    protected $attributes = [
        'balance_cents' => 0,
        'reserved_cents' => 0,
        'outstanding_cents' => 0,
        'currency' => 'UAH',
        'auto_top_up_enabled' => false,
        'auto_top_up_monthly_spent_cents' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_cents' => 'integer',
            'reserved_cents' => 'integer',
            'outstanding_cents' => 'integer',
            'auto_top_up_enabled' => 'boolean',
            'auto_top_up_threshold_cents' => 'integer',
            'auto_top_up_target_cents' => 'integer',
            'auto_top_up_monthly_cap_cents' => 'integer',
            'auto_top_up_monthly_spent_cents' => 'integer',
            'auto_top_up_monthly_period' => 'date',
            'auto_top_up_suspended_at' => 'datetime',
            'last_low_balance_warning_at' => 'datetime',
            'last_auto_top_up_failure_warning_at' => 'datetime',
            'last_outstanding_warning_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SmsWalletLedgerEntry::class);
    }

    public function topUpPayments(): HasMany
    {
        return $this->hasMany(SmsTopUpPayment::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(SmsDelivery::class);
    }

    public function spendableBalanceCents(): int
    {
        return max(0, $this->balance_cents - $this->reserved_cents);
    }
}
