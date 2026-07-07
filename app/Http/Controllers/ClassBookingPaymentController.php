<?php

namespace App\Http\Controllers;

use App\Actions\RecordManualClassBookingPayment;
use App\Enums\ClassBookingStatus;
use App\Http\Requests\StoreClassBookingPaymentRequest;
use App\Models\Account;
use App\Models\ClassBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ClassBookingPaymentController extends Controller
{
    public function store(
        StoreClassBookingPaymentRequest $request,
        Account $account,
        ClassBooking $classBooking,
        RecordManualClassBookingPayment $recordManualClassBookingPayment,
    ): RedirectResponse|JsonResponse {
        $this->ensureBookingBelongsToAccount($account, $classBooking);

        try {
            $recordManualClassBookingPayment->execute($account, $classBooking, $request->amountCents());
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return back()->withErrors($exception->errors())->withInput();
        }

        if ($request->expectsJson()) {
            return $this->bookingJsonResponse($account, $classBooking, __('app.class_booking_payment_recorded'));
        }

        $returnUrl = $request->safeReturnUrl($account);

        if ($returnUrl !== null) {
            return redirect()->to($returnUrl)
                ->with('status', __('app.class_booking_payment_recorded'));
        }

        return redirect()->route('dashboard.accounts.scheduled-classes.index', $account)
            ->with('status', __('app.class_booking_payment_recorded'));
    }

    private function ensureBookingBelongsToAccount(Account $account, ClassBooking $classBooking): void
    {
        abort_unless($classBooking->account_id === $account->id, 404);
    }

    private function bookingJsonResponse(Account $account, ClassBooking $classBooking, string $message): JsonResponse
    {
        $scheduledClass = $classBooking->scheduledClass()->firstOrFail();
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
        ]);
    }
}
