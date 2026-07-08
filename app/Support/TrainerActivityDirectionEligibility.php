<?php

namespace App\Support;

use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassType;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class TrainerActivityDirectionEligibility
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

        return $account->activityDirections()
            ->active()
            ->whereKey((int) $classType->activity_direction_id)
            ->value('id');
    }

    public function classTypeMatchesDirection(Account $account, ClassType $classType, ?int $activityDirectionId): bool
    {
        if ($classType->account_id !== $account->id) {
            return false;
        }

        if (! $activityDirectionId) {
            return true;
        }

        $classTypeDirectionId = $this->classTypeDirectionId($account, $classType);

        return $classTypeDirectionId === null || $classTypeDirectionId === $activityDirectionId;
    }

    public function effectiveDirectionId(Account $account, ClassType $classType, ?int $activityDirectionId): ?int
    {
        return $this->classTypeDirectionId($account, $classType) ?? $activityDirectionId;
    }

    public function trainerCanHandle(Account $account, Trainer $trainer, ClassType $classType, ?int $activityDirectionId): bool
    {
        if ($trainer->account_id !== $account->id || ! $this->classTypeMatchesDirection($account, $classType, $activityDirectionId)) {
            return false;
        }

        return $this->trainerCanHandleDirection(
            $account,
            $trainer,
            $this->effectiveDirectionId($account, $classType, $activityDirectionId),
        );
    }

    public function trainerCanHandleDirection(Account $account, Trainer $trainer, ?int $activityDirectionId): bool
    {
        if ($trainer->account_id !== $account->id) {
            return false;
        }

        if (! $activityDirectionId) {
            return true;
        }

        if ($trainer->relationLoaded('activityDirections')) {
            /** @var Collection<int, ActivityDirection> $activityDirections */
            $activityDirections = $trainer->activityDirections;

            return $activityDirections->isEmpty()
                || $activityDirections->contains(fn (ActivityDirection $activityDirection): bool => $activityDirection->id === $activityDirectionId);
        }

        $assignedDirectionIds = $trainer->activityDirections()
            ->where('trainer_activity_direction.account_id', $account->id)
            ->pluck('activity_directions.id');

        return $assignedDirectionIds->isEmpty() || $assignedDirectionIds->contains($activityDirectionId);
    }

    /**
     * @param  Collection<int, ClassType>  $classTypes
     * @return Collection<int, ClassType>
     */
    public function filterClassTypes(Collection $classTypes, Account $account, ?int $activityDirectionId): Collection
    {
        if (! $activityDirectionId) {
            return $classTypes;
        }

        return $classTypes
            ->filter(fn (ClassType $classType): bool => $this->classTypeMatchesDirection($account, $classType, $activityDirectionId))
            ->values();
    }

    /**
     * @param  Collection<int, Trainer>  $trainers
     * @return Collection<int, Trainer>
     */
    public function filterTrainers(Collection $trainers, Account $account, ?int $activityDirectionId): Collection
    {
        if (! $activityDirectionId) {
            return $trainers;
        }

        return $trainers
            ->filter(fn (Trainer $trainer): bool => $this->trainerCanHandleDirection($account, $trainer, $activityDirectionId))
            ->values();
    }

    public function scopeTrainerQueryForDirection(Builder|Relation $query, Account $account, ?int $activityDirectionId): Builder|Relation
    {
        if (! $activityDirectionId) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($account, $activityDirectionId): void {
            $query
                ->whereDoesntHave('activityDirections', fn (Builder $query) => $query
                    ->where('trainer_activity_direction.account_id', $account->id))
                ->orWhereHas('activityDirections', fn (Builder $query) => $query
                    ->where('trainer_activity_direction.account_id', $account->id)
                    ->whereKey($activityDirectionId));
        });
    }
}
