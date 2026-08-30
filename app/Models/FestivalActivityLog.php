<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['account_id', 'festival_edition_id', 'festival_entry_id', 'actor_user_id', 'actor_portal_user_id', 'actor_account_api_token_id', 'action', 'subject_type', 'subject_id', 'payload', 'occurred_at'])]
class FestivalActivityLog extends Model
{
    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'festival_entry_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorPortalUser(): BelongsTo
    {
        return $this->belongsTo(FestivalPortalUser::class, 'actor_portal_user_id');
    }

    public function actorAccountApiToken(): BelongsTo
    {
        return $this->belongsTo(AccountApiToken::class, 'actor_account_api_token_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
