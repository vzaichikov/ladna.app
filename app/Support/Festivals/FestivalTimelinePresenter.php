<?php

namespace App\Support\Festivals;

use App\Models\FestivalEdition;
use App\Models\FestivalTimeline;
use App\Models\FestivalTimelineItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FestivalTimelinePresenter
{
    /** @return array<string, mixed> */
    public function scene(FestivalTimeline $timeline, bool $public = false): array
    {
        $timeline->loadMissing(['stage', 'edition', 'items', 'activeItem', 'lastFinishedItem']);
        $items = $timeline->items
            ->when($public, fn (Collection $collection): Collection => $collection->where('is_enabled', true))
            ->values();
        $nextItem = $this->nextItem($timeline);

        return [
            'id' => $timeline->id,
            'stage_id' => $timeline->festival_stage_id,
            'scene_name' => $timeline->stage->name,
            'started' => $timeline->started_at !== null,
            'paused' => $timeline->paused_at !== null,
            'completed' => $timeline->completed_at !== null,
            'active_item_id' => $timeline->active_item_id,
            'last_finished_item_id' => $timeline->last_finished_item_id,
            'state' => $this->state($timeline),
            'next_label' => $nextItem?->label,
            'next_transition_iso' => $timeline->next_transition_at?->toISOString(),
            'next_transition_local' => $timeline->next_transition_at?->timezone($timeline->edition->timezone)->format('d.m.Y H:i:s'),
            'timezone' => $timeline->edition->timezone,
            'items' => $items->map(fn (FestivalTimelineItem $item): array => [
                'model' => $item,
                'id' => $item->id,
                'label' => $item->label,
                'type' => $item->type->value,
                'type_label' => __('app.festival_schedule_slot_type_'.$item->type->value),
                'entry_reference' => $item->entry_reference,
                'notes' => $public ? null : $item->notes,
                'duration_seconds' => $item->duration_seconds,
                'duration_label' => $this->duration($item->duration_seconds),
                'starts_at_iso' => $item->planned_starts_at->toISOString(),
                'ends_at_iso' => $item->planned_ends_at->toISOString(),
                'starts_at_local' => $item->planned_starts_at->timezone($timeline->edition->timezone)->format('d.m.Y H:i:s'),
                'ends_at_local' => $item->planned_ends_at->timezone($timeline->edition->timezone)->format('d.m.Y H:i:s'),
                'status' => $this->itemStatus($timeline, $item),
                'enabled' => $item->is_enabled,
            ])->all(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function scenes(Collection $timelines, bool $public = false): Collection
    {
        return $timelines
            ->sortBy(fn (FestivalTimeline $timeline): string => sprintf('%010d:%020d', $timeline->stage->sort_order, $timeline->id))
            ->map(fn (FestivalTimeline $timeline): array => $this->scene($timeline, $public))
            ->values();
    }

    public function isWithinLocalDates(FestivalEdition $edition): bool
    {
        $today = CarbonImmutable::now($edition->timezone)->toDateString();

        return $today >= $edition->starts_at->timezone($edition->timezone)->toDateString()
            && $today <= $edition->ends_at->timezone($edition->timezone)->toDateString();
    }

    private function state(FestivalTimeline $timeline): string
    {
        if (! $timeline->started_at) {
            return 'prepared';
        }

        if ($timeline->paused_at) {
            return 'paused';
        }

        if ($timeline->completed_at) {
            return 'completed';
        }

        return $timeline->active_item_id ? 'active' : 'waiting';
    }

    private function itemStatus(FestivalTimeline $timeline, FestivalTimelineItem $item): string
    {
        if (! $item->is_enabled) {
            return 'disabled';
        }

        if (! $timeline->started_at) {
            return 'future';
        }

        if ($timeline->completed_at) {
            return 'passed';
        }

        $marker = $timeline->activeItem ?? $timeline->lastFinishedItem;

        if (! $marker instanceof FestivalTimelineItem) {
            return 'future';
        }

        if ($timeline->active_item_id === $item->id) {
            return 'active';
        }

        $isBeforeMarker = $item->sort_order < $marker->sort_order
            || ($item->sort_order === $marker->sort_order && $item->id < $marker->id);

        if ($timeline->active_item_id) {
            return $isBeforeMarker ? 'passed' : 'future';
        }

        return $isBeforeMarker || $item->id === $marker->id ? 'passed' : 'future';
    }

    private function nextItem(FestivalTimeline $timeline): ?FestivalTimelineItem
    {
        $enabledItems = $timeline->items->where('is_enabled', true)->values();

        if ($timeline->active_item_id) {
            return $enabledItems
                ->skipUntil(fn (FestivalTimelineItem $item): bool => $item->id === $timeline->active_item_id)
                ->skip(1)
                ->first();
        }

        if ($timeline->last_finished_item_id) {
            return $enabledItems
                ->skipUntil(fn (FestivalTimelineItem $item): bool => $item->id === $timeline->last_finished_item_id)
                ->skip(1)
                ->first();
        }

        return $enabledItems->first();
    }

    private function duration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes === 0) {
            return trans_choice('app.festival_timeline_duration_seconds', $remainingSeconds, ['count' => $remainingSeconds]);
        }

        if ($remainingSeconds === 0) {
            return trans_choice('app.festival_timeline_duration_minutes', $minutes, ['count' => $minutes]);
        }

        return __('app.festival_timeline_duration_mixed', ['minutes' => $minutes, 'seconds' => $remainingSeconds]);
    }
}
