<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalScheduleSlotType;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalStage;
use App\Models\FestivalTimeline;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateFestivalProgram
{
    public function __construct(private readonly FestivalActivityRecorder $activity) {}

    /**
     * @return array{created: int, created_headers: int, deleted: int, skipped: int}
     */
    public function execute(FestivalEdition $edition, FestivalStage $stage, string $mode, User $actor): array
    {
        return DB::transaction(function () use ($edition, $stage, $mode, $actor): array {
            $lockedEdition = FestivalEdition::query()
                ->whereKey($edition->id)
                ->where('account_id', $edition->account_id)
                ->lockForUpdate()
                ->firstOrFail();
            $timeline = $mode === 'full'
                ? FestivalTimeline::query()
                    ->where('festival_edition_id', $lockedEdition->id)
                    ->where('festival_stage_id', $stage->id)
                    ->where('account_id', $lockedEdition->account_id)
                    ->lockForUpdate()
                    ->first()
                : null;
            $lockedStage = FestivalStage::query()
                ->whereKey($stage->id)
                ->where('festival_edition_id', $lockedEdition->id)
                ->where('account_id', $lockedEdition->account_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($timeline?->started_at !== null) {
                throw ValidationException::withMessages([
                    'mode' => __('app.festival_program_generation_timeline_started'),
                ]);
            }

            $categories = FestivalCategory::query()
                ->where('festival_edition_id', $lockedEdition->id)
                ->where('account_id', $lockedEdition->account_id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'sort_order'])
                ->keyBy('id');
            $acceptedEntries = FestivalEntry::query()
                ->where('festival_edition_id', $lockedEdition->id)
                ->where('account_id', $lockedEdition->account_id)
                ->where('status', FestivalEntryStatus::Accepted->value)
                ->whereIn('festival_category_id', $categories->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'festival_category_id', 'entry_name'])
                ->sort(function (FestivalEntry $first, FestivalEntry $second) use ($categories): int {
                    $firstCategory = $categories->get($first->festival_category_id);
                    $secondCategory = $categories->get($second->festival_category_id);

                    return [$firstCategory?->sort_order ?? 0, $first->festival_category_id, $first->entry_name, $first->id]
                        <=> [$secondCategory?->sort_order ?? 0, $second->festival_category_id, $second->entry_name, $second->id];
                })
                ->values();
            $currentSlots = FestivalScheduleSlot::query()
                ->where('festival_stage_id', $lockedStage->id)
                ->where('festival_edition_id', $lockedEdition->id)
                ->where('account_id', $lockedEdition->account_id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $existingEntryIds = FestivalScheduleSlot::query()
                ->where('festival_edition_id', $lockedEdition->id)
                ->where('account_id', $lockedEdition->account_id)
                ->where('type', FestivalScheduleSlotType::Performance->value)
                ->whereNotNull('festival_entry_id')
                ->when($mode === 'full', fn ($query) => $query->where('festival_stage_id', '!=', $lockedStage->id))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['festival_entry_id'])
                ->pluck('festival_entry_id')
                ->map(fn (mixed $entryId): int => (int) $entryId)
                ->unique()
                ->all();
            $eligibleEntries = $acceptedEntries
                ->reject(fn (FestivalEntry $entry): bool => in_array($entry->id, $existingEntryIds, true))
                ->values();
            $skipped = $acceptedEntries->count() - $eligibleEntries->count();
            $deleted = 0;
            $timelineRemoved = false;

            if ($mode === 'full') {
                $deleted = $currentSlots->count();
                $timelineRemoved = $timeline !== null;
                $timeline?->delete();
                FestivalScheduleSlot::query()
                    ->where('festival_stage_id', $lockedStage->id)
                    ->where('festival_edition_id', $lockedEdition->id)
                    ->where('account_id', $lockedEdition->account_id)
                    ->delete();
                $currentSlots = collect();
            }

            $createdHeaders = 0;
            $createdPerformances = 0;
            $headersByCategory = $this->categoryHeaders($currentSlots);
            $nextRootSortOrder = ((int) $currentSlots->whereNull('parent_id')->max('sort_order')) + 10;

            foreach ($eligibleEntries->groupBy('festival_category_id') as $categoryId => $categoryEntries) {
                $categoryId = (int) $categoryId;
                $header = $headersByCategory->get($categoryId);

                if (! $header) {
                    $header = FestivalScheduleSlot::query()->create([
                        'account_id' => $lockedEdition->account_id,
                        'festival_edition_id' => $lockedEdition->id,
                        'festival_stage_id' => $lockedStage->id,
                        'festival_category_id' => $categoryId,
                        'type' => FestivalScheduleSlotType::CategoryHeader,
                        'sort_order' => $nextRootSortOrder,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);
                    $headersByCategory->put($categoryId, $header);
                    $nextRootSortOrder += 10;
                    $createdHeaders++;
                }

                $nextChildSortOrder = ((int) FestivalScheduleSlot::query()
                    ->where('parent_id', $header->id)
                    ->max('sort_order')) + 10;

                foreach ($categoryEntries as $entry) {
                    FestivalScheduleSlot::query()->create([
                        'account_id' => $lockedEdition->account_id,
                        'festival_edition_id' => $lockedEdition->id,
                        'festival_stage_id' => $lockedStage->id,
                        'festival_entry_id' => $entry->id,
                        'parent_id' => $header->id,
                        'type' => FestivalScheduleSlotType::Performance,
                        'starts_at' => null,
                        'ends_at' => null,
                        'sort_order' => $nextChildSortOrder,
                        'published_at' => null,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);
                    $nextChildSortOrder += 10;
                    $createdPerformances++;
                }
            }

            $result = [
                'created' => $createdPerformances,
                'created_headers' => $createdHeaders,
                'deleted' => $deleted,
                'skipped' => $skipped,
            ];

            $this->activity->record($lockedStage, 'schedule.generated', $lockedEdition, $actor, [
                'mode' => $mode,
                ...$result,
                'timeline_removed' => $timelineRemoved,
            ]);

            return $result;
        }, 3);
    }

    /**
     * @param  Collection<int, FestivalScheduleSlot>  $slots
     * @return Collection<int, FestivalScheduleSlot>
     */
    private function categoryHeaders(Collection $slots): Collection
    {
        return $slots
            ->where('type', FestivalScheduleSlotType::CategoryHeader)
            ->whereNotNull('festival_category_id')
            ->groupBy('festival_category_id')
            ->map(fn (Collection $headers): FestivalScheduleSlot => $headers
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->first());
    }
}
