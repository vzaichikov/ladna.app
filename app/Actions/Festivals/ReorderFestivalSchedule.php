<?php

namespace App\Actions\Festivals;

use App\Models\FestivalEdition;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalStage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReorderFestivalSchedule
{
    public function __construct(private readonly FestivalActivityRecorder $activity) {}

    /**
     * @param  list<array{id: int, parent_id: int|null}>  $items
     * @return array{sort_orders: array<int, int>, parent_ids: array<int, int|null>}
     */
    public function execute(FestivalEdition $edition, FestivalStage $stage, array $items, User $actor): array
    {
        return DB::transaction(function () use ($edition, $stage, $items, $actor): array {
            $lockedStage = FestivalStage::query()
                ->whereKey($stage->id)
                ->where('festival_edition_id', $edition->id)
                ->where('account_id', $edition->account_id)
                ->lockForUpdate()
                ->firstOrFail();
            $slots = FestivalScheduleSlot::query()
                ->where('festival_stage_id', $lockedStage->id)
                ->where('festival_edition_id', $edition->id)
                ->where('account_id', $edition->account_id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $this->validateExactSet($slots, $items);
            $parentIds = collect($items)->mapWithKeys(fn (array $item): array => [(int) $item['id'] => isset($item['parent_id']) ? (int) $item['parent_id'] : null]);
            $this->validateHierarchy($slots, $parentIds);
            $before = $slots->mapWithKeys(fn (FestivalScheduleSlot $slot): array => [$slot->id => ['parent_id' => $slot->parent_id, 'sort_order' => $slot->sort_order]]);
            $sortOrders = [];

            collect($items)->groupBy(fn (array $item): int => (int) ($item['parent_id'] ?? 0))->each(
                function (Collection $siblings, int $parentKey) use ($slots, &$sortOrders): void {
                    foreach ($siblings->values() as $index => $item) {
                        $slot = $slots->get((int) $item['id']);
                        $sortOrder = ($index + 1) * 10;
                        $slot->forceFill([
                            'parent_id' => $parentKey === 0 ? null : $parentKey,
                            'sort_order' => $sortOrder,
                        ])->save();
                        $sortOrders[$slot->id] = $sortOrder;
                    }
                },
            );

            foreach ($slots as $slot) {
                $previous = $before->get($slot->id);

                if ($previous['parent_id'] === $slot->parent_id && $previous['sort_order'] === $slot->sort_order) {
                    continue;
                }

                $this->activity->record($slot, 'schedule.reordered', $edition, $actor, [
                    'before' => $previous,
                    'after' => ['parent_id' => $slot->parent_id, 'sort_order' => $slot->sort_order],
                ]);
            }

            return ['sort_orders' => $sortOrders, 'parent_ids' => $parentIds->all()];
        }, 3);
    }

    /**
     * @param  Collection<int, FestivalScheduleSlot>  $slots
     * @param  list<array{id: int, parent_id: int|null}>  $items
     */
    private function validateExactSet(Collection $slots, array $items): void
    {
        $expectedIds = $slots->keys()->map(fn ($id): int => (int) $id)->sort()->values();
        $receivedIds = collect($items)->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values();

        if ($expectedIds->all() !== $receivedIds->all()) {
            throw ValidationException::withMessages(['items' => __('app.festival_program_order_invalid')]);
        }
    }

    /**
     * @param  Collection<int, FestivalScheduleSlot>  $slots
     * @param  Collection<int, int|null>  $parentIds
     */
    private function validateHierarchy(Collection $slots, Collection $parentIds): void
    {
        foreach ($parentIds as $slotId => $parentId) {
            if ($parentId === null) {
                continue;
            }

            $parent = $slots->get($parentId);

            if (! $parent || ! $parent->type->isHeader()) {
                throw ValidationException::withMessages(['items' => __('app.festival_program_parent_must_be_header')]);
            }

            $seen = [(int) $slotId => true];
            $ancestorId = $parentId;

            while ($ancestorId !== null) {
                if (isset($seen[$ancestorId])) {
                    throw ValidationException::withMessages(['items' => __('app.festival_program_hierarchy_cycle')]);
                }

                $seen[$ancestorId] = true;
                $ancestorId = $parentIds->get($ancestorId);
            }
        }
    }
}
