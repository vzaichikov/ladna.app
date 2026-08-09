<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['account_id', 'festival_edition_id', 'actor_user_id', 'actor_portal_user_id', 'action', 'subject_type', 'subject_id', 'payload', 'occurred_at'])]
class FestivalActivityLog extends Model
{
    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }
}
