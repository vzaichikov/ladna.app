<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalChargePricingMode;
use App\Enums\FestivalChargeStatus;
use App\Models\FestivalCharge;
use App\Models\FestivalEntry;
use Illuminate\Support\Facades\DB;

class RepriceFestivalEntryCharges
{
    public function __construct(private readonly FestivalChargeDefinitionResolver $resolver) {}

    public function execute(FestivalEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $entry = FestivalEntry::query()->with(['account', 'participants'])->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $charges = FestivalCharge::query()
                ->with('definition')
                ->where('festival_entry_id', $entry->id)
                ->whereIn('status', [FestivalChargeStatus::Pending->value, FestivalChargeStatus::Failed->value])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($charges as $charge) {
                if ($charge->definition?->pricing_mode !== FestivalChargePricingMode::Roster || $charge->paymentAttempts()->exists()) {
                    continue;
                }

                $charge->forceFill([
                    'amount_cents' => $this->resolver->amount($charge->definition, $entry),
                    'currency' => strtoupper($entry->account->default_currency),
                    'status' => FestivalChargeStatus::Pending,
                ])->save();
            }
        }, 3);
    }
}
