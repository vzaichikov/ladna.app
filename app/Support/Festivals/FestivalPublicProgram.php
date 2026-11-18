<?php

namespace App\Support\Festivals;

use App\Enums\FestivalScheduleSlotType;
use App\Models\FestivalEdition;
use App\Models\FestivalScheduleSlot;
use Illuminate\Database\Eloquent\Collection;

class FestivalPublicProgram
{
    public function __construct(private readonly FestivalProgramOrder $order) {}

    /** @return list<array{stage: string, items: list<array<string, mixed>>}> */
    public function stages(FestivalEdition $edition): array
    {
        $edition->loadMissing(['publicProgramSlots.stage', 'publicProgramSlots.entry', 'publicProgramSlots.category']);

        return $edition->publicProgramSlots
            ->groupBy('festival_stage_id')
            ->sortBy(fn (Collection $slots): string => sprintf('%010d:%020d', $slots->first()->stage->sort_order, $slots->first()->stage->id))
            ->map(function (Collection $slots): array {
                $tree = $this->order->tree($slots, includeOrphans: true);
                $numbers = $this->order->performanceNumbers($tree);

                return [
                    'stage' => $slots->first()->stage->name,
                    'items' => array_map(fn (array $node): array => $this->item($node, $numbers), $tree),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{item: FestivalScheduleSlot, children: array}  $node
     * @param  array<int, int>  $numbers
     * @return array<string, mixed>
     */
    private function item(array $node, array $numbers): array
    {
        $slot = $node['item'];

        return [
            'name' => $slot->displayName(),
            'type' => $slot->type->value,
            'type_label' => __('app.festival_schedule_slot_type_'.$slot->type->value),
            'category' => $slot->type === FestivalScheduleSlotType::Performance ? $slot->category?->name : null,
            'category_number' => $numbers[$slot->id] ?? null,
            'starts_at' => $slot->starts_at?->toIso8601String(),
            'ends_at' => $slot->ends_at?->toIso8601String(),
            'children' => array_map(fn (array $child): array => $this->item($child, $numbers), $node['children']),
        ];
    }
}
