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

class AdjustCustomerClassPassValidityDays
{
    private const MAX_DAYS_DELTA = 3650;

    private const MAX_VALIDITY_DAYS = 65535;

    public function __construct(
        private readonly NormalizeCustomerClassPasses $normalizeCustomerClassPasses,
        private readonly ActorSnapshot $actorSnapshot,
        private readonly TransactionalMailDispatcher $mailDispatcher,
    ) {}

    public function execute(Account $account, CustomerClassPass $customerClassPass, ?User $user, int $daysDelta, string $reason): CustomerClassPassAdjustment
    {
        $adjustment = DB::transaction(function () use ($account, $customerClassPass, $user, $daysDelta, $reason): CustomerClassPassAdjustment {
            $lockedPass = CustomerClassPass::query()
                ->whereKey($customerClassPass->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($lockedPass->account_id === $account->id, 404);

            $lockedPass = $this->normalizeCustomerClassPasses->forPass($lockedPass);

            if (! $this->canAdjust($lockedPass)) {
                throw ValidationException::withMessages([
                    'days_delta' => __('app.class_pass_days_adjustment_unavailable'),
                ]);
            }

            if ($daysDelta === 0 || abs($daysDelta) > self::MAX_DAYS_DELTA) {
                throw ValidationException::withMessages([
                    'days_delta' => __('app.class_pass_days_adjustment_too_large'),
                ]);
            }

            $previousValidityDays = (int) $lockedPass->validity_days;
            $newValidityDays = $previousValidityDays + $daysDelta;

            if ($newValidityDays < 1) {
                throw ValidationException::withMessages([
                    'days_delta' => __('app.class_pass_days_adjustment_below_minimum'),
                ]);
            }

            if ($newValidityDays > self::MAX_VALIDITY_DAYS) {
                throw ValidationException::withMessages([
                    'days_delta' => __('app.class_pass_days_adjustment_too_large'),
                ]);
            }

            $previousStatus = $lockedPass->status;

            $lockedPass->forceFill([
                'validity_days' => $newValidityDays,
            ])->save();

            $lockedPass = $this->normalizeCustomerClassPasses->forPass($lockedPass);

            return $lockedPass->adjustments()->create([
                'account_id' => $account->id,
                'user_id' => $user?->id,
                ...$this->actorSnapshot->capture($account, $user),
                'adjustment_type' => CustomerClassPassAdjustmentType::ValidityDays->value,
                'days_delta' => $daysDelta,
                'previous_validity_days' => $previousValidityDays,
                'new_validity_days' => $newValidityDays,
                'previous_status' => $previousStatus->value,
                'new_status' => $lockedPass->status->value,
                'reason' => $reason,
            ]);
        });

        $this->mailDispatcher->classPassAdjusted($adjustment);

        return $adjustment;
    }

    private function canAdjust(CustomerClassPass $customerClassPass): bool
    {
        return $customerClassPass->is_active && in_array($customerClassPass->status, [
            CustomerClassPassStatus::Active,
            CustomerClassPassStatus::Freezed,
        ], true);
    }
}
