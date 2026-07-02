<?php

namespace App\Support;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class UnreservedClassPassBookingIssues
{
    public function queryForAccount(Account $account): Builder
    {
        return $this->queryForAccountId((int) $account->id);
    }

    public function queryForAccountId(int $accountId): Builder
    {
        return $this->baseQueryForAccountId($accountId)
            ->select((new ClassBooking)->getTable().'.*')
            ->orderBy((new ScheduledClass)->getTable().'.starts_at')
            ->orderBy((new ClassBooking)->getTable().'.id');
    }

    private function baseQueryForAccountId(int $accountId): Builder
    {
        $classBookingTable = (new ClassBooking)->getTable();
        $scheduledClassTable = (new ScheduledClass)->getTable();

        return ClassBooking::query()
            ->join($scheduledClassTable, "{$scheduledClassTable}.id", '=', "{$classBookingTable}.scheduled_class_id")
            ->where("{$classBookingTable}.account_id", $accountId)
            ->whereIn("{$classBookingTable}.status", array_map(
                fn (ClassBookingStatus $status): string => $status->value,
                ClassBookingStatus::cases(),
            ))
            ->where("{$classBookingTable}.skip_class_pass_reservation", false)
            ->where("{$scheduledClassTable}.account_id", $accountId)
            ->where("{$scheduledClassTable}.status", ScheduledClassStatus::Scheduled->value)
            ->whereDoesntHave('classPassReservation', fn ($query) => $query->whereIn('status', [
                CustomerClassPassReservationStatus::Reserved->value,
                CustomerClassPassReservationStatus::Used->value,
            ]));
    }

    public function queryForAccountCustomer(int $accountId, int $customerId): Builder
    {
        $classBookingTable = (new ClassBooking)->getTable();

        return $this->queryForAccountId($accountId)
            ->where("{$classBookingTable}.customer_id", $customerId);
    }

    public function countForAccount(Account $account): int
    {
        return $this->queryForAccount($account)->count();
    }

    /**
     * @return Collection<int, int>
     */
    public function countsByTrainer(Account $account): Collection
    {
        $scheduledClassTable = (new ScheduledClass)->getTable();

        return $this->baseQueryForAccountId((int) $account->id)
            ->whereNotNull("{$scheduledClassTable}.trainer_id")
            ->selectRaw("{$scheduledClassTable}.trainer_id as trainer_id, count(*) as bookings_count")
            ->groupBy("{$scheduledClassTable}.trainer_id")
            ->pluck('bookings_count', 'trainer_id')
            ->map(fn ($count): int => (int) $count);
    }

    /**
     * @return Collection<int, Collection<int, ClassBooking>>
     */
    public function bookingsByTrainer(Account $account): Collection
    {
        return $this->queryForAccount($account)
            ->with([
                'customer:id,name,phone,email',
                'scheduledClass:id,account_id,location_id,room_id,class_type_id,trainer_id,title,starts_at,ends_at,status',
                'scheduledClass.location:id,name',
                'scheduledClass.room:id,name',
            ])
            ->get()
            ->groupBy(fn (ClassBooking $booking): int => (int) $booking->scheduledClass?->trainer_id)
            ->filter(fn (Collection $bookings, int $trainerId): bool => $trainerId > 0);
    }
}
