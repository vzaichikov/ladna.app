<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_score_sheet_id', 'festival_rubric_criterion_id', 'score', 'comment'])]
class FestivalCriterionScore extends Model
{
    protected function casts(): array
    {
        return ['score' => 'decimal:2'];
    }

    public function scoreSheet(): BelongsTo
    {
        return $this->belongsTo(FestivalScoreSheet::class, 'festival_score_sheet_id');
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(FestivalRubricCriterion::class, 'festival_rubric_criterion_id');
    }
}
