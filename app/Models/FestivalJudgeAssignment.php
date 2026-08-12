<?php

namespace App\Models;

use Database\Factories\FestivalJudgeAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'user_id', 'festival_portal_user_id', 'display_name', 'is_head_judge', 'is_active'])]
class FestivalJudgeAssignment extends Model
{
    /** @use HasFactory<FestivalJudgeAssignmentFactory> */
    use HasFactory;

    protected $attributes = ['is_head_judge' => false, 'is_active' => true];

    protected function casts(): array
    {
        return ['is_head_judge' => 'boolean', 'is_active' => 'boolean'];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function festivalEdition(): BelongsTo
    {
        return $this->edition();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(FestivalPortalUser::class, 'festival_portal_user_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(FestivalCategory::class, 'festival_category_judge_assignment')->withPivot('account_id');
    }

    public function rubricSections(): BelongsToMany
    {
        return $this->belongsToMany(FestivalRubricSection::class, 'festival_judge_assignment_rubric_section')
            ->withPivot('account_id')
            ->withTimestamps();
    }

    public function scoreSheets(): HasMany
    {
        return $this->hasMany(FestivalScoreSheet::class);
    }
}
