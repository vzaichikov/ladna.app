<?php

namespace App\Models;

use App\Enums\SmsWalletLedgerEntryType;
use Database\Factories\SmsWalletLedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

#[Fillable(['account_id', 'account_sms_wallet_id', 'type', 'amount_cents', 'balance_after_cents', 'outstanding_after_cents', 'reference_type', 'reference_id', 'actor_user_id', 'reason', 'idempotency_key'])]
class SmsWalletLedgerEntry extends Model
{
    /** @use HasFactory<SmsWalletLedgerEntryFactory> */
    use HasFactory;

    protected $attributes = [
        'outstanding_after_cents' => 0,
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('SMS wallet ledger entries are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('SMS wallet ledger entries cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SmsWalletLedgerEntryType::class,
            'amount_cents' => 'integer',
            'balance_after_cents' => 'integer',
            'outstanding_after_cents' => 'integer',
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

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
