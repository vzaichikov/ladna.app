<?php

namespace App\Actions;

use App\Enums\CustomerClassPassReservationStatus;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Location;
use App\Models\User;
use App\Support\ActorSnapshot;
use App\Support\ClassPassCodeGenerator;
use App\Support\Mail\TransactionalMailDispatcher;
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
    ): CustomerClassPass {
        if ($customer->account_id !== $account->id || $classPassPlan->account_id !== $account->id) {
            abort(404);
        }

        if ($issuedLocation && $issuedLocation->account_id !== $account->id) {
            abort(404);
        }

        $this->assertTrialClassPassIsAvailable($account, $customer, $classPassPlan, $source);

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

        $classPass = DB::transaction(function () use ($account, $customer, $classPassPlan, $source, $issuedBy, $issuedLocation, $isPaid, $paidAmountCents, $priceCents, $snapshot, $purchasedAt, $totalValidityDays): CustomerClassPass {
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

    private function assertTrialClassPassIsAvailable(Account $account, Customer $customer, ClassPassPlan $classPassPlan, string $source): void
    {
        if (! $classPassPlan->is_trial) {
            return;
        }

        $bookings = $customer->classBookings()
            ->notCorrectedRemoved()
            ->where('account_id', $account->id);

        if ($source !== 'manual') {
            if ($bookings->exists()) {
                $this->throwTrialUnavailable();
            }

            return;
        }

        $bookingCount = (clone $bookings)->count();

        if ($bookingCount === 0) {
            return;
        }

        if ($bookingCount !== 1) {
            $this->throwTrialUnavailable();
        }

        $hasActiveReservation = (clone $bookings)
            ->whereHas('classPassReservation', fn ($query) => $query->whereIn('status', [
                CustomerClassPassReservationStatus::Reserved->value,
                CustomerClassPassReservationStatus::Used->value,
            ]))
            ->exists();

        if ($hasActiveReservation) {
            $this->throwTrialUnavailable();
        }
    }

    private function throwTrialUnavailable(): void
    {
        throw ValidationException::withMessages([
            'class_pass_plan_id' => __('app.trial_class_pass_not_available'),
        ]);
    }
}
