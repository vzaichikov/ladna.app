<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalChargeStatus;
use App\Models\FestivalEntryRequirement;
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
            $charges = $requirement->entry->charges()->where('festival_entry_requirement_id', $requirement->id)->lockForUpdate()->get();
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
            $requirement->entry->chargeAdjustments()->where('festival_entry_requirement_id', $requirement->id)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);

            foreach ($charges->whereIn('status', [FestivalChargeStatus::Pending, FestivalChargeStatus::Failed]) as $charge) {
                $charge->forceFill(['status' => FestivalChargeStatus::Cancelled, 'cancelled_at' => now(), 'notes' => __('app.festival_repriced_charge_cancelled')])->save();
            }

            if ($target > $paid) {
                $requirement->entry->charges()->create([
                    'account_id' => $requirement->account_id,
                    'festival_entry_step_id' => $requirement->festival_entry_step_id,
                    'festival_entry_requirement_id' => $requirement->id,
                    'festival_submission_id' => $submission->id,
                    'pricing_key' => 'response:'.$submission->id,
                    'code' => 'FCH-'.str()->upper(str()->random(12)),
                    'kind' => 'response_price',
                    'name' => $requirement->definition->name,
                    'amount_cents' => $target - $paid,
                    'currency' => $currentCurrency,
                ]);
            } elseif ($target < $paid) {
                $requirement->entry->chargeAdjustments()->firstOrCreate(
                    ['idempotency_key' => 'response-refund:'.$submission->id],
                    [
                        'account_id' => $requirement->account_id,
                        'festival_entry_step_id' => $requirement->festival_entry_step_id,
                        'festival_entry_requirement_id' => $requirement->id,
                        'festival_submission_id' => $submission->id,
                        'direction' => 'refund',
                        'status' => 'pending',
                        'amount_cents' => $paid - $target,
                        'currency' => $currentCurrency,
                    ],
                );
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
