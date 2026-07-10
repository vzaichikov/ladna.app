<?php

namespace App\Http\Controllers;

use App\Actions\NormalizeCustomerClassPasses;
use App\Actions\ReconcileCustomerClassPassForBooking;
use App\Actions\ReserveCustomerClassPassForBooking;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduleKind;
use App\Http\Requests\StoreClassBookingRequest;
use App\Http\Requests\UpdateClassBookingStatusRequest;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use App\Support\ActorSnapshot;
use App\Support\ClassBookingCancellationWindow;
use App\Support\CustomerNotifications\ClassBookingNotificationCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ClassBookingController extends Controller
{
    public function store(StoreClassBookingRequest $request, Account $account, ScheduledClass $scheduledClass, ReserveCustomerClassPassForBooking $reserveCustomerClassPassForBooking, ActorSnapshot $actorSnapshot, ClassBookingNotificationCoordinator $notifications): RedirectResponse|JsonResponse
    {
        $this->ensureClassBelongsToAccount($account, $scheduledClass);
        $scheduledClass->loadMissing('classType');

        $customer = $account->customers()->whereKey($request->validated('customer_id'))->firstOrFail();
        $exclusiveBookingError = $this->exclusiveBookingError($scheduledClass, $customer->id);

        if ($exclusiveBookingError) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exclusiveBookingError,
                    'errors' => [
                        'customer_id' => [$exclusiveBookingError],
                    ],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return back()->withErrors(['customer_id' => $exclusiveBookingError])->withInput();
        }

        $classBooking = $scheduledClass->classBookings()->updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'account_id' => $account->id,
                'booked_by_user_id' => $request->user()->id,
                ...$actorSnapshot->prefixed($account, $request->user(), 'booked_by_actor'),
                'status' => ClassBookingStatus::Booked->value,
                'attended_at' => null,
                'notes' => $request->validated('notes'),
            ],
        );
        $shouldNotifyCustomer = $classBooking->wasRecentlyCreated || $classBooking->wasChanged('status');
        $reserveCustomerClassPassForBooking->execute($classBooking);

        if ($shouldNotifyCustomer) {
            $notifications->bookingCreated($classBooking);
        }

        if ($request->expectsJson()) {
            return $this->bookingJsonResponse($account, $scheduledClass, __('app.booking_created'), Response::HTTP_CREATED);
        }

        return redirect()->route('dashboard.accounts.scheduled-classes.index', $account)
            ->with('status', __('app.booking_created'));
    }

    public function update(UpdateClassBookingStatusRequest $request, Account $account, ClassBooking $classBooking, ReconcileCustomerClassPassForBooking $reconcileCustomerClassPassForBooking, ClassBookingCancellationWindow $cancellationWindow, ClassBookingNotificationCoordinator $notifications): RedirectResponse|JsonResponse
    {
        $this->ensureBookingBelongsToAccount($account, $classBooking);

        $status = ClassBookingStatus::from($request->validated('status'));
        $previousStatus = $classBooking->status;

        if ($status === ClassBookingStatus::Cancelled && $cancellationWindow->isLockedForBooking($classBooking)) {
            return $this->bookingBlockedResponse($request, __('app.booking_cancellation_cutoff_locked'), 'status');
        }

        $classBooking->update([
            'status' => $status->value,
            'attended_at' => $status === ClassBookingStatus::Attended ? now() : null,
            'notes' => $request->validated('notes', $classBooking->notes),
        ]);
        $reconcileCustomerClassPassForBooking->execute($classBooking);

        if ($status === ClassBookingStatus::Cancelled && $previousStatus !== ClassBookingStatus::Cancelled) {
            $notifications->bookingCancelled($classBooking);
        } elseif ($status === ClassBookingStatus::Booked && $previousStatus !== ClassBookingStatus::Booked) {
            $notifications->bookingCreated($classBooking);
        } elseif ($status === ClassBookingStatus::Attended && $previousStatus !== ClassBookingStatus::Attended) {
            $notifications->bookingUpdatedToActive($classBooking);
        } elseif (in_array($previousStatus, [ClassBookingStatus::Booked, ClassBookingStatus::Attended], true) && ! in_array($status, [ClassBookingStatus::Booked, ClassBookingStatus::Attended], true)) {
            $notifications->bookingNoLongerActive($classBooking, 'booking_status_'.$status->value);
        }

        if ($request->expectsJson()) {
            return $this->bookingJsonResponse($account, $classBooking->scheduledClass, __('app.booking_updated'));
        }

        return redirect()->route('dashboard.accounts.scheduled-classes.index', $account)
            ->with('status', __('app.booking_updated'));
    }

    public function destroy(Request $request, Account $account, ClassBooking $classBooking, NormalizeCustomerClassPasses $normalizeCustomerClassPasses, ClassBookingCancellationWindow $cancellationWindow, ClassBookingNotificationCoordinator $notifications): RedirectResponse|JsonResponse
    {
        $this->ensureBookingBelongsToAccount($account, $classBooking);
        $bookingCancellationLocked = $cancellationWindow->isLockedForBooking($classBooking);

        $this->authorize($bookingCancellationLocked ? 'correctClosedClasses' : 'manageBookings', $account);

        $scheduledClass = $classBooking->scheduledClass;
        $classBooking->loadMissing('classPassReservation.customerClassPass');
        $customerClassPass = $classBooking->classPassReservation?->customerClassPass;
        $classBooking->delete();
        $notifications->bookingCancelled($classBooking);

        if ($customerClassPass) {
            $normalizeCustomerClassPasses->forPass($customerClassPass);
        }

        if ($request->expectsJson()) {
            return $this->bookingJsonResponse($account, $scheduledClass, __('app.booking_deleted'));
        }

        return redirect()->route('dashboard.accounts.scheduled-classes.index', $account)
            ->with('status', __('app.booking_deleted'));
    }

    private function ensureClassBelongsToAccount(Account $account, ScheduledClass $scheduledClass): void
    {
        abort_unless($scheduledClass->account_id === $account->id, 404);
    }

    private function ensureBookingBelongsToAccount(Account $account, ClassBooking $classBooking): void
    {
        abort_unless($classBooking->account_id === $account->id, 404);
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

    private function bookingJsonResponse(Account $account, ScheduledClass $scheduledClass, string $message, int $status = Response::HTTP_OK): JsonResponse
    {
        $scheduledClass->load([
            'location',
            'room',
            'classType.activityDirection',
            'trainer',
            'scheduleSeries',
            'activeCancellation.effects',
            'classBookings' => fn ($query) => $query
                ->notCorrectedRemoved()
                ->with(['customer', 'manualCashPayment', 'classPassReservation.customerClassPass.classPassPlan']),
        ]);

        return response()->json([
            'message' => $message,
            'scheduled_class_id' => $scheduledClass->id,
            'card_html' => view('scheduled-classes._card', [
                'account' => $account,
                'scheduledClass' => $scheduledClass,
                'customerSearchUrl' => route('dashboard.accounts.customers.search', $account),
                'bookingStatuses' => ClassBookingStatus::cases(),
            ])->render(),
        ], $status);
    }

    private function bookingBlockedResponse(Request $request, string $message, string $field): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => [
                    $field => [$message],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return back()->withErrors([$field => $message])->withInput();
    }
}
