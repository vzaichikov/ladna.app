<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_edition_id', 'subject', 'body', 'audience', 'status', 'scheduled_at', 'sent_at', 'created_by'])]
class FestivalAnnouncement extends Model
{
    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['audience' => 'array', 'scheduled_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }
}
