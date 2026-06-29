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

class UnfreezeCustomerClassPass
{
    private const MAX_VALIDITY_DAYS = 65535;

    public function __construct(
        private readonly NormalizeCustomerClassPasses $normalizeCustomerClassPasses,
        private readonly ActorSnapshot $actorSnapshot,
        private readonly TransactionalMailDispatcher $mailDispatcher,
    ) {}

    public function execute(Account $account, CustomerClassPass $customerClassPass, ?User $user): CustomerClassPassAdjustment
    {
        $adjustment = DB::transaction(function () use ($account, $customerClassPass, $user): CustomerClassPassAdjustment {
            $lockedPass = CustomerClassPass::query()
                ->whereKey($customerClassPass->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($lockedPass->account_id === $account->id, 404);

            $lockedPass = $this->normalizeCustomerClassPasses->forPass($lockedPass);

            if ($lockedPass->status !== CustomerClassPassStatus::Freezed || ! $lockedPass->is_active || ! $lockedPass->frozen_at) {
                throw ValidationException::withMessages([
                    'status' => __('app.class_pass_unfreeze_unavailable'),
                ]);
            }

            $now = now();
            $timezone = $account->timezone ?: config('app.timezone');
            $freezeStartedAt = $lockedPass->frozen_at;
            $freezeDaysCount = max(1, (int) $freezeStartedAt
                ->copy()
                ->timezone($timezone)
                ->startOfDay()
                ->diffInDays($now->copy()->timezone($timezone)->startOfDay()));
            $previousValidityDays = (int) $lockedPass->validity_days;
            $newValidityDays = $previousValidityDays + $freezeDaysCount;

            if ($newValidityDays > self::MAX_VALIDITY_DAYS) {
                throw ValidationException::withMessages([
                    'status' => __('app.class_pass_adjustment_too_large'),
                ]);
            }

            $previousStatus = $lockedPass->status;

            $lockedPass->forceFill([
                'validity_days' => $newValidityDays,
                'status' => CustomerClassPassStatus::Active->value,
                'is_active' => true,
                'closed_at' => null,
                'frozen_at' => null,
            ])->save();

            $lockedPass = $this->normalizeCustomerClassPasses->forPass($lockedPass);

            return $lockedPass->adjustments()->create([
                'account_id' => $account->id,
                'user_id' => $user?->id,
                ...$this->actorSnapshot->capture($account, $user),
                'adjustment_type' => CustomerClassPassAdjustmentType::Unfreeze->value,
                'days_delta' => $freezeDaysCount,
                'previous_validity_days' => $previousValidityDays,
                'new_validity_days' => $newValidityDays,
                'previous_status' => $previousStatus->value,
                'new_status' => $lockedPass->status->value,
                'freeze_started_at' => $freezeStartedAt,
                'freeze_finished_at' => $now,
                'freeze_days_count' => $freezeDaysCount,
                'reason' => __('app.customer_class_pass_unfreeze_reason', ['days' => $freezeDaysCount]),
            ]);
        });

        $this->mailDispatcher->classPassAdjusted($adjustment);

        return $adjustment;
    }
}
