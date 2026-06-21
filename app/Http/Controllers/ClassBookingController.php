<?php

namespace App\Http\Controllers;

use App\Actions\NormalizeCustomerClassPasses;
use App\Actions\ReconcileCustomerClassPassForBooking;
use App\Actions\ReserveCustomerClassPassForBooking;
use App\Enums\ClassBookingStatus;
use App\Http\Requests\StoreClassBookingRequest;
use App\Http\Requests\UpdateClassBookingStatusRequest;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ClassBookingController extends Controller
{
    public function store(StoreClassBookingRequest $request, Account $account, ScheduledClass $scheduledClass, ReserveCustomerClassPassForBooking $reserveCustomerClassPassForBooking): RedirectResponse|JsonResponse
    {
        $this->ensureClassBelongsToAccount($account, $scheduledClass);

        $customer = $account->customers()->whereKey($request->validated('customer_id'))->firstOrFail();

        $classBooking = $scheduledClass->classBookings()->updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'account_id' => $account->id,
                'booked_by_user_id' => $request->user()->id,
                'status' => ClassBookingStatus::Booked->value,
                'attended_at' => null,
                'notes' => $request->validated('notes'),
            ],
        );
        $reserveCustomerClassPassForBooking->execute($classBooking);

        if ($request->expectsJson()) {
            return $this->bookingJsonResponse($account, $scheduledClass, __('app.booking_created'), Response::HTTP_CREATED);
        }

        return redirect()->route('dashboard.accounts.scheduled-classes.index', $account)
            ->with('status', __('app.booking_created'));
    }

    public function update(UpdateClassBookingStatusRequest $request, Account $account, ClassBooking $classBooking, ReconcileCustomerClassPassForBooking $reconcileCustomerClassPassForBooking): RedirectResponse|JsonResponse
    {
        $this->ensureBookingBelongsToAccount($account, $classBooking);

        $status = ClassBookingStatus::from($request->validated('status'));
        $classBooking->update([
            'status' => $status->value,
            'attended_at' => $status === ClassBookingStatus::Attended ? now() : null,
            'notes' => $request->validated('notes', $classBooking->notes),
        ]);
        $reconcileCustomerClassPassForBooking->execute($classBooking);

        if ($request->expectsJson()) {
            return $this->bookingJsonResponse($account, $classBooking->scheduledClass, __('app.booking_updated'));
        }

        return redirect()->route('dashboard.accounts.scheduled-classes.index', $account)
            ->with('status', __('app.booking_updated'));
    }

    public function destroy(Request $request, Account $account, ClassBooking $classBooking, NormalizeCustomerClassPasses $normalizeCustomerClassPasses): RedirectResponse|JsonResponse
    {
        $this->authorize('manageBookings', $account);
        $this->ensureBookingBelongsToAccount($account, $classBooking);

        $scheduledClass = $classBooking->scheduledClass;
        $classBooking->loadMissing('classPassReservation.customerClassPass');
        $customerClassPass = $classBooking->classPassReservation?->customerClassPass;
        $classBooking->delete();

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

    private function bookingJsonResponse(Account $account, ScheduledClass $scheduledClass, string $message, int $status = Response::HTTP_OK): JsonResponse
    {
        $scheduledClass->load([
            'location',
            'room',
            'classType.activityDirection',
            'trainer',
            'scheduleSeries',
            'classBookings.customer',
            'classBookings.classPassReservation.customerClassPass.classPassPlan',
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
}
