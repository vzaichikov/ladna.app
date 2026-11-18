<?php

namespace App\Support\Festivals;

use App\Enums\FestivalScheduleSlotType;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalTimelineItem;

class FestivalPerformanceNumbers
{
    /**
     * @param  iterable<FestivalScheduleSlot|FestivalTimelineItem>  $items
     * @return array<int, int>
     */
    public static function forItems(iterable $items): array
    {
        $categoryCounts = [];
        $numbers = [];

        foreach ($items as $item) {
            $categoryId = self::categoryId($item);
            if ($categoryId === null) {
                continue;
            }

            $categoryCounts[$categoryId] = ($categoryCounts[$categoryId] ?? 0) + 1;
            $numbers[$item->id] = $categoryCounts[$categoryId];
        }

        return $numbers;
    }

    public static function categoryId(FestivalScheduleSlot|FestivalTimelineItem $item): ?int
    {
        if ($item->type !== FestivalScheduleSlotType::Performance || ($item instanceof FestivalTimelineItem && ! $item->is_enabled)) {
            return null;
        }

        return $item->entry?->festival_category_id
            ?? ($item instanceof FestivalScheduleSlot ? $item->festival_category_id : null);
    }
}
