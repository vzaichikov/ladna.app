<?php

namespace App\Models;

use App\Enums\FestivalFieldScope;
use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementType;
use Database\Factories\FestivalRequirementDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'festival_category_id', 'festival_workflow_step_id', 'code', 'type', 'subject_scope', 'input_type', 'name', 'instructions', 'options', 'validation', 'pricing', 'stage', 'due_at', 'allowed_extensions', 'allowed_mime_types', 'max_size_kb', 'min_duration_seconds', 'max_duration_seconds', 'is_required', 'is_active', 'sort_order'])]
class FestivalRequirementDefinition extends Model
{
    /** @use HasFactory<FestivalRequirementDefinitionFactory> */
    use HasFactory;

    protected $attributes = ['stage' => 'final', 'subject_scope' => 'entry', 'input_type' => 'file', 'max_size_kb' => 20480, 'is_required' => true, 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'type' => FestivalRequirementType::class,
            'subject_scope' => FestivalFieldScope::class,
            'input_type' => FestivalRequirementInputType::class,
            'options' => 'array',
            'validation' => 'array',
            'pricing' => 'array',
            'due_at' => 'datetime',
            'allowed_extensions' => 'array',
            'allowed_mime_types' => 'array',
            'max_size_kb' => 'integer',
            'min_duration_seconds' => 'integer',
            'max_duration_seconds' => 'integer',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function festivalEdition(): BelongsTo
    {
        return $this->edition();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FestivalCategory::class, 'festival_category_id');
    }

    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(FestivalWorkflowStep::class, 'festival_workflow_step_id');
    }

    public function entryRequirements(): HasMany
    {
        return $this->hasMany(FestivalEntryRequirement::class, 'festival_requirement_definition_id');
    }
}
