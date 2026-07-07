<?php

namespace App\Support\Reports;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class UnpaidClassBookingPaymentsReport
{
    public function countForAccount(Account $account): int
    {
        return $this->matchingBookings($account)->count();
    }

    public function paginateForAccount(Account $account, int $perPage = 25): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();
        $bookings = $this->matchingBookings($account);

        return new LengthAwarePaginator(
            $bookings->forPage($page, $perPage)->values(),
            $bookings->count(),
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * @return Collection<int, ClassBooking>
     */
    private function matchingBookings(Account $account): Collection
    {
        return $this->candidateQuery($account)
            ->get()
            ->filter(fn (ClassBooking $booking): bool => $booking->manualCashPaymentDueKind($booking->scheduledClass) !== null)
            ->values();
    }

    private function candidateQuery(Account $account): Builder
    {
        $bookingsTable = (new ClassBooking)->getTable();
        $classesTable = (new ScheduledClass)->getTable();

        return ClassBooking::query()
            ->select("{$bookingsTable}.*")
            ->join($classesTable, "{$classesTable}.id", '=', "{$bookingsTable}.scheduled_class_id")
            ->where("{$bookingsTable}.account_id", $account->id)
            ->whereNull("{$bookingsTable}.corrected_removed_at")
            ->whereIn("{$bookingsTable}.status", [
                ClassBookingStatus::Booked->value,
                ClassBookingStatus::Attended->value,
            ])
            ->where("{$classesTable}.status", ScheduledClassStatus::Scheduled->value)
            ->whereDoesntHave('manualCashPayment')
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->whereHas('scheduledClass.classType', fn (Builder $query): Builder => $query->where('schedule_kind', ScheduleKind::RoomRental->value))
                            ->whereDoesntHave('classPassReservation', fn (Builder $query): Builder => $this->activeReservationQuery($query));
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->whereHas('classPassReservation', function (Builder $query): Builder {
                            return $this->activeReservationQuery($query)
                                ->whereHas('customerClassPass', fn (Builder $query): Builder => $query
                                    ->where('allows_any_time', true)
                                    ->where('any_time_addon_price_cents', '>', 0));
                        });
                    });
            })
            ->with([
                'customer:id,account_id,name,phone,email',
                'scheduledClass.account:id,timezone',
                'scheduledClass.location:id,account_id,name,timezone',
                'scheduledClass.room:id,account_id,location_id,name',
                'scheduledClass.classType:id,account_id,name,schedule_kind',
                'scheduledClass.trainer:id,account_id,name',
                'manualCashPayment',
                'classPassReservation.customerClassPass',
            ])
            ->orderBy("{$classesTable}.starts_at")
            ->orderBy("{$bookingsTable}.id");
    }

    private function activeReservationQuery(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CustomerClassPassReservationStatus::Reserved->value,
            CustomerClassPassReservationStatus::Used->value,
        ]);
    }
}
