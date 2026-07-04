<?php

namespace App\Http\Controllers;

use App\Actions\CancelScheduledClassForStudio;
use App\Actions\RestoreScheduledClassCancellation;
use App\Enums\ClassBookingStatus;
use App\Http\Requests\CancelClosedScheduledClassRequest;
use App\Models\Account;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassCancellation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ScheduledClassCancellationController extends Controller
{
    public function cancel(Request $request, Account $account, ScheduledClass $scheduledClass, CancelScheduledClassForStudio $cancelScheduledClass): RedirectResponse|JsonResponse
    {
        $this->authorize('manageSchedule', $account);
        $this->authorize('manageBookings', $account);
        $this->ensureClassBelongsToAccount($account, $scheduledClass);

        try {
            $cancelScheduledClass->execute($account, $scheduledClass, $request->user());
        } catch (ValidationException $exception) {
            return $this->validationErrorResponse($request, $exception);
        }

        if ($this->wantsJsonResponse($request)) {
            return $this->scheduledClassJsonResponse($account, $scheduledClass, __('app.scheduled_class_cancelled'));
        }

        return redirect()->route('dashboard.accounts.scheduled-classes.index', $account)
            ->with('status', __('app.scheduled_class_cancelled'));
    }

    public function restore(Request $request, Account $account, ScheduledClass $scheduledClass, RestoreScheduledClassCancellation $restoreScheduledClassCancellation): RedirectResponse|JsonResponse
    {
        $this->authorize('manageSchedule', $account);
        $this->authorize('manageBookings', $account);
        $this->ensureClassBelongsToAccount($account, $scheduledClass);

        try {
            $restoreScheduledClassCancellation->execute($account, $scheduledClass, $request->user());
        } catch (ValidationException $exception) {
            return $this->validationErrorResponse($request, $exception);
        }

        if ($this->wantsJsonResponse($request)) {
            return $this->scheduledClassJsonResponse($account, $scheduledClass, __('app.scheduled_class_restored'));
        }

        return redirect()->route('dashboard.accounts.scheduled-classes.index', $account)
            ->with('status', __('app.scheduled_class_restored'));
    }

    public function cancelClosed(CancelClosedScheduledClassRequest $request, Account $account, ScheduledClass $scheduledClass, CancelScheduledClassForStudio $cancelScheduledClass): RedirectResponse|JsonResponse
    {
        $this->ensureClassBelongsToAccount($account, $scheduledClass);

        try {
            $cancelScheduledClass->execute($account, $scheduledClass, $request->user(), [
                'mode' => ScheduledClassCancellation::ModeClosedCorrection,
                'pass_effect' => $request->string('pass_effect')->toString(),
                'reason' => $request->string('reason')->toString(),
            ]);
        } catch (ValidationException $exception) {
            return $this->validationErrorResponse($request, $exception);
        }

        if ($this->wantsJsonResponse($request)) {
            return $this->scheduledClassJsonResponse($account, $scheduledClass, __('app.scheduled_class_closed_cancelled'));
        }

        return back()->with('status', __('app.scheduled_class_closed_cancelled'));
    }

    private function ensureClassBelongsToAccount(Account $account, ScheduledClass $scheduledClass): void
    {
        abort_unless($scheduledClass->account_id === $account->id, 404);
    }

    private function validationErrorResponse(Request $request, ValidationException $exception): RedirectResponse|JsonResponse
    {
        if ($this->wantsJsonResponse($request)) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return back()->withErrors($exception->errors())->withInput();
    }

    private function wantsJsonResponse(Request $request): bool
    {
        return $request->expectsJson() || $request->isJson() || $request->ajax();
    }

    private function scheduledClassJsonResponse(Account $account, ScheduledClass $scheduledClass, string $message): JsonResponse
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
        ]);
    }
}
