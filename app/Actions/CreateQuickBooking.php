<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use App\Models\User;
use App\Models\WebsiteLead;
use App\Support\ActorSnapshot;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\ManualQuickBookingAvailability;
use App\Support\Payments\PaymentAmounts;
use App\Support\ScheduleKindRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateQuickBooking
{
    public function __construct(
        private readonly ResolveQuickBookingCustomer $resolveQuickBookingCustomer,
        private readonly ReserveCustomerClassPassForBooking $reserveCustomerClassPassForBooking,
        private readonly ManualQuickBookingAvailability $manualQuickBookingAvailability,
        private readonly ActorSnapshot $actorSnapshot,
        private readonly TransactionalMailDispatcher $mailDispatcher,
        private readonly RecordManualClassBookingPayment $recordManualClassBookingPayment,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(Account $account, User $user, array $validated): ClassBooking
    {
        $classBooking = DB::transaction(function () use ($account, $user, $validated): ClassBooking {
            $customer = $this->resolveQuickBookingCustomer->execute($account, $validated);
            $scheduleKind = ScheduleKind::from((string) $validated['schedule_kind']);
            $scheduledClass = $scheduleKind === ScheduleKind::GroupClass
                ? $this->groupScheduledClass($account, (int) $validated['scheduled_class_id'], $customer->id)
                : $this->createManualScheduledClass($account, $scheduleKind, $validated);
            $skipClassPassReservation = $this->shouldSkipClassPassReservation($scheduleKind, $validated);

            $classBooking = $scheduledClass->classBookings()->updateOrCreate(
                ['customer_id' => $customer->id],
                [
                    'account_id' => $account->id,
                    'booked_by_user_id' => $user->id,
                    ...$this->actorSnapshot->prefixed($account, $user, 'booked_by_actor'),
                    'status' => ClassBookingStatus::Booked->value,
                    'attended_at' => null,
                    'notes' => $validated['notes'] ?? null,
                    'skip_class_pass_reservation' => $skipClassPassReservation,
                ],
            );

            if (! $skipClassPassReservation) {
                $this->reserveCustomerClassPassForBooking->execute($classBooking);
            }

            $this->recordManualPaymentIfNeeded($account, $scheduleKind, $classBooking, $validated);

            $this->markLeadBooked($account, $validated, $customer->id, $classBooking->id);

            return $classBooking;
        });

        $this->mailDispatcher->bookingCreated($classBooking);

        return $classBooking;
    }

    private function groupScheduledClass(Account $account, int $scheduledClassId, int $customerId): ScheduledClass
    {
        $scheduledClass = $account->scheduledClasses()
            ->with('classType')
            ->whereKey($scheduledClassId)
            ->firstOrFail();

        abort_unless($scheduledClass->classType?->schedule_kind === ScheduleKind::GroupClass, 404);

        $this->ensureGroupCapacity($scheduledClass, $customerId);

        return $scheduledClass;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createManualScheduledClass(Account $account, ScheduleKind $scheduleKind, array $validated): ScheduledClass
    {
        if (! in_array($scheduleKind, ScheduleKindRegistry::manualKinds(), true)) {
            throw ValidationException::withMessages([
                'schedule_kind' => __('app.manual_class_format_invalid'),
            ]);
        }

        $location = $account->locations()->whereKey((int) $validated['location_id'])->firstOrFail();
        $room = $account->rooms()->whereKey((int) $validated['room_id'])->firstOrFail();
        $classType = $account->classTypes()
            ->whereKey((int) $validated['class_type_id'])
            ->where('schedule_kind', $scheduleKind->value)
            ->firstOrFail();
        $trainer = filled($validated['trainer_id'] ?? null)
            ? $account->trainers()->whereKey((int) $validated['trainer_id'])->firstOrFail()
            : null;
        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $validated['starts_at'], $timezone);
        $isAnytimeRental = $this->shouldSkipClassPassReservation($scheduleKind, $validated);
        $endsAt = $isAnytimeRental
            ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $validated['ends_at'], $timezone)
            : $startsAt->addMinutes((int) ($classType->default_duration_minutes ?: 60));
        $isAvailable = $isAnytimeRental
            ? $this->manualQuickBookingAvailability->hasRange($account, $scheduleKind, (string) $validated['starts_at'], (string) $validated['ends_at'], [
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer?->id,
                'allow_past' => true,
            ])
            : $this->manualQuickBookingAvailability->hasStart($account, $scheduleKind, (string) $validated['starts_at'], [
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer?->id,
                'allow_past' => $scheduleKind === ScheduleKind::RoomRental,
            ]);

        if (! $isAvailable) {
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
                'source' => 'quick_booking',
                'schedule_kind' => $scheduleKind->value,
                'rental_mode' => $isAnytimeRental ? 'anytime' : null,
                'skip_class_pass_reservation' => $isAnytimeRental,
            ],
            'is_public' => (bool) ScheduleKindRegistry::get($scheduleKind)['default_is_public'],
            'status' => ScheduledClassStatus::Scheduled->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function shouldSkipClassPassReservation(ScheduleKind $scheduleKind, array $validated): bool
    {
        return $scheduleKind === ScheduleKind::RoomRental
            && ($validated['rental_mode'] ?? 'preset') === 'anytime';
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function recordManualPaymentIfNeeded(Account $account, ScheduleKind $scheduleKind, ClassBooking $classBooking, array $validated): void
    {
        if ($scheduleKind !== ScheduleKind::RoomRental || blank($validated['payment_amount'] ?? null)) {
            return;
        }

        $amountCents = PaymentAmounts::decimalToCents($validated['payment_amount']);

        if ($amountCents === null || $amountCents <= 0) {
            return;
        }

        $this->recordManualClassBookingPayment->execute($account, $classBooking, $amountCents);
    }

    private function ensureGroupCapacity(ScheduledClass $scheduledClass, int $customerId): void
    {
        $activeStatuses = [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ];
        $hasExistingActiveBooking = $scheduledClass->classBookings()
            ->where('customer_id', $customerId)
            ->whereIn('status', $activeStatuses)
            ->exists();

        if ($hasExistingActiveBooking) {
            return;
        }

        $activeBookingsCount = $scheduledClass->classBookings()
            ->whereIn('status', $activeStatuses)
            ->count();
        $capacity = (int) ($scheduledClass->capacity ?? 0);

        if ($capacity <= 0 || $activeBookingsCount >= $capacity) {
            throw ValidationException::withMessages([
                'scheduled_class_id' => __('app.no_available_group_slots'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function markLeadBooked(Account $account, array $validated, int $customerId, int $classBookingId): void
    {
        $websiteLeadId = (int) ($validated['website_lead_id'] ?? 0);

        if ($websiteLeadId <= 0) {
            return;
        }

        WebsiteLead::whereBelongsTo($account)
            ->whereKey($websiteLeadId)
            ->update([
                'customer_id' => $customerId,
                'class_booking_id' => $classBookingId,
                'status' => 'booked',
                'converted_at' => now(),
            ]);
    }
}
