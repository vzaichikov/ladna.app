<?php

namespace App\Http\Controllers;

use App\Actions\AddClosedClassBookingCorrection;
use App\Actions\RemoveClosedClassBookingCorrection;
use App\Enums\ClassBookingStatus;
use App\Http\Requests\RemoveClosedClassBookingRequest;
use App\Http\Requests\StoreClosedClassBookingCorrectionRequest;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Models\ScheduledClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ClosedClassBookingCorrectionController extends Controller
{
    public function preview(Request $request, Account $account, ScheduledClass $scheduledClass, AddClosedClassBookingCorrection $correction): JsonResponse
    {
        $this->authorize('correctClosedClasses', $account);
        $this->ensureClassBelongsToAccount($account, $scheduledClass);
        $scheduledClass->loadMissing('classType');

        abort_unless($scheduledClass->acceptsCustomerBookings(), Response::HTTP_UNPROCESSABLE_ENTITY, __('app.class_does_not_accept_customer_bookings'));

        $validated = $request->validate([
            'customer_id' => [
                'required',
                'integer',
                Rule::exists((new Customer)->getTable(), 'id')->where('account_id', $account->id),
            ],
        ]);
        $customer = $account->customers()->whereKey($validated['customer_id'])->firstOrFail();
        $matchingPass = $correction->matchingPass($account, $scheduledClass, $customer);

        return response()->json([
            'message' => $matchingPass
                ? __('app.closed_class_correction_pass_preview_matched')
                : __('app.closed_class_correction_pass_preview_none'),
            'pass' => $matchingPass ? [
                'id' => $matchingPass->id,
                'code' => $matchingPass->code,
                'plan_name' => $matchingPass->plan_name,
                'remaining_sessions' => $matchingPass->remainingSessionsCount(),
            ] : null,
        ]);
    }

    public function store(StoreClosedClassBookingCorrectionRequest $request, Account $account, ScheduledClass $scheduledClass, AddClosedClassBookingCorrection $correction): RedirectResponse|JsonResponse
    {
        $this->ensureClassBelongsToAccount($account, $scheduledClass);
        $scheduledClass->loadMissing('classType');

        if (! $scheduledClass->acceptsCustomerBookings()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('app.class_does_not_accept_customer_bookings'),
                    'errors' => [
                        'customer_id' => [__('app.class_does_not_accept_customer_bookings')],
                    ],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return back()->withErrors(['customer_id' => __('app.class_does_not_accept_customer_bookings')]);
        }

        $customer = $account->customers()->whereKey($request->validated('customer_id'))->firstOrFail();

        $correction->execute(
            $account,
            $scheduledClass,
            $customer,
            $request->user(),
            $request->status(),
            $request->validated('notes'),
            $request->validated('reason'),
        );

        if ($request->expectsJson()) {
            return $this->cardResponse($account, $scheduledClass, __('app.closed_class_booking_added_corrected'), Response::HTTP_CREATED);
        }

        return back()->with('status', __('app.closed_class_booking_added_corrected'));
    }

    public function remove(RemoveClosedClassBookingRequest $request, Account $account, ClassBooking $classBooking, RemoveClosedClassBookingCorrection $correction): RedirectResponse|JsonResponse
    {
        $this->ensureBookingBelongsToAccount($account, $classBooking);
        $scheduledClass = $classBooking->scheduledClass;

        $correction->execute(
            $account,
            $classBooking,
            $request->user(),
            $request->validated('pass_effect'),
            $request->validated('reason'),
        );

        if ($request->expectsJson()) {
            return $this->cardResponse($account, $scheduledClass, __('app.closed_class_booking_removed_corrected'));
        }

        return back()->with('status', __('app.closed_class_booking_removed_corrected'));
    }

    private function ensureClassBelongsToAccount(Account $account, ScheduledClass $scheduledClass): void
    {
        abort_unless($scheduledClass->account_id === $account->id, 404);
    }

    private function ensureBookingBelongsToAccount(Account $account, ClassBooking $classBooking): void
    {
        abort_unless($classBooking->account_id === $account->id, 404);
    }

    private function cardResponse(Account $account, ScheduledClass $scheduledClass, string $message, int $status = Response::HTTP_OK): JsonResponse
    {
        $scheduledClass->load([
            'location',
            'room',
            'classType.activityDirection',
            'trainer',
            'trainerChanges',
            'scheduleSeries',
            'activeCancellation.effects',
            'classBookings' => fn ($query) => $query
                ->notCorrectedRemoved()
                ->with(['customer', 'manualCashPayment', 'classPassReservation.customerClassPass.classPassPlan']),
            'classBookingCorrections',
        ]);

        return response()->json([
            'message' => $message,
            'scheduled_class_id' => $scheduledClass->id,
            'card_html' => view('scheduled-classes._card', [
                'account' => $account,
                'scheduledClass' => $scheduledClass,
                'customerSearchUrl' => route('dashboard.accounts.customers.search', $account),
                'bookingStatuses' => ClassBookingStatus::cases(),
                'trainerOptions' => $account->trainers()
                    ->orderByDesc('is_active')
                    ->orderBy('name')
                    ->get(['id', 'name', 'is_active']),
                'readonly' => true,
            ])->render(),
        ], $status);
    }
}
