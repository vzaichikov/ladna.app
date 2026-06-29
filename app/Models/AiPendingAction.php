<?php

namespace App\Models;

use Database\Factories\AiPendingActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'ai_conversation_id', 'user_id', 'trainer_id', 'action_name', 'arguments', 'preview', 'status', 'result', 'error_message', 'expires_at', 'confirmed_at', 'cancelled_at', 'executed_at'])]
class AiPendingAction extends Model
{
    /** @use HasFactory<AiPendingActionFactory> */
    use HasFactory;

    public const StatusPending = 'pending';

    public const StatusConfirmed = 'confirmed';

    public const StatusCancelled = 'cancelled';

    public const StatusExecuted = 'executed';

    public const StatusFailed = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'preview' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::StatusPending
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
