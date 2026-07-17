<?php

namespace App\Http\Controllers;

use App\Actions\ChangeScheduledClassTrainer;
use App\Enums\ClassBookingStatus;
use App\Http\Requests\UpdateScheduledClassTrainerRequest;
use App\Models\Account;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;

class ScheduledClassTrainerController extends Controller
{
    public function update(
        UpdateScheduledClassTrainerRequest $request,
        Account $account,
        ScheduledClass $scheduledClass,
        ChangeScheduledClassTrainer $changeTrainer,
    ): RedirectResponse|JsonResponse {
        abort_unless($scheduledClass->account_id === $account->id, 404);

        $trainer = $account->trainers()
            ->whereKey((int) $request->validated('trainer_id'))
            ->firstOrFail();
        $scheduledClass = $changeTrainer->execute($account, $scheduledClass, $trainer, $request->user());

        if ($request->expectsJson()) {
            return $this->jsonResponse($account, $scheduledClass, (bool) $request->validated('readonly', false));
        }

        return back()->with('status', __('app.scheduled_class_trainer_updated'));
    }

    private function jsonResponse(Account $account, ScheduledClass $scheduledClass, bool $readonly): JsonResponse
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
        ]);

        return response()->json([
            'message' => __('app.scheduled_class_trainer_updated'),
            'scheduled_class_id' => $scheduledClass->id,
            'card_html' => view('scheduled-classes._card', [
                'account' => $account,
                'scheduledClass' => $scheduledClass,
                'customerSearchUrl' => route('dashboard.accounts.customers.search', $account),
                'bookingStatuses' => ClassBookingStatus::cases(),
                'trainerOptions' => $this->trainerOptions($account),
                'readonly' => $readonly,
            ])->render(),
        ]);
    }

    /**
     * @return Collection<int, Trainer>
     */
    private function trainerOptions(Account $account): Collection
    {
        return $account->trainers()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);
    }
}
