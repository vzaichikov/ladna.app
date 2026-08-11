<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_edition_id', 'festival_entry_id', 'total_score', 'rank', 'medal', 'published_at'])]
class FestivalResult extends Model
{
    protected function casts(): array
    {
        return ['total_score' => 'decimal:4', 'rank' => 'integer', 'published_at' => 'datetime'];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'festival_entry_id');
    }
}
