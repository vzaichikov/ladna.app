<?php

namespace App\Models;

use App\Enums\FestivalRubricSectionContribution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_rubric_id', 'name', 'weight', 'contribution', 'sort_order'])]
class FestivalRubricSection extends Model
{
    protected $attributes = ['weight' => 1, 'contribution' => 'award', 'sort_order' => 0];

    protected function casts(): array
    {
        return ['weight' => 'decimal:4', 'contribution' => FestivalRubricSectionContribution::class, 'sort_order' => 'integer'];
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(FestivalRubric::class, 'festival_rubric_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(FestivalRubricCriterion::class)->orderBy('sort_order')->orderBy('id');
    }

    public function judgeAssignments(): BelongsToMany
    {
        return $this->belongsToMany(FestivalJudgeAssignment::class, 'festival_judge_assignment_rubric_section')
            ->withPivot('account_id')
            ->withTimestamps();
    }
}
