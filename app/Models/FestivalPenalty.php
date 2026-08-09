<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_entry_id', 'festival_score_sheet_id', 'kind', 'points', 'reason', 'created_by'])]
class FestivalPenalty extends Model
{
    protected $attributes = ['kind' => 'deduction'];

    protected function casts(): array
    {
        return ['points' => 'decimal:2'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'festival_entry_id');
    }

    public function scoreSheet(): BelongsTo
    {
        return $this->belongsTo(FestivalScoreSheet::class, 'festival_score_sheet_id');
    }
}
