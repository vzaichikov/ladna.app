<?php

namespace App\Models;

use App\Enums\FestivalWorkflowReviewEffect;
use App\Enums\FestivalWorkflowReviewMode;
use App\Enums\FestivalWorkflowStepType;
use Database\Factories\FestivalWorkflowStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_workflow_id', 'code', 'type', 'title', 'description', 'sort_order', 'review_mode', 'review_effect', 'opens_at', 'due_at', 'config', 'is_active'])]
class FestivalWorkflowStep extends Model
{
    /** @use HasFactory<FestivalWorkflowStepFactory> */
    use HasFactory;

    protected $attributes = ['review_mode' => 'automatic', 'review_effect' => 'none', 'sort_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'type' => FestivalWorkflowStepType::class,
            'review_mode' => FestivalWorkflowReviewMode::class,
            'review_effect' => FestivalWorkflowReviewEffect::class,
            'sort_order' => 'integer',
            'opens_at' => 'datetime',
            'due_at' => 'datetime',
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(FestivalWorkflow::class, 'festival_workflow_id');
    }

    public function requirementDefinitions(): HasMany
    {
        return $this->hasMany(FestivalRequirementDefinition::class);
    }

    public function chargeDefinitions(): HasMany
    {
        return $this->hasMany(FestivalChargeDefinition::class);
    }

    public function entrySteps(): HasMany
    {
        return $this->hasMany(FestivalEntryStep::class);
    }
}
