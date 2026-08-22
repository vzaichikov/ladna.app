<?php

namespace App\Support\Festivals;

use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalPaymentStatus;
use App\Models\FestivalCharge;
use App\Models\FestivalEntryStep;
use App\Models\FestivalPaymentAttemptCharge;
use Illuminate\Support\Collection;

class FestivalChargePaymentGroups
{
    /** @return Collection<int, array{key: string, charge: FestivalCharge, charges: Collection<int, FestivalCharge>, status: FestivalChargeStatus, amount_cents: int, currency: string, due_at: mixed}> */
    public function forStep(FestivalEntryStep $step): Collection
    {
        $charges = $step->charges
            ->whereNotIn('status', [FestivalChargeStatus::Cancelled, FestivalChargeStatus::Refunded])
            ->values();
        $charges->loadMissing('paymentAllocations.attempt');

        $groups = collect();
        $recoverablePaymentPending = $charges
            ->where('status', FestivalChargeStatus::PaymentPending)
            ->reject(fn (FestivalCharge $charge): bool => $this->allocationsForCharge($charge)->contains(
                fn (FestivalPaymentAttemptCharge $allocation): bool => $allocation->attempt?->status === FestivalPaymentStatus::Pending
                    && ($allocation->attempt->expires_at === null || $allocation->attempt->expires_at->isFuture()),
            ));
        $outstanding = $charges
            ->whereIn('status', [FestivalChargeStatus::Pending, FestivalChargeStatus::Failed])
            ->concat($recoverablePaymentPending)
            ->unique('id')
            ->values();

        foreach ($outstanding->groupBy(fn (FestivalCharge $charge): string => strtoupper($charge->currency)) as $currency => $currencyCharges) {
            $groups->push($this->group(
                key: 'outstanding-'.$step->id.'-'.$currency,
                charges: $currencyCharges,
                status: $currencyCharges->contains('status', FestivalChargeStatus::Pending)
                    ? FestivalChargeStatus::Pending
                    : FestivalChargeStatus::Failed,
            ));
        }

        $allocatedCharges = $charges->whereIn('status', [
            FestivalChargeStatus::PaymentPending,
            FestivalChargeStatus::Paid,
            FestivalChargeStatus::PaidRequiresRefund,
        ])->whereNotIn('id', $recoverablePaymentPending->modelKeys())->groupBy(function (FestivalCharge $charge): string {
            $paymentStatus = $charge->status === FestivalChargeStatus::PaymentPending
                ? FestivalPaymentStatus::Pending
                : FestivalPaymentStatus::Paid;
            $allocation = $this->allocationsForCharge($charge)
                ->filter(fn (FestivalPaymentAttemptCharge $allocation): bool => $allocation->attempt?->status === $paymentStatus
                    && ($paymentStatus !== FestivalPaymentStatus::Pending
                        || $allocation->attempt->expires_at === null
                        || $allocation->attempt->expires_at->isFuture()))
                ->sortByDesc('festival_payment_attempt_id')
                ->first();

            return $allocation
                ? 'attempt-'.$allocation->festival_payment_attempt_id
                : 'charge-'.$charge->id;
        });

        foreach ($allocatedCharges as $key => $groupCharges) {
            $groups->push($this->group(
                key: $key,
                charges: $groupCharges,
                status: $groupCharges->contains('status', FestivalChargeStatus::PaidRequiresRefund)
                    ? FestivalChargeStatus::PaidRequiresRefund
                    : $groupCharges->firstOrFail()->status,
                attemptId: str_starts_with($key, 'attempt-') ? (int) str($key)->after('attempt-')->toString() : null,
            ));
        }

        return $groups->sortBy(fn (array $group): int => $group['charges']->min('id'))->values();
    }

    /**
     * @param  Collection<int, FestivalCharge>  $charges
     * @return array{key: string, charge: FestivalCharge, charges: Collection<int, FestivalCharge>, status: FestivalChargeStatus, amount_cents: int, currency: string, due_at: mixed}
     */
    private function group(string $key, Collection $charges, FestivalChargeStatus $status, ?int $attemptId = null): array
    {
        $charges = $charges->sortBy('id')->values();
        $leadCharge = $charges->first(fn (FestivalCharge $charge): bool => $charge->festival_charge_definition_id !== null)
            ?? $charges->firstOrFail();
        $allocations = $attemptId === null
            ? collect()
            : $charges->flatMap(fn (FestivalCharge $charge): Collection => $this->allocationsForCharge($charge)
                ->where('festival_payment_attempt_id', $attemptId));

        return [
            'key' => $key,
            'charge' => $leadCharge,
            'charges' => $charges,
            'status' => $status,
            'amount_cents' => $allocations->isNotEmpty()
                ? (int) $allocations->sum('amount_cents')
                : (int) $charges->sum('amount_cents'),
            'currency' => strtoupper($allocations->first()?->currency ?? $leadCharge->currency),
            'due_at' => $charges->whereNotNull('due_at')->min('due_at'),
        ];
    }

    /** @return Collection<int, FestivalPaymentAttemptCharge> */
    private function allocationsForCharge(FestivalCharge $charge): Collection
    {
        return $charge->paymentAllocations->filter(
            fn (FestivalPaymentAttemptCharge $allocation): bool => $allocation->account_id === $charge->account_id
                && $allocation->festival_charge_id === $charge->id
                && $allocation->attempt?->account_id === $charge->account_id,
        );
    }
}
