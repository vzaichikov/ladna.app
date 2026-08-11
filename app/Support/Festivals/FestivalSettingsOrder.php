<?php

namespace App\Support\Festivals;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

final class FestivalSettingsOrder
{
    public function move(Model $model, Builder|Relation $query, string $direction): void
    {
        DB::transaction(function () use ($model, $query, $direction): void {
            $items = (clone $query)
                ->reorder()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($items as $index => $item) {
                $item->forceFill(['sort_order' => ($index + 1) * 10])->save();
            }

            $index = $items->search(fn (Model $item): bool => $item->is($model));

            if ($index === false) {
                return;
            }

            $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;

            if (! $items->has($targetIndex)) {
                return;
            }

            $target = $items[$targetIndex];
            $currentOrder = $items[$index]->sort_order;
            $items[$index]->update(['sort_order' => $target->sort_order]);
            $target->update(['sort_order' => $currentOrder]);
        }, 3);
    }

    public function next(Builder|Relation $query): int
    {
        return ((int) (clone $query)->max('sort_order')) + 10;
    }
}
