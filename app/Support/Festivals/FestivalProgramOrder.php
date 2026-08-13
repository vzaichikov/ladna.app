<?php

namespace App\Support\Festivals;

use App\Models\FestivalScheduleSlot;
use Illuminate\Support\Collection;

class FestivalProgramOrder
{
    /** @param Collection<int, FestivalScheduleSlot> $items */
    public function ordered(Collection $items): Collection
    {
        return collect($this->tree($items))
            ->flatMap(fn (array $node): array => $this->flattenNode($node))
            ->values();
    }

    /**
     * @param  Collection<int, FestivalScheduleSlot>  $items
     * @return list<array{item: FestivalScheduleSlot, children: array}>
     */
    public function tree(Collection $items, ?int $parentId = null): array
    {
        return $items
            ->filter(fn (FestivalScheduleSlot $item): bool => $item->parent_id === $parentId)
            ->sortBy(fn (FestivalScheduleSlot $item): string => sprintf('%010d:%020d', $item->sort_order, $item->id))
            ->map(fn (FestivalScheduleSlot $item): array => [
                'item' => $item,
                'children' => $this->tree($items, $item->id),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{item: FestivalScheduleSlot, children: array}  $node
     * @return list<FestivalScheduleSlot>
     */
    private function flattenNode(array $node): array
    {
        $items = [$node['item']];

        foreach ($node['children'] as $child) {
            array_push($items, ...$this->flattenNode($child));
        }

        return $items;
    }
}
