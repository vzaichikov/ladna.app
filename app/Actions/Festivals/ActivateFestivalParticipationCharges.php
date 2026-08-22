<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalChargeStatus;
use App\Models\FestivalCharge;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ActivateFestivalParticipationCharges
{
    public function __construct(private readonly FestivalChargeDefinitionResolver $resolver) {}

    public function execute(FestivalEntry $entry, CarbonInterface $approvedAt): void
    {
        DB::transaction(function () use ($entry, $approvedAt): void {
            $entry = FestivalEntry::query()->with(['account', 'participants', 'steps.workflowStep'])->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $definitions = FestivalChargeDefinition::query()
                ->where('festival_edition_id', $entry->festival_edition_id)
                ->where('kind', 'participation')
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('festival_category_id')->orWhere('festival_category_id', $entry->festival_category_id))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $definitionIds = $definitions->modelKeys();

            FestivalCharge::query()
                ->where('festival_entry_id', $entry->id)
                ->where('kind', 'participation')
                ->when($definitionIds !== [], fn ($query) => $query->whereNotIn('festival_charge_definition_id', $definitionIds))
                ->when($definitionIds === [], fn ($query) => $query->whereNotNull('id'))
                ->whereIn('status', [FestivalChargeStatus::Pending->value, FestivalChargeStatus::Failed->value])
                ->update(['status' => FestivalChargeStatus::Cancelled->value, 'cancelled_at' => now()]);

            foreach ($definitions as $definition) {
                $step = $entry->steps->firstWhere('festival_workflow_step_id', $definition->festival_workflow_step_id)
                    ?? $entry->steps->first(fn ($candidate): bool => $candidate->workflowStep->code === 'participation_payment');
                if (! $step) {
                    continue;
                }

                $amount = $this->resolver->amount($definition, $entry);
                $charge = FestivalCharge::query()
                    ->with('paymentAllocations')
                    ->where('festival_entry_id', $entry->id)
                    ->where('festival_charge_definition_id', $definition->id)
                    ->lockForUpdate()
                    ->first();
                $values = [
                    'account_id' => $entry->account_id,
                    'festival_entry_step_id' => $step->id,
                    'festival_charge_definition_id' => $definition->id,
                    'kind' => $definition->kind,
                    'name' => $definition->name,
                    'amount_cents' => $amount,
                    'currency' => strtoupper($entry->account->default_currency),
                    'due_at' => $this->resolver->dueAt($definition, $approvedAt),
                    'status' => $amount === 0 ? FestivalChargeStatus::Paid : FestivalChargeStatus::Pending,
                    'paid_at' => $amount === 0 ? now() : null,
                    'cancelled_at' => null,
                ];

                if ($charge) {
                    if (! in_array($charge->status, [FestivalChargeStatus::Pending, FestivalChargeStatus::Failed, FestivalChargeStatus::Cancelled], true)
                        || $charge->hasPaymentHistory()) {
                        continue;
                    }
                    $charge->forceFill($values)->save();
                } else {
                    $entry->charges()->create([
                        ...$values,
                        'code' => 'FCH-'.str()->upper(str()->random(12)),
                    ]);
                }
            }
        }, 3);
    }
}
