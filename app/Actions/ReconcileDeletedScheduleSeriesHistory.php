<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\AccountActivityLog;
use App\Models\ClassBooking;
use App\Models\ClassBookingCorrection;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Support\ScheduleOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReconcileDeletedScheduleSeriesHistory
{
    public function __construct(
        private readonly NormalizeCustomerClassPasses $normalizeCustomerClassPasses,
        private readonly ScheduleOccupancy $scheduleOccupancy,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function execute(array $manifest, bool $apply = false): array
    {
        $this->validateManifest($manifest);
        $account = Account::query()->findOrFail($manifest['account_id']);

        return DB::transaction(function () use ($manifest, $account, $apply): array {
            if ($apply) {
                $this->scheduleOccupancy->lockAccount($account);
            }

            $report = ['account_id' => $account->id, 'account_name' => $account->name, 'source_backup' => $manifest['source_backup'], 'applied' => $apply, 'unsupported_dates' => $manifest['unsupported_dates'] ?? [], 'classes' => []];
            $additionalUsage = [];
            $passes = [];

            $seenOccurrences = [];
            foreach ($manifest['classes'] as $source) {
                $key = implode(':', [$source['attributes']['location_id'], $source['attributes']['room_id'], $source['attributes']['class_type_id'], CarbonImmutable::parse($source['attributes']['starts_at'])->utc()->toDateTimeString()]);
                if (isset($seenOccurrences[$key])) {
                    $report['classes'][] = ['original_class_id' => $source['original_class_id'], 'starts_at' => $source['attributes']['starts_at'], 'scheduled_class_id' => null, 'status' => 'conflicting', 'reason' => 'Duplicate source occurrence.', 'bookings' => []];

                    continue;
                }
                $seenOccurrences[$key] = true;
                $report['classes'][] = $this->inspectClass($account, $manifest, $source, $additionalUsage, $passes, $apply);
            }

            if (! $apply) {
                return $report;
            }

            foreach ($report['classes'] as $entry) {
                if ($entry['status'] === 'conflicting' || collect($entry['bookings'])->contains('status', 'conflicting')) {
                    throw ValidationException::withMessages(['manifest' => 'Resolve conflicting historical records before applying this repair.']);
                }
            }

            $affectedPasses = array_filter($passes);
            foreach ($report['classes'] as $index => &$entry) {
                if ($entry['status'] === 'unsupported') {
                    continue;
                }

                $source = $manifest['classes'][$index];
                $classChanged = $entry['scheduled_class_id'] === null;
                $scheduledClass = $entry['scheduled_class_id']
                    ? $account->scheduledClasses()->findOrFail($entry['scheduled_class_id'])
                    : $account->scheduledClasses()->create([
                        ...Arr::only($source['attributes'], ['location_id', 'room_id', 'class_type_id', 'trainer_id', 'title', 'description', 'starts_at', 'ends_at', 'capacity', 'booking_cutoff_minutes', 'cancellation_cutoff_minutes', 'is_public', 'status']),
                        'schedule_series_id' => null,
                        'is_generated' => false,
                        'is_manually_modified' => true,
                        'metadata' => ['source' => 'historical_schedule_reconciliation', 'original_series_id' => $manifest['original_series_id'], 'original_class_id' => $source['original_class_id'], 'source_backup' => $manifest['source_backup']],
                    ]);
                $entry['scheduled_class_id'] = $scheduledClass->id;

                foreach ($entry['bookings'] as $bookingIndex => &$bookingEntry) {
                    if ($bookingEntry['status'] === 'already_repaired') {
                        continue;
                    }
                    $classChanged = true;
                    $bookingSource = $source['bookings'][$bookingIndex];
                    $booking = $bookingEntry['class_booking_id']
                        ? $scheduledClass->classBookings()->findOrFail($bookingEntry['class_booking_id'])
                        : $scheduledClass->classBookings()->create([
                            'account_id' => $account->id,
                            ...Arr::only($bookingSource['attributes'], ['customer_id', 'status', 'attended_at', 'notes', 'skip_class_pass_reservation', 'corrected_removed_at']),
                        ]);
                    $reservationSource = $bookingSource['reservation'];
                    $reservation = null;
                    if ($reservationSource) {
                        $reservation = $booking->classPassReservation()->create([
                            'account_id' => $account->id,
                            'scheduled_class_id' => $scheduledClass->id,
                            ...Arr::only($reservationSource, ['customer_class_pass_id', 'status', 'reserved_at', 'used_at', 'released_at']),
                        ]);
                        $affectedPasses[$reservation->customer_class_pass_id] = $passes[$reservation->customer_class_pass_id];
                    }
                    ClassBookingCorrection::query()->create([
                        'account_id' => $account->id,
                        'scheduled_class_id' => $scheduledClass->id,
                        'class_booking_id' => $booking->id,
                        'new_customer_id' => $booking->customer_id,
                        'new_customer_name' => $booking->customer->name,
                        'new_customer_class_pass_id' => $reservation?->customer_class_pass_id,
                        'customer_class_pass_reservation_id' => $reservation?->id,
                        'action' => ClassBookingCorrection::ActionAdded,
                        'pass_effect' => $reservation ? ClassBookingCorrection::PassEffectAutoMatched : ClassBookingCorrection::PassEffectNoMatchingPass,
                        'new_booking_status' => $booking->status->value,
                        'new_reservation_status' => $reservation?->status->value,
                        'new_reserved_at' => $reservation?->reserved_at,
                        'new_used_at' => $reservation?->used_at,
                        'new_released_at' => $reservation?->released_at,
                        'actor_name' => 'Historical schedule reconciliation',
                        'reason' => "Restore original series #{$manifest['original_series_id']}, class #{$source['original_class_id']}, booking #{$bookingSource['original_booking_id']} from {$manifest['source_backup']}; original pass preserved.",
                    ]);
                    $bookingEntry['class_booking_id'] = $booking->id;
                    $bookingEntry['status'] = 'repaired';
                }
                unset($bookingEntry);
                $entry['status'] = $classChanged ? 'repaired' : 'already_repaired';
            }
            unset($entry);

            foreach ($affectedPasses as $pass) {
                $this->normalizeCustomerClassPasses->forPass($pass, reconcileReservations: false);
            }

            return $report;
        });
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $source
     * @param  array<int, int>  $additionalUsage
     * @param  array<int, CustomerClassPass>  $passes
     * @return array<string, mixed>
     */
    private function inspectClass(Account $account, array $manifest, array $source, array &$additionalUsage, array &$passes, bool $lock): array
    {
        $attributes = $source['attributes'];
        $entry = ['original_class_id' => $source['original_class_id'], 'starts_at' => $attributes['starts_at'], 'scheduled_class_id' => null, 'status' => 'repairable', 'reason' => null, 'bookings' => []];
        if (CarbonImmutable::parse($attributes['starts_at'])->greaterThanOrEqualTo(CarbonImmutable::parse($manifest['snapshot_at'])) || CarbonImmutable::parse($attributes['ends_at'])->isFuture()) {
            return [...$entry, 'status' => 'unsupported', 'reason' => 'The source snapshot does not establish the historical outcome of this class.'];
        }
        if ($this->hasLaterCorrection($account, ScheduledClass::class, $source['original_class_id'], $manifest['snapshot_at'])) {
            return [...$entry, 'status' => 'conflicting', 'reason' => 'A later class correction requires review before restoring the older snapshot.'];
        }
        foreach (['location_id' => Location::class, 'room_id' => Room::class, 'class_type_id' => ClassType::class, 'trainer_id' => Trainer::class] as $field => $model) {
            if (($attributes[$field] ?? null) && ! $model::query()->whereBelongsTo($account)->whereKey($attributes[$field])->exists()) {
                return [...$entry, 'status' => 'conflicting', 'reason' => "Missing or foreign {$field}."];
            }
        }
        $matches = $account->scheduledClasses()->where(Arr::only($attributes, ['location_id', 'room_id', 'class_type_id', 'starts_at']))->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($matches->count() > 1) {
            return [...$entry, 'status' => 'conflicting', 'reason' => 'Multiple current classes match the source occurrence.'];
        }
        $scheduledClass = $matches->first();
        if ($scheduledClass && ($scheduledClass->status->value !== $attributes['status'] || $scheduledClass->trainer_id !== ($attributes['trainer_id'] ?? null))) {
            return [...$entry, 'status' => 'conflicting', 'reason' => 'Current class has a different trainer or cancellation state.'];
        }
        $entry['scheduled_class_id'] = $scheduledClass?->id;
        $entry['status'] = $scheduledClass ? 'already_repaired' : 'repairable';
        $seenCustomers = [];
        foreach ($source['bookings'] as $bookingSource) {
            $customerId = $bookingSource['attributes']['customer_id'];
            if (isset($seenCustomers[$customerId])) {
                $entry['bookings'][] = ['original_booking_id' => $bookingSource['original_booking_id'], 'customer_id' => $customerId, 'status' => 'conflicting', 'reason' => 'Duplicate source customer in one class.'];

                continue;
            }
            $seenCustomers[$customerId] = true;
            $bookingEntry = $this->inspectBooking($account, $scheduledClass, $bookingSource, $additionalUsage, $passes, $lock, $manifest['snapshot_at']);
            $entry['bookings'][] = $bookingEntry;
            if ($bookingEntry['status'] === 'repairable') {
                $entry['status'] = 'repairable';
            }
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<int, int>  $additionalUsage
     * @param  array<int, CustomerClassPass>  $passes
     * @return array<string, mixed>
     */
    private function inspectBooking(Account $account, ?ScheduledClass $scheduledClass, array $source, array &$additionalUsage, array &$passes, bool $lock, string $snapshotAt): array
    {
        $attributes = $source['attributes'];
        $reservationSource = $source['reservation'];
        $entry = ['original_booking_id' => $source['original_booking_id'], 'original_reservation_id' => $source['original_reservation_id'] ?? null, 'customer_id' => $attributes['customer_id'], 'customer_class_pass_id' => $reservationSource['customer_class_pass_id'] ?? null, 'class_booking_id' => null, 'status' => 'repairable', 'reason' => null];
        if ($this->hasLaterCorrection($account, ClassBooking::class, $source['original_booking_id'], $snapshotAt)) {
            return [...$entry, 'status' => 'conflicting', 'reason' => 'A later booking correction requires review before restoring the older snapshot.'];
        }
        if (! Customer::query()->whereBelongsTo($account)->whereKey($attributes['customer_id'])->exists()) {
            return [...$entry, 'status' => 'conflicting', 'reason' => 'Missing or foreign customer.'];
        }
        $pass = null;
        if ($reservationSource) {
            $passId = $reservationSource['customer_class_pass_id'];
            $pass = $passes[$passId] ??= CustomerClassPass::query()->whereBelongsTo($account)->where('customer_id', $attributes['customer_id'])->whereKey($passId)->when($lock, fn ($query) => $query->lockForUpdate())->first();
            if (! $pass || $pass->customer_id !== $attributes['customer_id']) {
                return [...$entry, 'status' => 'conflicting', 'reason' => 'Original pass is missing or belongs to another customer.'];
            }
            if ($reservationSource['status'] === CustomerClassPassReservationStatus::Reserved->value) {
                return [...$entry, 'status' => 'conflicting', 'reason' => 'Historical reservation was not settled in the source snapshot.'];
            }
            if ($reservationSource['status'] === CustomerClassPassReservationStatus::Used->value && (empty($reservationSource['used_at']) || ! empty($attributes['skip_class_pass_reservation']))) {
                return [...$entry, 'status' => 'conflicting', 'reason' => 'Source usage conflicts with the booking state.'];
            }
        }
        $booking = $scheduledClass?->classBookings()->where('customer_id', $attributes['customer_id'])->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $entry['class_booking_id'] = $booking?->id;
        $reservation = $booking?->classPassReservation()->first();
        if ($booking && ($booking->status->value !== $attributes['status'] || (bool) $booking->skip_class_pass_reservation !== (bool) ($attributes['skip_class_pass_reservation'] ?? false) || (bool) $booking->corrected_removed_at !== (bool) ($attributes['corrected_removed_at'] ?? null))) {
            return [...$entry, 'status' => 'conflicting', 'reason' => 'Current booking has a different attendance or correction state.'];
        }
        if ($booking) {
            $differences = [];
            foreach (['attended_at', 'corrected_removed_at', 'reserved_at', 'used_at', 'released_at'] as $field) {
                $current = in_array($field, ['attended_at', 'corrected_removed_at'], true) ? $booking->{$field} : $reservation?->{$field};
                $original = in_array($field, ['attended_at', 'corrected_removed_at'], true) ? ($attributes[$field] ?? null) : ($reservationSource[$field] ?? null);
                if (! in_array($field, ['attended_at', 'corrected_removed_at'], true) && ! $reservation) {
                    continue;
                }
                $currentValue = $current?->copy()->utc()->toDateTimeString();
                $originalValue = $original ? CarbonImmutable::parse($original)->utc()->toDateTimeString() : null;
                if ($currentValue !== $originalValue) {
                    $differences[$field] = ['current' => $currentValue, 'source' => $originalValue];
                }
            }
            if ($differences) {
                $originalId = preg_quote((string) $source['original_booking_id'], '/');
                $hasRecoveryProvenance = ClassBookingCorrection::query()->whereBelongsTo($account)->where('class_booking_id', $booking->id)
                    ->where('action', ClassBookingCorrection::ActionAdded)->where('new_customer_class_pass_id', $reservationSource['customer_class_pass_id'] ?? null)
                    ->pluck('reason')->contains(fn (?string $reason): bool => preg_match('/(?:запис\s*№|booking\s*#)\s*'.$originalId.'(?:\D|$)/u', $reason ?? '') === 1);
                if (! $reservation || ! $hasRecoveryProvenance || isset($differences['corrected_removed_at'])) {
                    return [...$entry, 'status' => 'conflicting', 'reason' => 'Unexplained historical timestamp differences.', 'timestamp_differences' => $differences];
                }
                $entry['timestamp_differences'] = $differences;
                $entry['reason'] = 'Existing documented recovery timestamps retained; no additional debit.';
            }
        }
        if ($reservation) {
            if (! $reservationSource || $reservation->customer_class_pass_id !== $reservationSource['customer_class_pass_id'] || $reservation->status->value !== $reservationSource['status']) {
                return [...$entry, 'status' => 'conflicting', 'reason' => 'Current booking has a different pass or reservation state.'];
            }

            return [...$entry, 'status' => 'already_repaired'];
        }
        if (! $reservationSource) {
            return [...$entry, 'status' => $booking ? 'already_repaired' : 'repairable'];
        }
        if ($reservationSource['status'] === CustomerClassPassReservationStatus::Used->value) {
            $additionalUsage[$pass->id] = ($additionalUsage[$pass->id] ?? 0) + 1;
            $committed = $pass->reservations()->whereIn('status', ['used', 'reserved'])->count();
            if ($committed + $additionalUsage[$pass->id] > $pass->sessions_count) {
                return [...$entry, 'status' => 'conflicting', 'reason' => 'Restoring original usage would overdraw this pass.'];
            }
        }
        if (! $pass->is_active && $pass->reservations()->where('status', 'reserved')->exists()) {
            return [...$entry, 'status' => 'conflicting', 'reason' => 'Inactive original pass still has reservations requiring review.'];
        }

        return $entry;
    }

    private function hasLaterCorrection(Account $account, string $subjectType, int $subjectId, string $snapshotAt): bool
    {
        return AccountActivityLog::query()->whereBelongsTo($account)
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->where('occurred_at', '>', $snapshotAt)
            ->where(function ($query): void {
                foreach (['%cancel%', '%restore%', '%correction%', '%update%', '%destroy%'] as $action) {
                    $query->orWhere('action', 'like', $action);
                }
            })->exists();
    }

    /** @param array<string, mixed> $manifest */
    private function validateManifest(array $manifest): void
    {
        validator($manifest, [
            'account_id' => ['required', 'integer'],
            'original_series_id' => ['required', 'integer'],
            'source_backup' => ['required', 'string', 'max:255'],
            'snapshot_at' => ['required', 'date', 'before:now'],
            'classes' => ['required', 'array'],
            'classes.*.original_class_id' => ['required', 'integer', 'distinct'],
            'classes.*.attributes' => ['required', 'array'],
            'classes.*.attributes.location_id' => ['required', 'integer'],
            'classes.*.attributes.room_id' => ['required', 'integer'],
            'classes.*.attributes.class_type_id' => ['required', 'integer'],
            'classes.*.attributes.trainer_id' => ['nullable', 'integer'],
            'classes.*.attributes.title' => ['required', 'string', 'max:255'],
            'classes.*.attributes.starts_at' => ['required', 'date'],
            'classes.*.attributes.ends_at' => ['required', 'date'],
            'classes.*.attributes.status' => ['required', Rule::enum(ScheduledClassStatus::class)],
            'classes.*.bookings' => ['present', 'array'],
            'classes.*.bookings.*.original_booking_id' => ['required', 'integer', 'distinct'],
            'classes.*.bookings.*.attributes.customer_id' => ['required', 'integer'],
            'classes.*.bookings.*.attributes.status' => ['required', Rule::enum(ClassBookingStatus::class)],
            'classes.*.bookings.*.attributes.attended_at' => ['nullable', 'date'],
            'classes.*.bookings.*.attributes.corrected_removed_at' => ['nullable', 'date'],
            'classes.*.bookings.*.attributes.skip_class_pass_reservation' => ['sometimes', 'boolean'],
            'classes.*.bookings.*.reservation' => ['present', 'nullable', 'array'],
            'classes.*.bookings.*.reservation.customer_class_pass_id' => ['required_with:classes.*.bookings.*.reservation', 'integer'],
            'classes.*.bookings.*.reservation.status' => ['required_with:classes.*.bookings.*.reservation', Rule::enum(CustomerClassPassReservationStatus::class)],
            'classes.*.bookings.*.reservation.reserved_at' => ['nullable', 'date'],
            'classes.*.bookings.*.reservation.used_at' => ['nullable', 'date'],
            'classes.*.bookings.*.reservation.released_at' => ['nullable', 'date'],
        ])->validate();
    }
}
