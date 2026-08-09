<?php

namespace App\Models;

use App\Enums\FestivalScoreSheetStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_entry_id', 'festival_judge_assignment_id', 'festival_rubric_id', 'status', 'comments', 'total_score', 'lock_version', 'submitted_at', 'locked_at', 'unlocked_by', 'unlock_reason'])]
class FestivalScoreSheet extends Model
{
    protected $attributes = ['status' => 'draft', 'total_score' => 0, 'lock_version' => 1];

    protected function casts(): array
    {
        return ['status' => FestivalScoreSheetStatus::class, 'total_score' => 'decimal:4', 'lock_version' => 'integer', 'submitted_at' => 'datetime', 'locked_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'festival_entry_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(FestivalJudgeAssignment::class, 'festival_judge_assignment_id');
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(FestivalRubric::class, 'festival_rubric_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(FestivalCriterionScore::class);
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(FestivalPenalty::class);
    }
}
