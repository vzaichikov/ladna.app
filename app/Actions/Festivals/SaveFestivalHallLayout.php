<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Support\Festivals\FestivalSeatMapPng;
use App\Support\Festivals\FestivalSeatReservations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class SaveFestivalHallLayout
{
    public function __construct(
        private readonly FestivalSeatReservations $reservations,
        private readonly FestivalSeatMapPng $png,
    ) {}

    /** @param array<string,mixed> $layout */
    public function update(FestivalEdition $edition, array $layout): void
    {
        $created = [];

        try {
            DB::transaction(function () use ($edition, $layout, &$created): void {
                [$edition, $purchase] = $this->lockedEdition($edition);
                $this->assertNoHolds($edition);
                if ($edition->seats()->has('orderSeats')->exists()) {
                    $this->reject('history');
                }

                $types = $this->lockedTypes($edition);
                $floors = collect($layout['floors'])->keyBy('id');
                $seats = collect($layout['seats']);
                $this->validateSeats($floors, $seats, $types);

                $enabled = $edition->assigned_seating_enabled || $layout['publish'];
                if ($enabled) {
                    $this->publishInventory($seats, $types, $purchase);
                }

                $stored = $edition->seats()->orderBy('id')->get()->keyBy('id');
                $retained = $seats->pluck('id')->filter();
                if ($retained->diff($stored->keys())->isNotEmpty()) {
                    $this->reject('seat_missing');
                }

                $previous = collect($edition->seating_floors ?? [])->keyBy('id');
                $floors = $floors->map(function (array $floor) use ($edition, $previous, &$created): array {
                    $oldBackground = $previous->get($floor['id'])['background'] ?? null;
                    $floor['background'] = $this->storeBackground($edition, $floor, $oldBackground, $created);

                    return $floor;
                });

                $edition->seats()->whereKey($stored->keys()->diff($retained)->all())->delete();
                foreach ($seats as $seat) {
                    $id = $seat['id'] ?? null;
                    unset($seat['id']);
                    if ($id) {
                        $stored[$id]->update($seat);
                    } else {
                        $edition->seats()->create(['account_id' => $edition->account_id, ...$seat]);
                    }
                }

                $edition->update(['seating_floors' => $floors->values()->all(), 'assigned_seating_enabled' => $enabled]);
                $retired = $previous->pluck('background')->filter()->diff($floors->pluck('background'));
                DB::afterCommit(fn () => $this->deleteBackgrounds($edition, $retired));
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($created);

            throw $exception;
        }
    }

    public function mode(FestivalEdition $edition, bool $enabled): void
    {
        DB::transaction(function () use ($edition, $enabled): void {
            [$edition, $purchase] = $this->lockedEdition($edition);
            if ($edition->assigned_seating_enabled === $enabled) {
                return;
            }

            $this->assertNoHolds($edition);
            if ($enabled) {
                $types = $this->lockedTypes($edition);
                $seats = collect($edition->seats()->orderBy('id')->get()->toArray());
                $this->validateSeats(collect($edition->seating_floors ?? [])->keyBy('id'), $seats, $types);
                $this->publishInventory($seats, $types, $purchase);
            }

            $edition->update(['assigned_seating_enabled' => $enabled]);
        }, 3);
    }

    /** @param list<int> $ids */
    public function block(FestivalEdition $edition, array $ids, bool $blocked): void
    {
        DB::transaction(function () use ($edition, $ids, $blocked): void {
            [$edition] = $this->lockedEdition($edition);
            $types = $this->lockedTypes($edition);
            $seats = $edition->seats()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($seats->count() !== count($ids)) {
                $this->reject('seat_missing');
            }

            if ($this->reservations->live($edition)->whereKey($ids)->exists()) {
                $this->reject('seat_unavailable');
            }

            if (! $blocked && $edition->assigned_seating_enabled && $seats->contains(
                fn ($seat): bool => ! $types->get($seat->festival_admission_type_id)?->is_active,
            )) {
                $this->reject('type_required');
            }

            $edition->seats()->whereKey($ids)->update(['is_blocked' => $blocked]);
        }, 3);
    }

    /** @return array{FestivalEdition, ?FestivalEditionPurchase} */
    private function lockedEdition(FestivalEdition $edition): array
    {
        $edition = FestivalEdition::query()->whereKey($edition->id)->where('account_id', $edition->account_id)->lockForUpdate()->firstOrFail();
        $purchase = $edition->purchase()->with('package')->lockForUpdate()->first();
        abort_if($purchase?->status === FestivalEditionPurchaseStatus::PaymentReversed, 423, __('app.festival_payment_reversed_readonly'));

        return [$edition, $purchase];
    }

    /** @return Collection<int, FestivalAdmissionType> */
    private function lockedTypes(FestivalEdition $edition): Collection
    {
        return $edition->admissionTypes()->where('account_id', $edition->account_id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function assertNoHolds(FestivalEdition $edition): void
    {
        if ($this->reservations->activeHolds($edition)->exists()) {
            $this->reject('active_holds');
        }
    }

    private function validateSeats(Collection $floors, Collection $seats, Collection $types): void
    {
        foreach ($floors as $floor) {
            $sections = collect($floor['sections'] ?? []);
            if ($sections->map(fn (string $label): string => mb_strtolower($label))->unique()->count() !== $sections->count()) {
                $this->reject('duplicate_sections');
            }

            $blocks = collect($floor['blocks'] ?? []);
            if ($blocks->pluck('id')->unique()->count() !== $blocks->count()) {
                $this->reject('invalid_blocks');
            }

            foreach ($blocks as $block) {
                if ($block['x'] - $block['width'] / 2 < 0 || $block['x'] + $block['width'] / 2 > 10000
                    || $block['y'] - $block['height'] / 2 < 0 || $block['y'] + $block['height'] / 2 > 10000) {
                    $this->reject('invalid_blocks');
                }
            }
        }

        $labels = [];
        $positions = [];
        foreach ($seats as $seat) {
            if (! $floors->has($seat['floor_key'])) {
                $this->reject('floor_missing');
            }

            $sections = $floors->get($seat['floor_key'])['sections'] ?? null;
            if ($sections !== null && ! in_array($seat['section'], $sections, true)) {
                $this->reject('section_missing');
            }

            $typeId = $seat['festival_admission_type_id'] ?? null;
            if ($typeId && (! $types->get($typeId)?->is_assigned_seating || $types->get($typeId)->delivery_mode !== FestivalAdmissionDeliveryMode::Venue)) {
                $this->reject('type_invalid');
            }

            $label = json_encode([
                $seat['floor_key'],
                mb_strtolower($seat['section']),
                $seat['group_kind'],
                mb_strtolower($seat['group_label']),
                mb_strtolower($seat['seat_number']),
            ]);
            $position = $seat['floor_key'].'/'.$seat['x'].'/'.$seat['y'];
            if (isset($labels[$label])) {
                $this->reject('duplicate_labels');
            }
            if (isset($positions[$position])) {
                $this->reject('duplicate_positions');
            }
            $labels[$label] = true;
            $positions[$position] = true;
        }
    }

    private function publishInventory(Collection $seats, Collection $types, ?FestivalEditionPurchase $purchase): void
    {
        $sellable = $seats->reject(fn (array $seat): bool => (bool) $seat['is_blocked']);
        if ($sellable->isEmpty() || $sellable->contains(
            fn (array $seat): bool => ! $types->get($seat['festival_admission_type_id'] ?? null)?->is_active,
        )) {
            $this->reject('type_required');
        }

        $counts = $seats->pluck('festival_admission_type_id')->filter()->countBy();
        $assignedTypes = $types->filter(fn (FestivalAdmissionType $type): bool => $type->is_assigned_seating);
        $total = $types->diffKeys($assignedTypes)->sum('inventory') + $counts->sum();
        if ($purchase && $total > $purchase->package->max_tickets) {
            throw ValidationException::withMessages(['layout' => __('app.festival_ticket_inventory_limit_exceeded', ['limit' => $purchase->package->max_tickets])]);
        }

        foreach ($assignedTypes as $type) {
            $capacity = (int) $counts->get($type->id, 0);
            if ($capacity > 0 && $type->early_bird_quota > $capacity) {
                throw ValidationException::withMessages(['layout' => __('app.festival_seating_admin.early_quota', ['type' => $type->name])]);
            }

            if ($type->inventory !== $capacity) {
                $type->update(['inventory' => $capacity]);
            }
        }
    }

    /** @param array<string,mixed> $floor
     * @param  list<string>  $created
     */
    private function storeBackground(FestivalEdition $edition, array $floor, ?string $previous, array &$created): ?string
    {
        $background = $floor['background'] ?? null;
        if ($background === $previous || $background === null) {
            return $background;
        }

        $prefix = 'data:image/png;base64,';
        if (! str_starts_with($background, $prefix) || ! extension_loaded('gd')) {
            $this->reject('invalid_png');
        }

        try {
            $image = $this->png->parse(base64_decode(substr($background, strlen($prefix)), true) ?: '');
        } catch (InvalidArgumentException) {
            $this->reject('invalid_png');
        }

        if ($image['seats'] !== [] || $image['warnings'] !== [] || $image['width'] !== (int) $floor['width'] || $image['height'] !== (int) $floor['height']) {
            $this->reject('invalid_png');
        }

        $path = $this->storagePrefix($edition).Str::uuid().'.png';
        $created[] = $path;
        abort_unless(Storage::disk('public')->put($path, $image['background']), 500);

        return '/storage/'.$path;
    }

    private function deleteBackgrounds(FestivalEdition $edition, Collection $backgrounds): void
    {
        $prefix = '/storage/'.$this->storagePrefix($edition);
        $paths = $backgrounds->filter(fn (string $url): bool => str_starts_with($url, $prefix))->map(fn (string $url): string => substr($url, strlen('/storage/')));

        Storage::disk('public')->delete($paths->values()->all());
    }

    private function storagePrefix(FestivalEdition $edition): string
    {
        return 'festivals/'.$edition->account_id.'/'.$edition->id.'/seating/';
    }

    private function reject(string $reason): never
    {
        throw ValidationException::withMessages(['layout' => __("app.festival_seating_admin.{$reason}")]);
    }
}
