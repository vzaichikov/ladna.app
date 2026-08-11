<?php

namespace Database\Factories;

use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Models\FestivalWorkflow;
use App\Models\FestivalWorkflowStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FestivalEntryStep>
 */
class FestivalEntryStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => fn (array $attributes) => FestivalEntry::findOrFail($attributes['festival_entry_id'])->account_id,
            'festival_entry_id' => FestivalEntry::factory(),
            'festival_workflow_step_id' => function (array $attributes): int {
                $entry = FestivalEntry::query()->findOrFail($attributes['festival_entry_id']);
                $workflow = FestivalWorkflow::factory()->create([
                    'account_id' => $entry->account_id,
                    'festival_edition_id' => $entry->festival_edition_id,
                ]);

                return FestivalWorkflowStep::factory()->create([
                    'account_id' => $entry->account_id,
                    'festival_workflow_id' => $workflow->id,
                ])->id;
            },
            'status' => 'draft',
        ];
    }
}
