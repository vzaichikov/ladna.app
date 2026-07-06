<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Actions\CreatePublicBooking;
use App\Actions\ReconcileCustomerClassPassForBooking;
use App\Actions\ReserveCustomerClassPassForBooking;
use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduleKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\MobileBookingStatusRequest;
use App\Http\Requests\Api\Mobile\MobileStaffBookingRequest;
use App\Http\Resources\MobileClassBookingResource;
use App\Models\ClassBooking;
use App\Models\MobileSession;
use App\Models\ScheduledClass;
use App\Support\ActorSnapshot;
use App\Support\ClassBookingCancellationWindow;
use App\Support\Mail\TransactionalMailDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MobileBookingController extends Controller
{
    public function customerStore(Request $request, ScheduledClass $scheduledClass, CreatePublicBooking $createPublicBooking): JsonResponse
    {
        $session = $this->customerSession($request);
        $this->ensureClassBelongsToSession($session, $scheduledClass);
        $scheduledClass->loadMissing(['location', 'classType']);
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($scheduledClass->classType?->schedule_kind !== ScheduleKind::GroupClass) {
            throw ValidationException::withMessages([
                'scheduled_class_id' => __('app.quick_booking_group_class_invalid'),
            ]);
        }

        $booking = $createPublicBooking->execute($session->account, $scheduledClass->location, $session->customer, [
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'scheduled_class_id' => $scheduledClass->id,
            'notes' => $validated['notes'] ?? null,
        ]);

        return $this->bookingResponse($booking, 201);
    }

    public function staffStore(
        MobileStaffBookingRequest $request,
        ScheduledClass $scheduledClass,
        ReserveCustomerClassPassForBooking $reserveCustomerClassPassForBooking,
        ActorSnapshot $actorSnapshot,
        TransactionalMailDispatcher $mailDispatcher,
    ): JsonResponse {
        $session = $this->staffSession($request);
        $this->ensureClassBelongsToSession($session, $scheduledClass);
        abort_unless($session->account->userCan($session->user, 'manage_bookings'), 403);

        $scheduledClass->loadMissing('classType');
        $customer = $session->account->customers()->whereKey($request->validated('customer_id'))->firstOrFail();
        $this->ensureGroupCapacity($scheduledClass, $customer->id);

        $exclusiveBookingError = $this->exclusiveBookingError($scheduledClass, $customer->id);

        if ($exclusiveBookingError) {
            throw ValidationException::withMessages([
                'customer_id' => $exclusiveBookingError,
            ]);
        }

        $booking = $scheduledClass->classBookings()->updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'account_id' => $session->account_id,
                'booked_by_user_id' => $session->user_id,
                ...$actorSnapshot->prefixed($session->account, $session->user, 'booked_by_actor'),
                'status' => ClassBookingStatus::Booked->value,
                'attended_at' => null,
                'notes' => $request->validated('notes'),
            ],
        );
        $reserveCustomerClassPassForBooking->execute($booking);

        if ($booking->wasRecentlyCreated || $booking->wasChanged('status')) {
            $mailDispatcher->bookingCreated($booking);
        }

        return $this->bookingResponse($booking, 201);
    }

    public function updateStatus(
        MobileBookingStatusRequest $request,
        ClassBooking $classBooking,
        ReconcileCustomerClassPassForBooking $reconcileCustomerClassPassForBooking,
        ClassBookingCancellationWindow $cancellationWindow,
        TransactionalMailDispatcher $mailDispatcher,
    ): JsonResponse {
        $session = $this->staffSession($request);
        $this->ensureBookingBelongsToSession($session, $classBooking);

        $status = ClassBookingStatus::from($request->validated('status'));
        $canMarkAttendance = $session->account->userCan($session->user, 'mark_attendance');
        $canManageBookings = $session->account->userCan($session->user, 'manage_bookings');

        abort_unless($canMarkAttendance || $canManageBookings, 403);

        if ($status === ClassBookingStatus::Cancelled && $cancellationWindow->isLockedForBooking($classBooking)) {
            throw ValidationException::withMessages([
                'status' => __('app.booking_cancellation_cutoff_locked'),
            ]);
        }

        $previousStatus = $classBooking->status;
        $classBooking->update([
            'status' => $status->value,
            'attended_at' => $status === ClassBookingStatus::Attended ? now() : null,
            'notes' => $request->validated('notes', $classBooking->notes),
        ]);
        $reconcileCustomerClassPassForBooking->execute($classBooking);

        if ($status === ClassBookingStatus::Cancelled && $previousStatus !== ClassBookingStatus::Cancelled) {
            $mailDispatcher->bookingCancelled($classBooking);
        } elseif ($status === ClassBookingStatus::Booked && $previousStatus !== ClassBookingStatus::Booked) {
            $mailDispatcher->bookingCreated($classBooking);
        }

        return $this->bookingResponse($classBooking);
    }

    public function cancel(
        Request $request,
        ClassBooking $classBooking,
        ReconcileCustomerClassPassForBooking $reconcileCustomerClassPassForBooking,
        ClassBookingCancellationWindow $cancellationWindow,
        TransactionalMailDispatcher $mailDispatcher,
    ): JsonResponse {
        $session = $request->attributes->get('mobileSession');
        abort_unless($session instanceof MobileSession, 403);
        $this->ensureBookingBelongsToSession($session, $classBooking);

        if ($session->guard === MobileSession::GuardCustomer) {
            abort_unless($classBooking->customer_id === $session->customer_id, 404);
            $classBooking->loadMissing('scheduledClass.classType');

            if ($classBooking->status !== ClassBookingStatus::Booked || $classBooking->scheduledClass?->starts_at?->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages([
                    'booking' => __('app.customer_booking_cancel_unavailable'),
                ]);
            }
        } else {
            abort_unless($session->account->userCan($session->user, 'manage_bookings'), 403);
        }

        if ($cancellationWindow->isLockedForBooking($classBooking)) {
            throw ValidationException::withMessages([
                'booking' => __('app.booking_cancellation_cutoff_locked'),
            ]);
        }

        $classBooking->update([
            'status' => ClassBookingStatus::Cancelled->value,
            'attended_at' => null,
        ]);
        $reconcileCustomerClassPassForBooking->execute($classBooking);
        $mailDispatcher->bookingCancelled($classBooking);

        return $this->bookingResponse($classBooking);
    }

    private function bookingResponse(ClassBooking $booking, int $status = 200): JsonResponse
    {
        $booking->load([
            'customer',
            'scheduledClass.location',
            'scheduledClass.room',
            'scheduledClass.classType.activityDirection',
            'scheduledClass.trainer',
            'classPassReservation.customerClassPass',
        ]);

        return response()->json(['data' => new MobileClassBookingResource($booking)], $status);
    }

    private function exclusiveBookingError(ScheduledClass $scheduledClass, int $customerId): ?string
    {
        if (! in_array($scheduledClass->classType?->schedule_kind, [ScheduleKind::PrivateLesson, ScheduleKind::RoomRental], true)) {
            return null;
        }

        $hasAnotherActiveBooking = $scheduledClass->classBookings()
            ->notCorrectedRemoved()
            ->where('customer_id', '!=', $customerId)
            ->whereIn('status', [
                ClassBookingStatus::Booked->value,
                ClassBookingStatus::Attended->value,
            ])
            ->exists();

        return $hasAnotherActiveBooking ? __('app.manual_class_already_booked') : null;
    }

    private function ensureGroupCapacity(ScheduledClass $scheduledClass, int $customerId): void
    {
        if ($scheduledClass->classType?->schedule_kind !== ScheduleKind::GroupClass) {
            return;
        }

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

    private function ensureClassBelongsToSession(MobileSession $session, ScheduledClass $scheduledClass): void
    {
        abort_unless($scheduledClass->account_id === $session->account_id, 404);

        if ($session->guard === MobileSession::GuardStaff && $session->role === AccountRole::Trainer->value) {
            $trainerId = $session->account->trainers()->where('user_id', $session->user_id)->value('id');

            abort_unless($trainerId && $scheduledClass->trainer_id === $trainerId, 404);
        }
    }

    private function ensureBookingBelongsToSession(MobileSession $session, ClassBooking $classBooking): void
    {
        abort_unless($classBooking->account_id === $session->account_id, 404);

        if ($session->guard === MobileSession::GuardStaff && $session->role === AccountRole::Trainer->value) {
            $classBooking->loadMissing('scheduledClass');
            $trainerId = $session->account->trainers()->where('user_id', $session->user_id)->value('id');

            abort_unless($trainerId && $classBooking->scheduledClass?->trainer_id === $trainerId, 404);
        }
    }

    private function customerSession(Request $request): MobileSession
    {
        $session = $request->attributes->get('mobileSession');

        abort_unless($session instanceof MobileSession && $session->guard === MobileSession::GuardCustomer, 403);

        return $session;
    }

    private function staffSession(Request $request): MobileSession
    {
        $session = $request->attributes->get('mobileSession');

        abort_unless($session instanceof MobileSession && $session->guard === MobileSession::GuardStaff, 403);

        return $session;
    }
}
