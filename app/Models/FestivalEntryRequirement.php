<?php

namespace App\Models;

use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['account_id', 'festival_entry_id', 'festival_entry_step_id', 'festival_requirement_definition_id', 'festival_participant_id', 'subject_key', 'status', 'reviewed_by', 'reviewed_at', 'review_notes'])]
class FestivalEntryRequirement extends Model
{
    protected $attributes = ['status' => 'missing'];

    protected function casts(): array
    {
        return ['status' => FestivalRequirementStatus::class, 'reviewed_at' => 'datetime'];
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

    public function latestSubmission(): HasOne
    {
        return $this->hasOne(FestivalSubmission::class)->latestOfMany();
    }

    public function selectedHelpers(): BelongsToMany
    {
        return $this->belongsToMany(
            FestivalParticipant::class,
            'festival_entry_requirement_helper',
            'festival_entry_requirement_id',
            'festival_participant_id',
        )->withPivot('sort_order')->orderByPivot('sort_order');
    }

    public function hasSubmittedResponse(): bool
    {
        $definition = $this->relationLoaded('definition')
            ? $this->definition
            : $this->definition()->first();
        $submission = $this->relationLoaded('submissions')
            ? $this->submissions->first()
            : $this->submissions()->first();

        if (! $definition || ! $submission) {
            return false;
        }

        if ($definition->input_type === FestivalRequirementInputType::File) {
            return filled($submission->disk) && filled($submission->path);
        }

        if ($definition->input_type === FestivalRequirementInputType::Agreement) {
            return in_array(data_get($submission->value_json, 'value'), [true, 1, '1'], true);
        }

        if ($definition->input_type === FestivalRequirementInputType::HelperSelection) {
            $enabled = data_get($submission->value_json, 'value.enabled');

            if (! is_bool($enabled)) {
                return false;
            }

            if (! $enabled) {
                return true;
            }

            return $this->relationLoaded('selectedHelpers')
                ? $this->selectedHelpers->isNotEmpty()
                : $this->selectedHelpers()->exists();
        }

        return is_array($submission->value_json)
            && array_key_exists('value', $submission->value_json);
    }
}
