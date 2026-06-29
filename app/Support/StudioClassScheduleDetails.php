<?php

namespace App\Support;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

class StudioClassScheduleDetails
{
    /**
     * @return array<string, mixed>
     */
    public function forDay(
        Account $account,
        Carbon $day,
        bool $includeCancelledClasses = false,
        bool $includeCancelledBookings = false,
        int $classLimit = 40,
        int $bookingLimitPerClass = 30,
    ): array {
        $timezone = $account->timezone ?: config('app.timezone');
        $accountDay = $day->copy()->timezone($timezone)->startOfDay();
        $classes = $this->classesForDay(
            $account,
            $accountDay,
            $includeCancelledClasses,
            max(1, $classLimit) + 1,
            $includeCancelledBookings,
        );
        $truncated = $classes->count() > $classLimit;
        $mappedClasses = $classes
            ->take($classLimit)
            ->map(fn (ScheduledClass $scheduledClass): array => $this->mapClass($scheduledClass, $timezone, $bookingLimitPerClass))
            ->values();

        return [
            'account' => [
                'name' => $account->name,
                'timezone' => $timezone,
            ],
            'date' => $accountDay->toDateString(),
            'include_cancelled_classes' => $includeCancelledClasses,
            'include_cancelled_bookings' => $includeCancelledBookings,
            'truncated' => $truncated,
            'total_classes' => $mappedClasses->count(),
            'total_bookings' => $mappedClasses->sum('bookings_count'),
            'by_trainer' => $mappedClasses
                ->groupBy(fn (array $class): string => (string) ($class['trainer']['id'] ?? 'none'))
                ->map(fn ($trainerClasses): array => [
                    'trainer_id' => $trainerClasses->first()['trainer']['id'] ?? null,
                    'trainer_name' => $trainerClasses->first()['trainer']['name'] ?? null,
                    'classes_count' => $trainerClasses->count(),
                    'bookings_count' => $trainerClasses->sum('bookings_count'),
                ])
                ->values()
                ->all(),
            'classes' => $mappedClasses->all(),
        ];
    }

    /**
     * @return EloquentCollection<int, ScheduledClass>
     */
    private function classesForDay(Account $account, Carbon $day, bool $includeCancelledClasses, int $limit, bool $includeCancelledBookings): EloquentCollection
    {
        return ScheduledClass::query()
            ->whereBelongsTo($account)
            ->whereBetween('starts_at', [$day->copy()->timezone('UTC'), $day->copy()->endOfDay()->timezone('UTC')])
            ->when(! $includeCancelledClasses, fn ($query) => $query->where('status', ScheduledClassStatus::Scheduled->value))
            ->with([
                'location:id,account_id,name,timezone',
                'room:id,account_id,location_id,name',
                'trainer:id,account_id,name',
                'classType:id,account_id,activity_direction_id,name,schedule_kind',
                'classType.activityDirection:id,account_id,name',
                'classBookings' => function ($query) use ($account, $includeCancelledBookings): void {
                    $query
                        ->whereBelongsTo($account)
                        ->when(! $includeCancelledBookings, fn ($query) => $query->where('status', ClassBookingStatus::Booked->value))
                        ->with([
                            'customer:id,account_id,name',
                            'classPassReservation:id,account_id,class_booking_id,customer_class_pass_id,status',
                            'classPassReservation.customerClassPass:id,account_id,customer_id,code,plan_name,status,is_paid,sessions_count,reserved_sessions_count,used_sessions_count',
                        ])
                        ->orderBy('id');
                },
            ])
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapClass(ScheduledClass $scheduledClass, string $timezone, int $bookingLimitPerClass): array
    {
        $startsAt = $scheduledClass->starts_at->copy()->timezone($timezone);
        $endsAt = $scheduledClass->ends_at->copy()->timezone($timezone);
        $bookings = $scheduledClass->classBookings->take(max(1, $bookingLimitPerClass));
        $bookingsCount = $scheduledClass->classBookings->count();
        $capacity = $scheduledClass->capacity;

        return [
            'scheduled_class_id' => $scheduledClass->id,
            'title' => $scheduledClass->title,
            'status' => $scheduledClass->status->value,
            'schedule_kind' => $scheduledClass->classType?->schedule_kind?->value,
            'direction' => $scheduledClass->classType?->activityDirection?->name,
            'class_type' => $scheduledClass->classType?->name,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'time' => $startsAt->format('H:i'),
            'time_range' => $startsAt->format('H:i').'-'.$endsAt->format('H:i'),
            'duration_minutes' => (int) $scheduledClass->starts_at->diffInMinutes($scheduledClass->ends_at),
            'location' => [
                'id' => $scheduledClass->location?->id,
                'name' => $scheduledClass->location?->name,
            ],
            'room' => [
                'id' => $scheduledClass->room?->id,
                'name' => $scheduledClass->room?->name,
            ],
            'trainer' => [
                'id' => $scheduledClass->trainer?->id,
                'name' => $scheduledClass->trainer?->name,
            ],
            'capacity' => $capacity,
            'bookings_count' => $bookingsCount,
            'bookings_truncated' => $scheduledClass->classBookings->count() > $bookingLimitPerClass,
            'available_spots' => $capacity === null ? null : max(0, (int) $capacity - $bookingsCount),
            'bookings' => $bookings
                ->map(fn (ClassBooking $booking): array => $this->mapBooking($booking))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBooking(ClassBooking $booking): array
    {
        $classPass = $booking->classPassReservation?->customerClassPass;

        return [
            'booking_id' => $booking->id,
            'status' => $booking->status->value,
            'customer' => [
                'id' => $booking->customer?->id,
                'name' => $booking->customer?->name,
            ],
            'class_pass' => $classPass ? [
                'id' => $classPass->id,
                'code' => $classPass->code,
                'plan_name' => $classPass->plan_name,
                'status' => $classPass->status->value,
                'is_paid' => $classPass->is_paid,
                'sessions_count' => $classPass->sessions_count,
                'reserved_sessions_count' => $classPass->reserved_sessions_count,
                'used_sessions_count' => $classPass->used_sessions_count,
            ] : null,
            'reservation_status' => $booking->classPassReservation?->status?->value,
        ];
    }
}
