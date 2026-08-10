<?php

namespace App\Models;

use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalWorkflowReviewEffect;
use App\Enums\FestivalWorkflowReviewMode;
use App\Enums\FestivalWorkflowStepType;
use Database\Factories\FestivalEntryStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_entry_id', 'festival_workflow_step_id', 'code', 'type', 'title', 'description', 'sort_order', 'review_mode', 'review_effect', 'status', 'opens_at', 'due_at', 'submitted_at', 'reviewed_at', 'reviewed_by', 'review_notes', 'revision_due_at', 'step_snapshot'])]
class FestivalEntryStep extends Model
{
    /** @use HasFactory<FestivalEntryStepFactory> */
    use HasFactory;

    protected $attributes = ['review_mode' => 'automatic', 'review_effect' => 'none', 'status' => 'draft', 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'type' => FestivalWorkflowStepType::class,
            'review_mode' => FestivalWorkflowReviewMode::class,
            'review_effect' => FestivalWorkflowReviewEffect::class,
            'status' => FestivalEntryStepStatus::class,
            'sort_order' => 'integer',
            'opens_at' => 'datetime',
            'due_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'revision_due_at' => 'datetime',
            'step_snapshot' => 'array',
        ];
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
