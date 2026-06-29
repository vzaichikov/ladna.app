<?php

namespace App\Actions;

use App\Enums\CustomerClassPassAdjustmentType;
use App\Enums\CustomerClassPassStatus;
use App\Models\Account;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassAdjustment;
use App\Models\User;
use App\Support\ActorSnapshot;
use App\Support\Mail\TransactionalMailDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustCustomerClassPassSessions
{
    public function __construct(
        private readonly NormalizeCustomerClassPasses $normalizeCustomerClassPasses,
        private readonly ActorSnapshot $actorSnapshot,
        private readonly TransactionalMailDispatcher $mailDispatcher,
    ) {}

    public function execute(Account $account, CustomerClassPass $customerClassPass, ?User $user, int $sessionsDelta, string $reason): CustomerClassPassAdjustment
    {
        $adjustment = DB::transaction(function () use ($account, $customerClassPass, $user, $sessionsDelta, $reason): CustomerClassPassAdjustment {
            $lockedPass = CustomerClassPass::query()
                ->whereKey($customerClassPass->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($lockedPass->account_id === $account->id, 404);

            $lockedPass = $this->normalizeCustomerClassPasses->forPass($lockedPass);

            if (! $this->canAdjust($lockedPass)) {
                throw ValidationException::withMessages([
                    'sessions_delta' => __('app.class_pass_adjustment_unavailable'),
                ]);
            }

            if ($sessionsDelta === 0 || abs($sessionsDelta) > 500) {
                throw ValidationException::withMessages([
                    'sessions_delta' => __('app.class_pass_adjustment_too_large'),
                ]);
            }

            $previousSessionsCount = (int) $lockedPass->sessions_count;
            $newSessionsCount = $previousSessionsCount + $sessionsDelta;

            if ($newSessionsCount < $this->minimumSessionsCount($lockedPass)) {
                throw ValidationException::withMessages([
                    'sessions_delta' => __('app.class_pass_adjustment_below_usage'),
                ]);
            }

            if ($newSessionsCount > 65535) {
                throw ValidationException::withMessages([
                    'sessions_delta' => __('app.class_pass_adjustment_too_large'),
                ]);
            }

            $attributes = [
                'sessions_count' => $newSessionsCount,
            ];

            if ($sessionsDelta > 0 && $lockedPass->status === CustomerClassPassStatus::UsedUp) {
                $attributes += [
                    'status' => CustomerClassPassStatus::Active->value,
                    'is_active' => true,
                    'closed_at' => null,
                ];
            }

            $lockedPass->forceFill($attributes)->save();
            $this->normalizeCustomerClassPasses->forPass($lockedPass);

            return $lockedPass->adjustments()->create([
                'account_id' => $account->id,
                'user_id' => $user?->id,
                ...$this->actorSnapshot->capture($account, $user),
                'adjustment_type' => CustomerClassPassAdjustmentType::Sessions->value,
                'sessions_delta' => $sessionsDelta,
                'previous_sessions_count' => $previousSessionsCount,
                'new_sessions_count' => $newSessionsCount,
                'reason' => $reason,
            ]);
        });

        $this->mailDispatcher->classPassAdjusted($adjustment);

        return $adjustment;
    }

    private function canAdjust(CustomerClassPass $customerClassPass): bool
    {
        if (! $this->isWithinValidity($customerClassPass)) {
            return false;
        }

        if ($customerClassPass->status === CustomerClassPassStatus::Active && $customerClassPass->is_active) {
            return true;
        }

        return $customerClassPass->status === CustomerClassPassStatus::UsedUp;
    }

    private function isWithinValidity(CustomerClassPass $customerClassPass): bool
    {
        if ($customerClassPass->expires_at && $customerClassPass->expires_at->lessThanOrEqualTo(now())) {
            return false;
        }

        $usableUntilAt = $customerClassPass->usableUntilAt();

        return ! $usableUntilAt || $usableUntilAt->greaterThan(now());
    }

    private function minimumSessionsCount(CustomerClassPass $customerClassPass): int
    {
        return (int) $customerClassPass->used_sessions_count + (int) $customerClassPass->reserved_sessions_count;
    }
}
