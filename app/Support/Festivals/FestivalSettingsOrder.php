<?php

namespace App\Support\Festivals;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

final class FestivalSettingsOrder
{
    public function move(Model $model, Builder|Relation $query, string $direction): void
    {
        $this->moveItems($model, $query, $direction);
    }

    /** @param Closure(Model): bool $isMovable */
    public function moveWithin(Model $model, Builder|Relation $query, string $direction, Closure $isMovable): void
    {
        $this->moveItems($model, $query, $direction, $isMovable);
    }

    /** @param null|Closure(Model): bool $isMovable */
    private function moveItems(Model $model, Builder|Relation $query, string $direction, ?Closure $isMovable = null): void
    {
        DB::transaction(function () use ($model, $query, $direction, $isMovable): void {
            $items = (clone $query)
                ->reorder()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($items as $index => $item) {
                $item->forceFill(['sort_order' => ($index + 1) * 10])->save();
            }

            $movableItems = $isMovable === null
                ? $items
                : $items->filter($isMovable)->values();
            $index = $movableItems->search(fn (Model $item): bool => $item->is($model));

            if ($index === false) {
                return;
            }

            $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;

            if (! $movableItems->has($targetIndex)) {
                return;
            }

            $target = $movableItems[$targetIndex];
            $currentOrder = $movableItems[$index]->sort_order;
            $movableItems[$index]->update(['sort_order' => $target->sort_order]);
            $target->update(['sort_order' => $currentOrder]);
        }, 3);
    }

    public function next(Builder|Relation $query): int
    {
        return ((int) (clone $query)->max('sort_order')) + 10;
    }
}
