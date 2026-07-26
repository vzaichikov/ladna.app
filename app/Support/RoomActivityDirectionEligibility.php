<?php

namespace App\Support;

use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassType;
use App\Models\Room;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class RoomActivityDirectionEligibility
{
    public function accountHasActiveDirections(Account $account): bool
    {
        return $account->activityDirections()->active()->exists();
    }

    public function activeDirectionId(Account $account, mixed $activityDirectionId): ?int
    {
        if (blank($activityDirectionId)) {
            return null;
        }

        $activityDirectionId = (int) $activityDirectionId;

        if ($activityDirectionId <= 0) {
            return null;
        }

        return $account->activityDirections()
            ->active()
            ->whereKey($activityDirectionId)
            ->value('id');
    }

    public function classTypeDirectionId(Account $account, ClassType $classType): ?int
    {
        if ($classType->account_id !== $account->id || blank($classType->activity_direction_id)) {
            return null;
        }

        if ($classType->relationLoaded('activityDirection')) {
            $activityDirection = $classType->activityDirection;

            return $activityDirection
                && $activityDirection->account_id === $account->id
                && $activityDirection->is_active
                    ? $activityDirection->id
                    : null;
        }

        return $account->activityDirections()
            ->active()
            ->whereKey((int) $classType->activity_direction_id)
            ->value('id');
    }

    public function effectiveDirectionId(Account $account, ClassType $classType, ?int $activityDirectionId): ?int
    {
        if ($classType->account_id !== $account->id) {
            return null;
        }

        if ($classType->schedule_kind === ScheduleKind::GroupClass) {
            return $this->classTypeDirectionId($account, $classType);
        }

        if ($classType->schedule_kind === ScheduleKind::PrivateLesson) {
            return $this->classTypeDirectionId($account, $classType)
                ?? $this->activeDirectionId($account, $activityDirectionId);
        }

        return null;
    }

    public function roomCanHost(
        Account $account,
        Room $room,
        ClassType $classType,
        ?int $activityDirectionId = null,
    ): bool {
        if ($room->account_id !== $account->id || $classType->account_id !== $account->id) {
            return false;
        }

        if ($this->classTypeBypassesDirectionRestriction($classType)) {
            return true;
        }

        return $this->roomCanHostDirection(
            $account,
            $room,
            $this->effectiveDirectionId($account, $classType, $activityDirectionId),
        );
    }

    public function roomCanHostDirection(Account $account, Room $room, ?int $activityDirectionId): bool
    {
        if ($room->account_id !== $account->id) {
            return false;
        }

        if ($room->relationLoaded('activityDirections')) {
            /** @var Collection<int, ActivityDirection> $activityDirections */
            $activityDirections = $room->activityDirections
                ->filter(fn (ActivityDirection $activityDirection): bool => (int) $activityDirection->pivot->account_id === $account->id);

            return $activityDirections->isEmpty()
                || ($activityDirectionId !== null
                    && $activityDirections->contains(fn (ActivityDirection $activityDirection): bool => $activityDirection->id === $activityDirectionId));
        }

        $assignedDirectionIds = $room->activityDirections()
            ->where('room_activity_direction.account_id', $account->id)
            ->pluck('activity_directions.id');

        return $assignedDirectionIds->isEmpty()
            || ($activityDirectionId !== null && $assignedDirectionIds->contains($activityDirectionId));
    }

    /**
     * @param  Collection<int, Room>  $rooms
     * @return Collection<int, Room>
     */
    public function filterRooms(
        Collection $rooms,
        Account $account,
        ClassType $classType,
        ?int $activityDirectionId = null,
    ): Collection {
        if ($classType->account_id !== $account->id) {
            return $rooms->reject(fn (): bool => true)->values();
        }

        if ($this->classTypeBypassesDirectionRestriction($classType)) {
            return $rooms
                ->filter(fn (Room $room): bool => $room->account_id === $account->id)
                ->values();
        }

        return $this->filterRoomsForDirection(
            $rooms,
            $account,
            $this->effectiveDirectionId($account, $classType, $activityDirectionId),
        );
    }

    /**
     * @param  Collection<int, Room>  $rooms
     * @return Collection<int, Room>
     */
    public function filterRoomsForDirection(
        Collection $rooms,
        Account $account,
        ?int $activityDirectionId,
    ): Collection {
        if ($rooms instanceof EloquentCollection) {
            $rooms->loadMissing('activityDirections');
        }

        return $rooms
            ->filter(fn (Room $room): bool => $this->roomCanHostDirection($account, $room, $activityDirectionId))
            ->values();
    }

    public function scopeRoomQuery(
        Builder|Relation $query,
        Account $account,
        ClassType $classType,
        ?int $activityDirectionId = null,
    ): Builder|Relation {
        if ($classType->account_id !== $account->id) {
            return $query->whereRaw('0 = 1');
        }

        if ($this->classTypeBypassesDirectionRestriction($classType)) {
            return $query;
        }

        return $this->scopeRoomQueryForDirection(
            $query,
            $account,
            $this->effectiveDirectionId($account, $classType, $activityDirectionId),
        );
    }

    public function scopeRoomQueryForDirection(
        Builder|Relation $query,
        Account $account,
        ?int $activityDirectionId,
    ): Builder|Relation {
        return $query->where(function (Builder $query) use ($account, $activityDirectionId): void {
            $query->whereDoesntHave('activityDirections', fn (Builder $query) => $query
                ->where('room_activity_direction.account_id', $account->id));

            if ($activityDirectionId !== null) {
                $query->orWhereHas('activityDirections', fn (Builder $query) => $query
                    ->where('room_activity_direction.account_id', $account->id)
                    ->whereKey($activityDirectionId));
            }
        });
    }

    private function classTypeBypassesDirectionRestriction(ClassType $classType): bool
    {
        return in_array($classType->schedule_kind, [
            ScheduleKind::RoomRental,
            ScheduleKind::InternalClass,
        ], true);
    }
}
