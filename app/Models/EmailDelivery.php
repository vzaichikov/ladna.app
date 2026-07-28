<?php

namespace App\Models;

use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailRecipientKind;
use App\Enums\EmailScenario;
use Database\Factories\EmailDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'customer_id', 'user_id', 'event_order_id', 'scenario', 'status', 'recipient_kind', 'recipient_name', 'recipient_email', 'locale', 'account_timezone', 'subject', 'subject_key', 'subject_parameters', 'content_view', 'payload', 'html_body', 'text_body', 'configured_engine', 'actual_engine', 'fallback_used', 'provider_message_id', 'attempts', 'queued_at', 'processing_at', 'sent_at', 'failed_at', 'skipped_at', 'status_reason', 'last_error'])]
class EmailDelivery extends Model
{
    /** @use HasFactory<EmailDeliveryFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
        'fallback_used' => false,
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scenario' => EmailScenario::class,
            'status' => EmailDeliveryStatus::class,
            'recipient_kind' => EmailRecipientKind::class,
            'subject_parameters' => 'array',
            'payload' => 'array',
            'fallback_used' => 'boolean',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'processing_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'skipped_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function eventOrder(): BelongsTo
    {
        return $this->belongsTo(EventOrder::class);
    }
}
