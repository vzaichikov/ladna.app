<?php

namespace App\Actions;

use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\ScheduledClass;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class RetireFutureScheduledClass
{
    public function __construct(private readonly CancelScheduledClassForStudio $cancelScheduledClass) {}

    public function execute(Account $account, ScheduledClass $scheduledClass, CarbonImmutable $cutoff, ?User $actor = null): bool
    {
        abort_unless($scheduledClass->account_id === $account->id, 404);

        if ($scheduledClass->starts_at->lessThanOrEqualTo($cutoff) || $scheduledClass->status === ScheduledClassStatus::Cancelled) {
            return false;
        }

        if ($scheduledClass->classBookings()->exists() || $scheduledClass->cancellations()->exists() || $scheduledClass->trainerChanges()->exists() || $scheduledClass->classBookingCorrections()->exists()) {
            if ($actor) {
                Gate::forUser($actor)->authorize('manageBookings', $account);
            }

            $this->cancelScheduledClass->execute($account, $scheduledClass, $actor, ['reconcile_reservations' => false]);

            return false;
        }

        $scheduledClass->delete();

        return true;
    }
}
