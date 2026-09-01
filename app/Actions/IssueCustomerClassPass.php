<?php

namespace App\Actions;

use App\Enums\CustomerClassPassAdjustmentType;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchase;
use App\Models\Location;
use App\Models\User;
use App\Support\ActorSnapshot;
use App\Support\ClassPassCodeGenerator;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\ScheduleKindRegistry;
use App\Support\TrialClassPassEligibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueCustomerClassPass
{
    public function __construct(
        private readonly ClassPassCodeGenerator $codeGenerator,
        private readonly ActorSnapshot $actorSnapshot,
        private readonly TransactionalMailDispatcher $mailDispatcher,
        private readonly ReconcileUnreservedCustomerBookingsForIssuedClassPass $reconcileUnreservedCustomerBookingsForIssuedClassPass,
        private readonly RecordManualCustomerClassPassPayment $recordManualCustomerClassPassPayment,
        private readonly TrialClassPassEligibility $trialClassPassEligibility,
    ) {}

    /**
     * @param  array{plan_name?: string, plan_slug?: string|null, price_cents?: int, currency?: string, sessions_count?: int, validity_days?: int, total_validity_days?: int, available_from_time?: string|null, available_until_time?: string|null, allows_any_time?: bool, any_time_addon_price_cents?: int|null}  $snapshot
     */
    public function execute(
        Account $account,
        Customer $customer,
        ClassPassPlan $classPassPlan,
        string $source = 'manual',
        ?Carbon $purchasedAt = null,
        array $snapshot = [],
        ?User $issuedBy = null,
        ?Location $issuedLocation = null,
        bool $isPaid = false,
        ?int $paidAmountCents = null,
        ?string $trialEligibilityOverrideReason = null,
        ?Carbon $trialEligibilityAsOf = null,
        ?CustomerPurchase $trialEligibilityOverridePurchase = null,
    ): CustomerClassPass {
        if ($customer->account_id !== $account->id || $classPassPlan->account_id !== $account->id) {
            abort(404);
        }

        if ($issuedLocation && $issuedLocation->account_id !== $account->id) {
            abort(404);
        }

        if (! $account->hasScheduleKindEnabled($classPassPlan->schedule_kind)
            || ! ScheduleKindRegistry::hasCapability($classPassPlan->schedule_kind, 'class_pass_eligible')) {
            abort(404);
        }

        $trialEligibilityOverrideReason = filled($trialEligibilityOverrideReason)
            ? trim((string) $trialEligibilityOverrideReason)
            : null;
        $usesTrialEligibilityOverride = $trialEligibilityOverrideReason !== null;
        $normalTrialEligibility = $classPassPlan->is_trial
            ? $this->trialClassPassEligibility->evaluate($account, $customer, $source, $trialEligibilityAsOf)
            : null;

        if (! $classPassPlan->is_trial) {
            if ($usesTrialEligibilityOverride) {
                throw ValidationException::withMessages([
                    'override_trial_eligibility' => __('app.trial_class_pass_override_unavailable'),
                ]);
            }
        } elseif ($normalTrialEligibility['status'] === 'eligible') {
            if ($usesTrialEligibilityOverride) {
                throw ValidationException::withMessages([
                    'override_trial_eligibility' => __('app.trial_class_pass_override_not_required'),
                ]);
            }
        } elseif (! $usesTrialEligibilityOverride) {
            throw ValidationException::withMessages([
                'class_pass_plan_id' => __('app.trial_class_pass_not_available'),
            ]);
        }

        if ($usesTrialEligibilityOverride) {
            $this->assertValidTrialEligibilityOverride(
                $account,
                $customer,
                $classPassPlan,
                $source,
                $issuedBy,
                $trialEligibilityOverrideReason,
                $trialEligibilityOverridePurchase,
            );
        }

        $purchasedAt ??= now();
        $totalValidityDays = (int) ($snapshot['total_validity_days'] ?? $classPassPlan->total_validity_days);
        $priceCents = (int) ($snapshot['price_cents'] ?? $classPassPlan->price_cents);
        $paidAmountCents = match (true) {
            $source === 'online_payment' => $priceCents,
            $isPaid => $priceCents,
            $paidAmountCents !== null => min(max(0, $paidAmountCents), $priceCents),
            default => 0,
        };
        $isPaid = $priceCents <= 0 || $paidAmountCents >= $priceCents;

        if ($source === 'manual' && $paidAmountCents > 0 && ! $issuedLocation) {
            throw ValidationException::withMessages([
                'issued_location_id' => __('app.class_pass_payment_location_required'),
            ]);
        }

        $classPass = DB::transaction(function () use ($account, $customer, $classPassPlan, $source, $issuedBy, $issuedLocation, $isPaid, $paidAmountCents, $priceCents, $snapshot, $purchasedAt, $totalValidityDays, $trialEligibilityOverrideReason, $usesTrialEligibilityOverride, $trialEligibilityOverridePurchase): CustomerClassPass {
            if ($usesTrialEligibilityOverride) {
                Customer::query()
                    ->whereBelongsTo($account)
                    ->whereKey($customer->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                CustomerClassPass::query()
                    ->whereBelongsTo($account)
                    ->whereBelongsTo($customer)
                    ->lockForUpdate()
                    ->get(['id']);
                CustomerPurchase::query()
                    ->whereBelongsTo($account)
                    ->whereBelongsTo($customer)
                    ->lockForUpdate()
                    ->get(['id']);

                $this->assertValidTrialEligibilityOverride(
                    $account,
                    $customer,
                    $classPassPlan,
                    $source,
                    $issuedBy,
                    (string) $trialEligibilityOverrideReason,
                    $trialEligibilityOverridePurchase,
                );
            }

            $classPass = $account->customerClassPasses()->create([
                'customer_id' => $customer->id,
                'class_pass_plan_id' => $classPassPlan->id,
                'code' => $this->codeGenerator->unique(),
                'source' => $source,
                'issued_location_id' => $issuedLocation?->id,
                'is_paid' => $source === 'manual' ? $priceCents <= 0 : $isPaid,
                ...$this->actorSnapshot->prefixed($account, $issuedBy, 'issued_by_actor'),
                'status' => 'active',
                'plan_name' => $snapshot['plan_name'] ?? $classPassPlan->name,
                'plan_slug' => $snapshot['plan_slug'] ?? $classPassPlan->slug,
                'price_cents' => $priceCents,
                'paid_amount_cents' => $source === 'manual' ? 0 : $paidAmountCents,
                'currency' => $snapshot['currency'] ?? $classPassPlan->currency,
                'sessions_count' => $snapshot['sessions_count'] ?? $classPassPlan->sessions_count,
                'validity_days' => $snapshot['validity_days'] ?? $classPassPlan->validity_days,
                'total_validity_days' => $totalValidityDays,
                'available_from_time' => $snapshot['available_from_time'] ?? $classPassPlan->available_from_time,
                'available_until_time' => $snapshot['available_until_time'] ?? $classPassPlan->available_until_time,
                'allows_any_time' => $snapshot['allows_any_time'] ?? $classPassPlan->allows_any_time,
                'any_time_addon_price_cents' => $snapshot['any_time_addon_price_cents'] ?? $classPassPlan->any_time_addon_price_cents,
                'purchased_at' => $purchasedAt,
                'usable_until_at' => $purchasedAt->copy()->addDays($totalValidityDays),
                'is_active' => true,
            ]);

            if ($usesTrialEligibilityOverride) {
                $classPass->adjustments()->create([
                    'account_id' => $account->id,
                    'user_id' => $issuedBy?->id,
                    ...$this->actorSnapshot->capture($account, $issuedBy),
                    'adjustment_type' => CustomerClassPassAdjustmentType::TrialEligibilityOverride->value,
                    'reason' => $trialEligibilityOverrideReason,
                ]);
            }

            $this->reconcileUnreservedCustomerBookingsForIssuedClassPass->execute($classPass);

            $classPass = $classPass->refresh();

            if ($source === 'manual' && $paidAmountCents > 0 && $issuedLocation) {
                $this->recordManualCustomerClassPassPayment->execute($account, $classPass, $issuedLocation, $paidAmountCents, $purchasedAt);
                $classPass = $classPass->refresh();
            }

            return $classPass;
        });

        if ($source !== 'online_payment') {
            $this->mailDispatcher->customerClassPassIssued($classPass);
        }

        return $classPass;
    }

    private function assertValidTrialEligibilityOverride(
        Account $account,
        Customer $customer,
        ClassPassPlan $classPassPlan,
        string $source,
        ?User $issuedBy,
        string $reason,
        ?CustomerPurchase $overridePurchase,
    ): void {
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages([
                'trial_eligibility_override_reason' => __('app.trial_class_pass_override_comment_invalid'),
            ]);
        }

        if (! $classPassPlan->is_trial) {
            throw ValidationException::withMessages([
                'override_trial_eligibility' => __('app.trial_class_pass_override_unavailable'),
            ]);
        }

        $available = match ($source) {
            TrialClassPassEligibility::SourceManual => $this->trialClassPassEligibility->evaluateManualOverride(
                $account,
                $customer,
                $source,
                actor: $issuedBy,
            )['available'],
            TrialClassPassEligibility::SourceOnlinePayment => $issuedBy !== null
                && $overridePurchase !== null
                && $overridePurchase->class_pass_plan_id === $classPassPlan->id
                && $this->trialClassPassEligibility->paidOnlineOverrideIsAvailable(
                    $account,
                    $customer,
                    $overridePurchase,
                    $issuedBy,
                ),
            default => false,
        };

        if (! $available) {
            throw ValidationException::withMessages([
                'override_trial_eligibility' => __('app.trial_class_pass_override_unavailable'),
            ]);
        }
    }
}
