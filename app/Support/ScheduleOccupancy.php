<?php

namespace App\Support;

use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ScheduleOccupancy
{
    public function lockAccount(Account $account): void
    {
        Account::query()
            ->whereKey($account->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<int, int>  $trainerIds
     */
    public function lockResources(Account $account, ?int $roomId, array $trainerIds): void
    {
        if ($roomId !== null) {
            Room::query()
                ->whereBelongsTo($account)
                ->whereKey($roomId)
                ->lockForUpdate()
                ->first();
        }

        $trainerIds = $this->normalizeTrainerIds($trainerIds);

        if ($trainerIds !== []) {
            Trainer::query()
                ->whereBelongsTo($account)
                ->whereKey($trainerIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }
    }

    /**
     * @param  array<int, int>  $trainerIds
     */
    public function assertAvailable(
        Account $account,
        ?int $roomId,
        array $trainerIds,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $exceptScheduledClassId = null,
    ): void {
        if ($this->conflictsQuery($account, $roomId, $trainerIds, $startsAt, $endsAt, $exceptScheduledClassId)->exists()) {
            throw ValidationException::withMessages([
                'starts_at' => __('app.manual_slot_unavailable'),
            ]);
        }
    }

    /**
     * @param  array<int, int>  $trainerIds
     */
    public function hasInternalClassConflict(
        Account $account,
        ?int $roomId,
        array $trainerIds,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $exceptScheduledClassId = null,
    ): bool {
        return $this->conflictsQuery($account, $roomId, $trainerIds, $startsAt, $endsAt, $exceptScheduledClassId)
            ->whereHas('classType', fn (Builder $query) => $query
                ->where('schedule_kind', ScheduleKind::InternalClass->value))
            ->exists();
    }

    /**
     * @param  array<int, int>  $trainerIds
     * @return Builder<ScheduledClass>
     */
    private function conflictsQuery(
        Account $account,
        ?int $roomId,
        array $trainerIds,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $exceptScheduledClassId,
    ): Builder {
        $trainerIds = $this->normalizeTrainerIds($trainerIds);

        return ScheduledClass::query()
            ->whereBelongsTo($account)
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->when($exceptScheduledClassId !== null, fn (Builder $query) => $query->whereKeyNot($exceptScheduledClassId))
            ->where(function (Builder $query) use ($roomId, $trainerIds): void {
                if ($roomId !== null) {
                    $query->where('room_id', $roomId);
                }

                if ($trainerIds !== []) {
                    $method = $roomId === null ? 'where' : 'orWhere';
                    $query->{$method}(function (Builder $query) use ($trainerIds): void {
                        $query
                            ->whereIn('trainer_id', $trainerIds)
                            ->orWhereHas('additionalTrainers', fn (Builder $query) => $query
                                ->whereKey($trainerIds));
                    });
                }

                if ($roomId === null && $trainerIds === []) {
                    $query->whereRaw('1 = 0');
                }
            });
    }

    /**
     * @param  array<int, int>  $trainerIds
     * @return array<int, int>
     */
    private function normalizeTrainerIds(array $trainerIds): array
    {
        return collect($trainerIds)
            ->map(fn (mixed $trainerId): int => (int) $trainerId)
            ->filter(fn (int $trainerId): bool => $trainerId > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
