<?php

namespace App\Models;

use Database\Factories\FestivalChargeDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_edition_id', 'festival_category_id', 'festival_workflow_step_id', 'kind', 'name', 'amount_cents', 'currency', 'due_at', 'is_active', 'sort_order'])]
class FestivalChargeDefinition extends Model
{
    /** @use HasFactory<FestivalChargeDefinitionFactory> */
    use HasFactory;

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'due_at' => 'datetime', 'is_active' => 'boolean', 'sort_order' => 'integer'];
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
}
