<?php

namespace App\Models;

use Database\Factories\TelegramAuthorizationSelectionCandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['telegram_authorization_selection_id', 'account_id', 'user_id', 'trainer_id', 'label'])]
class TelegramAuthorizationSelectionCandidate extends Model
{
    /** @use HasFactory<TelegramAuthorizationSelectionCandidateFactory> */
    use HasFactory;

    public function selection(): BelongsTo
    {
        return $this->belongsTo(TelegramAuthorizationSelection::class, 'telegram_authorization_selection_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }
}
