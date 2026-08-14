<?php

namespace App\Actions\Festivals;

use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalDirection;
use App\Models\FestivalEdition;
use App\Models\FestivalWorkflow;
use App\Support\StudioRulesHtmlSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveFestivalCategory
{
    public function __construct(private readonly StudioRulesHtmlSanitizer $htmlSanitizer) {}

    /** @param array<string, mixed> $input */
    public function execute(Account $account, FestivalEdition $edition, array $input, ?FestivalCategory $category = null): FestivalCategory
    {
        abort_unless($edition->account_id === $account->id, 404);
        abort_unless(! $category?->exists || ($category->account_id === $account->id && $category->festival_edition_id === $edition->id), 404);

        return DB::transaction(function () use ($account, $edition, $input, $category): FestivalCategory {
            $category = $category?->exists
                ? FestivalCategory::query()
                    ->whereKey($category->id)
                    ->whereBelongsTo($account)
                    ->whereBelongsTo($edition, 'edition')
                    ->lockForUpdate()
                    ->firstOrFail()
                : new FestivalCategory;

            $direction = FestivalDirection::query()
                ->whereBelongsTo($account)
                ->whereBelongsTo($edition, 'edition')
                ->whereKey($input['festival_direction_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $direction->is_active) {
                throw ValidationException::withMessages(['festival_direction_id' => __('app.festival_direction_inactive')]);
            }

            $workflow = filled($input['festival_workflow_id'] ?? null)
                ? FestivalWorkflow::query()
                    ->whereBelongsTo($account)
                    ->whereBelongsTo($edition, 'edition')
                    ->whereKey($input['festival_workflow_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail()
                : null;

            $maximumAcceptedEntries = filled($input['maximum_accepted_entries'] ?? null)
                ? (int) $input['maximum_accepted_entries']
                : null;
            if ($category->exists && $maximumAcceptedEntries !== null) {
                $capacityOccupyingEntriesCount = $category->capacityOccupyingEntries()->count();
                if ($maximumAcceptedEntries < $capacityOccupyingEntriesCount) {
                    throw ValidationException::withMessages([
                        'maximum_accepted_entries' => __('app.festival_category_capacity_below_accepted', ['count' => $capacityOccupyingEntriesCount]),
                    ]);
                }
            }

            $category->fill([
                'account_id' => $account->id,
                'festival_edition_id' => $edition->id,
                'festival_direction_id' => $direction->id,
                'festival_workflow_id' => $workflow?->id,
                'code' => $category->exists ? $category->code : $input['code'],
                'name' => $input['name'],
                'min_members' => $input['min_members'],
                'max_members' => $input['max_members'],
                'min_age' => $input['min_age'] ?? null,
                'max_age' => $input['max_age'] ?? null,
                'min_duration_seconds' => $input['min_duration_seconds'] ?? null,
                'max_duration_seconds' => $input['max_duration_seconds'] ?? null,
                'competition_format' => $input['competition_format'],
                'minimum_entries_to_run' => $input['minimum_entries_to_run'],
                'maximum_accepted_entries' => $maximumAcceptedEntries,
                'registration_closes_at' => $this->utc($input['registration_closes_at'] ?? null, $edition->timezone),
                'requirements_html' => $this->htmlSanitizer->sanitize($input['requirements_html'] ?? null),
                'is_active' => $input['is_active'] ?? ($category->exists ? $category->is_active : true),
                'sort_order' => $category->exists ? $category->sort_order : ((int) $edition->categories()->max('sort_order')) + 10,
            ])->save();

            return $category->refresh()->load(['direction', 'registrationWorkflow']);
        }, 3);
    }

    private function utc(mixed $value, string $timezone): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse((string) $value, $timezone)->utc() : null;
    }
}
