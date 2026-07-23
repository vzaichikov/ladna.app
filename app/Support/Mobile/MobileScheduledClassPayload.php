<?php

namespace App\Support\Mobile;

use App\Enums\ClassBookingStatus;
use App\Models\Customer;
use App\Models\ScheduledClass;

class MobileScheduledClassPayload
{
    /**
     * @return array<string, mixed>
     */
    public function forClass(ScheduledClass $scheduledClass, ?Customer $customer = null, bool $includeBookings = false): array
    {
        $timezone = $scheduledClass->displayTimezone();
        $scheduledClass->loadMissing([
            'location',
            'room',
            'classType.activityDirection',
            'trainer',
            'additionalTrainers',
        ]);

        $activeBookingStatuses = [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ];
        $acceptsCustomerBookings = $scheduledClass->acceptsCustomerBookings();
        $activeBookingsCount = $acceptsCustomerBookings
            ? ($scheduledClass->relationLoaded('classBookings')
                ? $scheduledClass->classBookings
                    ->whereNull('corrected_removed_at')
                    ->filter(fn ($booking): bool => in_array($booking->status->value, $activeBookingStatuses, true))
                    ->count()
                : $scheduledClass->classBookings()
                    ->notCorrectedRemoved()
                    ->whereIn('status', $activeBookingStatuses)
                    ->count())
            : 0;
        $capacity = (int) ($scheduledClass->capacity ?? 0);
        $customerBooking = null;

        if ($customer && $acceptsCustomerBookings) {
            $booking = $scheduledClass->relationLoaded('classBookings')
                ? $scheduledClass->classBookings
                    ->where('customer_id', $customer->id)
                    ->whereNull('corrected_removed_at')
                    ->first()
                : $scheduledClass->classBookings()
                    ->notCorrectedRemoved()
                    ->whereBelongsTo($customer)
                    ->first();

            $customerBooking = $booking ? [
                'id' => $booking->id,
                'status' => $booking->status->value,
            ] : null;
        }

        $data = [
            'id' => $scheduledClass->id,
            'title' => $scheduledClass->displayTitle(),
            'description' => $scheduledClass->description,
            'starts_at' => $scheduledClass->starts_at->copy()->timezone($timezone)->toIso8601String(),
            'ends_at' => $scheduledClass->ends_at->copy()->timezone($timezone)->toIso8601String(),
            'timezone' => $timezone,
            'status' => $scheduledClass->displayStatusValue(),
            'schedule_kind' => $scheduledClass->classType?->schedule_kind?->value,
            'location' => $scheduledClass->location ? [
                'id' => $scheduledClass->location->id,
                'name' => $scheduledClass->location->name,
                'slug' => $scheduledClass->location->slug,
            ] : null,
            'room' => $scheduledClass->room ? [
                'id' => $scheduledClass->room->id,
                'name' => $scheduledClass->room->name,
                'slug' => $scheduledClass->room->slug,
            ] : null,
            'class_type' => $scheduledClass->classType ? [
                'id' => $scheduledClass->classType->id,
                'name' => $scheduledClass->classType->name,
                'slug' => $scheduledClass->classType->slug,
            ] : null,
            'activity_direction' => $scheduledClass->classType?->activityDirection ? [
                'id' => $scheduledClass->classType->activityDirection->id,
                'name' => $scheduledClass->classType->activityDirection->name,
                'slug' => $scheduledClass->classType->activityDirection->slug,
                'color' => $scheduledClass->classType->activityDirection->color,
            ] : null,
            'trainer' => $scheduledClass->trainer ? [
                'id' => $scheduledClass->trainer->id,
                'name' => $scheduledClass->trainer->name,
                'photo_url' => $scheduledClass->trainer->photoUrl(),
            ] : null,
            'additional_trainers' => $scheduledClass->additionalTrainers
                ->map(fn ($trainer): array => [
                    'id' => $trainer->id,
                    'name' => $trainer->name,
                    'photo_url' => $trainer->photoUrl(),
                ])
                ->values()
                ->all(),
            'capacity' => $capacity,
            'booked_count' => $activeBookingsCount,
            'available_spots' => $acceptsCustomerBookings && $capacity > 0 ? max(0, $capacity - $activeBookingsCount) : null,
            'booking_open' => $acceptsCustomerBookings && $scheduledClass->isBookingOpen(),
            'customer_booking' => $customerBooking,
        ];

        if ($includeBookings) {
            $bookings = $acceptsCustomerBookings
                ? ($scheduledClass->relationLoaded('classBookings')
                    ? $scheduledClass->classBookings
                    : $scheduledClass->classBookings()
                        ->notCorrectedRemoved()
                        ->with(['customer', 'classPassReservation.customerClassPass'])
                        ->get())
                : collect();

            $data['bookings'] = $bookings
                ->whereNull('corrected_removed_at')
                ->map(fn ($booking): array => [
                    'id' => $booking->id,
                    'status' => $booking->status->value,
                    'notes' => $booking->notes,
                    'customer' => $booking->customer ? [
                        'id' => $booking->customer->id,
                        'name' => $booking->customer->name,
                        'email' => $booking->customer->email,
                        'phone' => $booking->customer->phone,
                    ] : null,
                    'class_pass' => $booking->classPassReservation?->customerClassPass ? [
                        'id' => $booking->classPassReservation->customerClassPass->id,
                        'code' => $booking->classPassReservation->customerClassPass->code,
                        'plan_name' => $booking->classPassReservation->customerClassPass->plan_name,
                    ] : null,
                ])
                ->values()
                ->all();
        }

        return $data;
    }
}
