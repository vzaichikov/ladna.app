<?php

namespace App\Models;

use App\Enums\FiscalReceiptStatus;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use Database\Factories\FiscalReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['account_id', 'scope_type', 'scope_id', 'payment_type', 'payment_id', 'provider', 'status', 'external_uuid', 'provider_receipt_id', 'provider_status', 'fiscal_number', 'attempts', 'request_payload', 'response_payload', 'last_error', 'sent_at', 'fiscalized_at', 'failed_at'])]
#[Hidden(['request_payload', 'response_payload'])]
class FiscalReceipt extends Model
{
    /** @use HasFactory<FiscalReceiptFactory> */
    use HasFactory;

    protected $attributes = [
        'scope_type' => 'account',
        'scope_id' => 0,
        'provider' => 'checkbox',
        'status' => 'pending',
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => IntegrationScope::class,
            'provider' => IntegrationProvider::class,
            'status' => FiscalReceiptStatus::class,
            'request_payload' => 'encrypted:array',
            'response_payload' => 'encrypted:array',
            'sent_at' => 'datetime',
            'fiscalized_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function payment(): MorphTo
    {
        return $this->morphTo();
    }

    public function isFiscalized(): bool
    {
        return $this->status === FiscalReceiptStatus::Fiscalized;
    }
}
