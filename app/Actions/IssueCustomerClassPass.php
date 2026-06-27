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
        private readonly SyncManualCustomerClassPassPayment $syncManualCustomerClassPassPayment,
    ) {}

    /**
     * @param  array{plan_name?: string, plan_slug?: string|null, price_cents?: int, currency?: string, sessions_count?: int, validity_days?: int, total_validity_days?: int}  $snapshot
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
        $isPaid = $source === 'online_payment' || $isPaid;

        $classPass = DB::transaction(function () use ($account, $customer, $classPassPlan, $source, $issuedBy, $issuedLocation, $isPaid, $snapshot, $purchasedAt, $totalValidityDays): CustomerClassPass {
            $classPass = $account->customerClassPasses()->create([
                'customer_id' => $customer->id,
                'class_pass_plan_id' => $classPassPlan->id,
                'code' => $this->codeGenerator->unique(),
                'source' => $source,
                'issued_location_id' => $issuedLocation?->id,
                'is_paid' => $isPaid,
                ...$this->actorSnapshot->prefixed($account, $issuedBy, 'issued_by_actor'),
                'status' => 'active',
                'plan_name' => $snapshot['plan_name'] ?? $classPassPlan->name,
                'plan_slug' => $snapshot['plan_slug'] ?? $classPassPlan->slug,
                'price_cents' => $snapshot['price_cents'] ?? $classPassPlan->price_cents,
                'currency' => $snapshot['currency'] ?? $classPassPlan->currency,
                'sessions_count' => $snapshot['sessions_count'] ?? $classPassPlan->sessions_count,
                'validity_days' => $snapshot['validity_days'] ?? $classPassPlan->validity_days,
                'total_validity_days' => $totalValidityDays,
                'purchased_at' => $purchasedAt,
                'usable_until_at' => $purchasedAt->copy()->addDays($totalValidityDays),
                'is_active' => true,
            ]);

            $this->reconcileUnreservedCustomerBookingsForIssuedClassPass->execute($classPass);

            $classPass = $classPass->refresh();

            if ($source === 'manual') {
                $this->syncManualCustomerClassPassPayment->execute($account, $classPass, $isPaid);
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
