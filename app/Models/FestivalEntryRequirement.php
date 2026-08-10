<?php

namespace App\Models;

use App\Enums\FestivalRequirementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_entry_id', 'festival_entry_step_id', 'festival_requirement_definition_id', 'festival_participant_id', 'subject_scope', 'subject_key', 'status', 'definition_snapshot', 'is_required', 'due_at', 'reviewed_by', 'reviewed_at', 'review_notes'])]
class FestivalEntryRequirement extends Model
{
    protected $attributes = ['status' => 'missing'];

    protected function casts(): array
    {
        return ['status' => FestivalRequirementStatus::class, 'definition_snapshot' => 'array', 'is_required' => 'boolean', 'due_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'festival_entry_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(FestivalRequirementDefinition::class, 'festival_requirement_definition_id');
    }

    public function entryStep(): BelongsTo
    {
        return $this->belongsTo(FestivalEntryStep::class, 'festival_entry_step_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(FestivalParticipant::class, 'festival_participant_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FestivalSubmission::class)->latest('id');
    }
}
