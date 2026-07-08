<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\ScheduledClass;
use App\Support\CustomerNotifications\ClassBookingNotificationCoordinator;
use App\Support\ManualQuickBookingAvailability;
use App\Support\ScheduleKindRegistry;
use App\Support\TrainerActivityDirectionEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePublicBooking
{
    public function __construct(
        private readonly ResolveQuickBookingCustomer $resolveQuickBookingCustomer,
        private readonly ReserveCustomerClassPassForBooking $reserveCustomerClassPassForBooking,
        private readonly ManualQuickBookingAvailability $manualQuickBookingAvailability,
        private readonly ClassBookingNotificationCoordinator $notifications,
        private readonly TrainerActivityDirectionEligibility $trainerActivityDirectionEligibility,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(Account $account, Location $location, ?Customer $authenticatedCustomer, array $validated): ClassBooking
    {
        $isGuestBooking = ! $authenticatedCustomer instanceof Customer;

        $classBooking = DB::transaction(function () use ($account, $location, $authenticatedCustomer, $validated, $isGuestBooking): ClassBooking {
            $customer = $authenticatedCustomer ?? $this->resolveQuickBookingCustomer->execute($account, $validated);
            $scheduleKind = ScheduleKind::from((string) $validated['schedule_kind']);
            $scheduledClass = $scheduleKind === ScheduleKind::GroupClass
                ? $this->groupScheduledClass($account, $location, (int) $validated['scheduled_class_id'], $customer->id)
                : $this->createManualScheduledClass($account, $location, $scheduleKind, $validated);

            $classBooking = $scheduledClass->classBookings()->updateOrCreate(
                ['customer_id' => $customer->id],
                [
                    'account_id' => $account->id,
                    'booked_by_user_id' => null,
                    'booked_by_actor_user_id' => null,
                    'booked_by_actor_trainer_id' => null,
                    'booked_by_actor_name' => $customer->name ?: ($customer->phone ?: __('app.public_booking_customer')),
                    'booked_by_actor_email' => $customer->email,
                    'booked_by_actor_role' => $isGuestBooking ? 'public_guest' : 'customer',
                    'status' => ClassBookingStatus::Booked->value,
                    'attended_at' => null,
                    'notes' => $validated['notes'] ?? null,
                ],
            );

            $this->reserveCustomerClassPassForBooking->execute($classBooking);

            return $classBooking;
        });

        if ($classBooking->wasRecentlyCreated || $classBooking->wasChanged('status')) {
            $this->notifications->bookingCreated($classBooking);
        }

        return $classBooking;
    }

    private function groupScheduledClass(Account $account, Location $location, int $scheduledClassId, int $customerId): ScheduledClass
    {
        $scheduledClass = $account->scheduledClasses()
            ->with('classType')
            ->whereKey($scheduledClassId)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $scheduledClass->location_id !== $location->id
            || ! $scheduledClass->is_public
            || $scheduledClass->status !== ScheduledClassStatus::Scheduled
            || $scheduledClass->starts_at->lessThan(now())
            || $scheduledClass->classType?->schedule_kind !== ScheduleKind::GroupClass
        ) {
            throw ValidationException::withMessages([
                'scheduled_class_id' => __('app.quick_booking_group_class_invalid'),
            ]);
        }

        if (! $scheduledClass->isBookingOpen()) {
            throw ValidationException::withMessages([
                'scheduled_class_id' => __('app.booking_cutoff_closed'),
            ]);
        }

        $this->ensureGroupCapacity($scheduledClass, $customerId);

        return $scheduledClass;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createManualScheduledClass(Account $account, Location $location, ScheduleKind $scheduleKind, array $validated): ScheduledClass
    {
        if (! $account->hasScheduleKindEnabled($scheduleKind) || ! in_array($scheduleKind, ScheduleKindRegistry::manualKinds(), true)) {
            throw ValidationException::withMessages([
                'schedule_kind' => __('app.manual_class_format_invalid'),
            ]);
        }

        $room = $account->rooms()
            ->active()
            ->whereKey((int) $validated['room_id'])
            ->where('location_id', $location->id)
            ->firstOrFail();
        $classType = $account->classTypes()
            ->active()
            ->whereKey((int) $validated['class_type_id'])
            ->where('schedule_kind', $scheduleKind->value)
            ->firstOrFail();
        $trainer = filled($validated['trainer_id'] ?? null)
            ? $account->trainers()->active()->whereKey((int) $validated['trainer_id'])->firstOrFail()
            : null;
        $activityDirectionId = $this->trainerActivityDirectionEligibility->activeDirectionId($account, $validated['activity_direction_id'] ?? null);
        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $validated['starts_at'], $timezone);
        $durationMinutes = (int) ($classType->default_duration_minutes ?: 60);
        $endsAt = $startsAt->addMinutes($durationMinutes);

        if ($scheduleKind === ScheduleKind::PrivateLesson && ! $trainer) {
            throw ValidationException::withMessages([
                'trainer_id' => __('app.private_lesson_trainer_required'),
            ]);
        }

        if ($scheduleKind === ScheduleKind::PrivateLesson) {
            if ($this->trainerActivityDirectionEligibility->accountHasActiveDirections($account) && ! $activityDirectionId) {
                throw ValidationException::withMessages([
                    'activity_direction_id' => __('app.private_lesson_activity_direction_required'),
                ]);
            }

            if ($trainer && ! $this->trainerActivityDirectionEligibility->trainerCanHandle($account, $trainer, $classType, $activityDirectionId)) {
                throw ValidationException::withMessages([
                    'trainer_id' => __('app.trainer_activity_direction_mismatch'),
                ]);
            }
        }

        if (! $this->manualQuickBookingAvailability->hasStart($account, $scheduleKind, (string) $validated['starts_at'], [
            'location_id' => $location->id,
            'room_id' => $room->id,
            'class_type_id' => $classType->id,
            'trainer_id' => $trainer?->id,
            'activity_direction_id' => $activityDirectionId,
        ])) {
            throw ValidationException::withMessages([
                'starts_at' => __('app.manual_slot_unavailable'),
            ]);
        }

        return $account->scheduledClasses()->create([
            'location_id' => $location->id,
            'room_id' => $room->id,
            'class_type_id' => $classType->id,
            'trainer_id' => $trainer?->id,
            'schedule_series_id' => null,
            'title' => $classType->name,
            'description' => $classType->description,
            'starts_at' => $startsAt->timezone(config('app.timezone')),
            'ends_at' => $endsAt->timezone(config('app.timezone')),
            'capacity' => $classType->default_capacity ?? $room->capacity,
            'booking_cutoff_minutes' => $classType->booking_cutoff_minutes,
            'cancellation_cutoff_minutes' => $classType->cancellation_cutoff_minutes,
            'is_generated' => false,
            'is_manually_modified' => false,
            'metadata' => [
                'source' => 'public_booking',
                'schedule_kind' => $scheduleKind->value,
            ],
            'is_public' => (bool) ScheduleKindRegistry::get($scheduleKind)['default_is_public'],
            'status' => ScheduledClassStatus::Scheduled->value,
        ]);
    }

    private function ensureGroupCapacity(ScheduledClass $scheduledClass, int $customerId): void
    {
        $activeStatuses = [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ];
        $hasExistingActiveBooking = $scheduledClass->classBookings()
            ->notCorrectedRemoved()
            ->where('customer_id', $customerId)
            ->whereIn('status', $activeStatuses)
            ->exists();

        if ($hasExistingActiveBooking) {
            return;
        }

        $activeBookingsCount = $scheduledClass->classBookings()
            ->notCorrectedRemoved()
            ->whereIn('status', $activeStatuses)
            ->count();
        $capacity = (int) ($scheduledClass->capacity ?? 0);

        if ($capacity <= 0 || $activeBookingsCount >= $capacity) {
            throw ValidationException::withMessages([
                'scheduled_class_id' => __('app.no_available_group_slots'),
            ]);
        }
    }
}
