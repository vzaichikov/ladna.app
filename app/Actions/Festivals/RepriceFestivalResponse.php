<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalPaymentStatus;
use App\Models\FestivalCharge;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepriceFestivalResponse
{
    public function execute(FestivalEntryRequirement $requirement, FestivalSubmission $submission): void
    {
        DB::transaction(function () use ($requirement, $submission): void {
            $requirement = FestivalEntryRequirement::query()->with(['definition', 'entry.account'])->whereKey($requirement->id)->lockForUpdate()->firstOrFail();
            $pricing = (array) ($requirement->definition->pricing ?? []);
            $mode = $pricing['mode'] ?? 'none';
            if ($mode === 'none') {
                return;
            }

            $target = $this->targetAmount($mode, $pricing, $submission->value_json['value'] ?? null);
            $charges = $requirement->entry->charges()
                ->with('paymentAllocations.attempt')
                ->where('festival_entry_requirement_id', $requirement->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($charges->whereIn('status', [FestivalChargeStatus::PaymentPending, FestivalChargeStatus::PaidRequiresRefund])->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'value' => __('app.festival_payment_already_pending'),
                ]);
            }
            if (FestivalPaymentAttempt::query()
                ->where('status', FestivalPaymentStatus::Pending->value)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereHas('allocations', fn ($query) => $query->whereIn('festival_charge_id', $charges->modelKeys()))
                ->exists()) {
                throw ValidationException::withMessages([
                    'value' => __('app.festival_payment_already_pending'),
                ]);
            }
            $currentCurrency = strtoupper($requirement->entry->account->default_currency);
            $paidCharges = $charges->where('status', FestivalChargeStatus::Paid);
            $hasForeignPaidCharge = $paidCharges->contains(
                fn ($charge): bool => strtoupper((string) $charge->currency) !== $currentCurrency,
            );
            if ($hasForeignPaidCharge) {
                throw ValidationException::withMessages([
                    'value' => __('app.festival_reprice_currency_mismatch'),
                ]);
            }
            $paid = (int) $paidCharges->sum('amount_cents');
            $adjustments = $requirement->entry->chargeAdjustments()
                ->where('festival_entry_requirement_id', $requirement->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($adjustments->where('status', 'pending') as $adjustment) {
                $adjustment->forceFill(['status' => 'cancelled'])->save();
            }
            $outstandingCharges = $charges->whereIn('status', [FestivalChargeStatus::Pending, FestivalChargeStatus::Failed]);
            $outstandingTarget = max(0, $target - $paid);
            $matchesOutstanding = $outstandingCharges->isNotEmpty()
                && (int) $outstandingCharges->sum('amount_cents') === $outstandingTarget
                && $outstandingCharges->every(fn (FestivalCharge $charge): bool => strtoupper($charge->currency) === $currentCurrency);

            if ($matchesOutstanding) {
                return;
            }

            $mutableCharge = $outstandingCharges->count() === 1 && ! $outstandingCharges->first()->hasPaymentHistory()
                ? $outstandingCharges->first()
                : ($charges->every(fn (FestivalCharge $charge): bool => ! $charge->hasPaymentHistory())
                    ? $charges->where('status', FestivalChargeStatus::Cancelled)->last()
                    : null);

            foreach ($outstandingCharges->reject(fn (FestivalCharge $charge): bool => $mutableCharge?->is($charge) === true) as $charge) {
                $charge->forceFill(['status' => FestivalChargeStatus::Cancelled, 'cancelled_at' => now(), 'notes' => __('app.festival_repriced_charge_cancelled')])->save();
            }

            if ($outstandingTarget > 0 && $mutableCharge) {
                $mutableCharge->forceFill([
                    'festival_submission_id' => $submission->id,
                    'name' => $requirement->definition->name,
                    'status' => FestivalChargeStatus::Pending,
                    'amount_cents' => $outstandingTarget,
                    'currency' => $currentCurrency,
                    'cancelled_at' => null,
                    'notes' => null,
                ])->save();
            } elseif ($outstandingTarget > 0) {
                $requirement->entry->charges()->create([
                    'account_id' => $requirement->account_id,
                    'festival_entry_step_id' => $requirement->festival_entry_step_id,
                    'festival_entry_requirement_id' => $requirement->id,
                    'festival_submission_id' => $submission->id,
                    'pricing_key' => $charges->isEmpty() ? 'response:'.$submission->id : null,
                    'code' => 'FCH-'.str()->upper(str()->random(12)),
                    'kind' => 'response_price',
                    'name' => $requirement->definition->name,
                    'amount_cents' => $outstandingTarget,
                    'currency' => $currentCurrency,
                ]);
            } elseif ($mutableCharge) {
                $mutableCharge->forceFill([
                    'status' => FestivalChargeStatus::Cancelled,
                    'cancelled_at' => now(),
                    'notes' => __('app.festival_repriced_charge_cancelled'),
                ])->save();
            }

            if ($target < $paid) {
                $mutableAdjustment = $adjustments->whereIn('status', ['pending', 'cancelled'])->last();
                if ($mutableAdjustment) {
                    $mutableAdjustment->forceFill([
                        'status' => 'pending',
                        'amount_cents' => $paid - $target,
                        'currency' => $currentCurrency,
                    ])->save();
                } else {
                    $requirement->entry->chargeAdjustments()->create([
                        'account_id' => $requirement->account_id,
                        'festival_entry_step_id' => $requirement->festival_entry_step_id,
                        'festival_entry_requirement_id' => $requirement->id,
                        'festival_submission_id' => $submission->id,
                        'idempotency_key' => 'response-refund:'.$submission->id.($adjustments->isEmpty() ? '' : ':'.($adjustments->count() + 1)),
                        'direction' => 'refund',
                        'status' => 'pending',
                        'amount_cents' => $paid - $target,
                        'currency' => $currentCurrency,
                    ]);
                }
            }
        }, 3);
    }

    /** @param array<string, mixed> $pricing */
    private function targetAmount(string $mode, array $pricing, mixed $value): int
    {
        return match ($mode) {
            'flat_when_true' => filter_var($value, FILTER_VALIDATE_BOOL) ? (int) ($pricing['amount_cents'] ?? 0) : 0,
            'per_unit' => max(0, (int) $value) * max(0, (int) ($pricing['unit_amount_cents'] ?? 0)),
            'option_prices' => collect(is_array($value) ? $value : [$value])->sum(fn ($option): int => (int) ($pricing['prices'][(string) $option] ?? 0)),
            default => 0,
        };
    }
}
