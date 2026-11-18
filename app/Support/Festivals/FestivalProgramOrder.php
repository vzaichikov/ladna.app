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
    public function tree(Collection $items, bool $includeOrphans = false): array
    {
        $visibleItems = $items->keyBy('id');
        $byParent = $items
            ->sortBy(fn (FestivalScheduleSlot $item): string => sprintf('%010d:%020d', $item->sort_order, $item->id))
            ->groupBy(fn (FestivalScheduleSlot $item): int => $item->parent_id === null || ($includeOrphans && ! $visibleItems->has($item->parent_id))
                ? 0
                : $item->parent_id);

        return $this->children($byParent, 0);
    }

    /**
     * @param  list<array{item: FestivalScheduleSlot, children: array}>  $tree
     * @return array<int, int>
     */
    public function performanceNumbers(array $tree): array
    {
        return FestivalPerformanceNumbers::forItems(collect($tree)->flatMap($this->flattenNode(...)));
    }

    /** @return list<array{item: FestivalScheduleSlot, children: array}> */
    private function children(Collection $byParent, int $parentId): array
    {
        return $byParent->get($parentId, collect())
            ->map(fn (FestivalScheduleSlot $item): array => [
                'item' => $item,
                'children' => $this->children($byParent, $item->id),
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
