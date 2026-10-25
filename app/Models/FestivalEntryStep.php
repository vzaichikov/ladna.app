<?php

namespace App\Models;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalWorkflowReviewEffect;
use App\Enums\FestivalWorkflowStepType;
use Database\Factories\FestivalEntryStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_entry_id', 'festival_workflow_step_id', 'status', 'submitted_at', 'reviewed_at', 'reviewed_by', 'review_notes', 'correction_due_at'])]
class FestivalEntryStep extends Model
{
    /** @use HasFactory<FestivalEntryStepFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'status' => FestivalEntryStepStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'correction_due_at' => 'datetime',
        ];
    }

    public function isTechnicalForm(): bool
    {
        return $this->workflowStep->type === FestivalWorkflowStepType::Form
            && $this->workflowStep->review_effect === FestivalWorkflowReviewEffect::None;
    }

    public function isTechnicalCorrection(): bool
    {
        return $this->status === FestivalEntryStepStatus::ChangesRequested && $this->isTechnicalForm();
    }

    public function canReturnForCorrection(?FestivalEntry $entry = null): bool
    {
        return $this->status === FestivalEntryStepStatus::Approved
            && $this->isTechnicalForm()
            && in_array(($entry ?? $this->entry)->status, [
                FestivalEntryStatus::Submitted,
                FestivalEntryStatus::UnderReview,
                FestivalEntryStatus::Accepted,
                FestivalEntryStatus::ChangesPending,
            ], true);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'festival_entry_id');
    }

    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(FestivalWorkflowStep::class, 'festival_workflow_step_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(FestivalEntryRequirement::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(FestivalCharge::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(FestivalChargeAdjustment::class);
    }
}
