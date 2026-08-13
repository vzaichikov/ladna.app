<?php

namespace App\Support\Festivals;

use App\Models\FestivalTimeline;
use App\Models\FestivalTimelineItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class FestivalTimelineEngine
{
    /** @param Collection<int, FestivalTimelineItem> $items */
    public function synchronize(FestivalTimeline $timeline, Collection $items, CarbonInterface $now): bool
    {
        $enabledItems = $items->where('is_enabled', true)->values();
        $activeItem = null;
        $lastFinishedItem = null;
        $nextTransitionAt = null;

        foreach ($enabledItems as $item) {
            if ($item->planned_ends_at->lessThanOrEqualTo($now)) {
                $lastFinishedItem = $item;

                continue;
            }

            if ($item->planned_starts_at->lessThanOrEqualTo($now)) {
                $activeItem = $item;
                $nextTransitionAt = $item->planned_ends_at;
            } else {
                $nextTransitionAt = $item->planned_starts_at;
            }

            break;
        }

        $completedAt = $enabledItems->isEmpty() || ($activeItem === null && $nextTransitionAt === null)
            ? ($timeline->completed_at ?? $now)
            : null;
        $before = $this->state($timeline);

        $timeline->forceFill([
            'active_item_id' => $activeItem?->id,
            'last_finished_item_id' => $lastFinishedItem?->id,
            'completed_at' => $completedAt,
            'next_transition_at' => $nextTransitionAt,
        ])->save();

        return $before !== $this->state($timeline);
    }

    /**
     * Chain all enabled items after the active card from its end.
     *
     * @param  Collection<int, FestivalTimelineItem>  $items
     */
    public function recountAfterActive(FestivalTimeline $timeline, Collection $items): void
    {
        $activeItem = $items->firstWhere('id', $timeline->active_item_id);

        if (! $activeItem instanceof FestivalTimelineItem) {
            return;
        }

        $anchor = $activeItem->planned_ends_at->toImmutable();
        $afterActive = false;

        foreach ($items as $item) {
            if ($item->id === $activeItem->id) {
                $afterActive = true;

                continue;
            }

            if (! $afterActive || ! $item->is_enabled) {
                continue;
            }

            $item->forceFill([
                'planned_starts_at' => $anchor,
                'planned_ends_at' => $anchor->addSeconds($item->duration_seconds),
            ])->save();
            $anchor = $item->planned_ends_at->toImmutable();
        }

        $timeline->forceFill([
            'last_finished_item_id' => $items->where('is_enabled', true)->takeUntil(fn (FestivalTimelineItem $item): bool => $item->id === $activeItem->id)->last()?->id,
            'completed_at' => null,
            'next_transition_at' => $activeItem->planned_ends_at,
        ])->save();
    }

    /**
     * Preserve the current gap boundary while chaining enabled cards after the
     * last finished marker in their current operational order.
     *
     * @param  Collection<int, FestivalTimelineItem>  $items
     */
    public function recountAfterGap(FestivalTimeline $timeline, Collection $items, CarbonInterface $boundary): void
    {
        $anchor = $boundary->toImmutable();
        $afterMarker = $timeline->last_finished_item_id === null;
        $hasFutureItem = false;

        foreach ($items as $item) {
            if (! $afterMarker) {
                $afterMarker = $item->id === $timeline->last_finished_item_id;

                continue;
            }

            if (! $item->is_enabled) {
                continue;
            }

            $item->forceFill([
                'planned_starts_at' => $anchor,
                'planned_ends_at' => $anchor->addSeconds($item->duration_seconds),
            ])->save();
            $anchor = $item->planned_ends_at->toImmutable();
            $hasFutureItem = true;
        }

        $timeline->forceFill([
            'completed_at' => $hasFutureItem ? null : ($timeline->completed_at ?? CarbonImmutable::now()),
            'next_transition_at' => $hasFutureItem ? $boundary : null,
        ])->save();
    }

    /** @return array{active_item_id: int|null, last_finished_item_id: int|null, completed_at: string|null, next_transition_at: string|null} */
    private function state(FestivalTimeline $timeline): array
    {
        return [
            'active_item_id' => $timeline->active_item_id,
            'last_finished_item_id' => $timeline->last_finished_item_id,
            'completed_at' => $timeline->completed_at?->toISOString(),
            'next_transition_at' => $timeline->next_transition_at?->toISOString(),
        ];
    }
}
