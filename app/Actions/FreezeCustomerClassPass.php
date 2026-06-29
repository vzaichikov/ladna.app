<?php

namespace App\Actions;

use App\Enums\CustomerClassPassAdjustmentType;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
use App\Models\Account;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassAdjustment;
use App\Models\User;
use App\Support\ActorSnapshot;
use App\Support\Mail\TransactionalMailDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FreezeCustomerClassPass
{
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

            if ($lockedPass->status !== CustomerClassPassStatus::Active || ! $lockedPass->is_active) {
                throw ValidationException::withMessages([
                    'status' => __('app.class_pass_freeze_unavailable'),
                ]);
            }

            $now = now();
            $previousStatus = $lockedPass->status;

            $lockedPass->reservations()
                ->where('status', CustomerClassPassReservationStatus::Reserved->value)
                ->whereHas('scheduledClass', fn ($query) => $query->where('starts_at', '>=', $now))
                ->update([
                    'status' => CustomerClassPassReservationStatus::Released->value,
                    'released_at' => $now,
                    'used_at' => null,
                ]);

            $lockedPass->forceFill([
                'status' => CustomerClassPassStatus::Freezed->value,
                'is_active' => true,
                'closed_at' => null,
                'frozen_at' => $now,
            ])->save();

            $lockedPass = $this->normalizeCustomerClassPasses->forPass($lockedPass);

            return $lockedPass->adjustments()->create([
                'account_id' => $account->id,
                'user_id' => $user?->id,
                ...$this->actorSnapshot->capture($account, $user),
                'adjustment_type' => CustomerClassPassAdjustmentType::Freeze->value,
                'previous_status' => $previousStatus->value,
                'new_status' => $lockedPass->status->value,
                'freeze_started_at' => $now,
                'reason' => __('app.customer_class_pass_freeze_reason'),
            ]);
        });

        $this->mailDispatcher->classPassAdjusted($adjustment);

        return $adjustment;
    }
}
