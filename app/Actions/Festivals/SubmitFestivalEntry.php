<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalCategoryWorkflow;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalQualificationStatus;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalRequirementDefinition;
use App\Support\Festivals\FestivalRuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitFestivalEntry
{
    public function __construct(
        private readonly FestivalRuleRegistry $rules,
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalNotificationOutbox $notifications,
    ) {}

    public function execute(FestivalEntry $entry): FestivalEntry
    {
        return DB::transaction(function () use ($entry): FestivalEntry {
            $purchase = FestivalEditionPurchase::query()
                ->where('festival_edition_id', $entry->festival_edition_id)
                ->lockForUpdate()
                ->first();
            abort_if($purchase?->status === FestivalEditionPurchaseStatus::PaymentReversed, 423, __('app.festival_payment_reversed_readonly'));

            $entry = FestivalEntry::query()
                ->with(['edition', 'category.options.axis', 'participants', 'portalUser'])
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($entry->status === FestivalEntryStatus::Draft, 409);
            $this->rules->validateEntry($entry->edition, $entry->category, $entry->participants);
            $this->assertParticipantLimit($entry, $purchase);

            $entry->category_snapshot = [
                'category_id' => $entry->category->id,
                'code' => $entry->category->code,
                'name' => $entry->category->name,
                'version' => $entry->category->version,
                'workflow' => $entry->category->workflow->value,
                'rules' => [
                    'min_members' => $entry->category->min_members,
                    'max_members' => $entry->category->max_members,
                    'min_age' => $entry->category->min_age,
                    'max_age' => $entry->category->max_age,
                    'min_duration_seconds' => $entry->category->min_duration_seconds,
                    'max_duration_seconds' => $entry->category->max_duration_seconds,
                ],
                'classification' => $entry->category->options->map(fn ($option): array => [
                    'axis' => $option->axis->name,
                    'axis_code' => $option->axis->code,
                    'option' => $option->label,
                    'option_code' => $option->code,
                ])->values()->all(),
            ];
            $entry->status = match ($entry->category->workflow) {
                FestivalCategoryWorkflow::Direct => FestivalEntryStatus::Accepted,
                default => FestivalEntryStatus::Submitted,
            };
            $entry->qualification_status = $entry->category->workflow === FestivalCategoryWorkflow::Qualification
                ? FestivalQualificationStatus::Pending
                : FestivalQualificationStatus::NotRequired;
            $entry->submitted_at = now();
            $entry->accepted_at = $entry->status === FestivalEntryStatus::Accepted ? now() : null;
            $entry->save();

            $requirements = FestivalRequirementDefinition::query()
                ->where('festival_edition_id', $entry->festival_edition_id)
                ->where(fn ($query) => $query->whereNull('festival_category_id')->orWhere('festival_category_id', $entry->festival_category_id))
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->get();

            foreach ($requirements as $definition) {
                $entry->requirements()->create([
                    'account_id' => $entry->account_id,
                    'festival_requirement_definition_id' => $definition->id,
                    'definition_snapshot' => [
                        'type' => $definition->type->value,
                        'name' => $definition->name,
                        'instructions' => $definition->instructions,
                        'stage' => $definition->stage,
                        'allowed_extensions' => $definition->allowed_extensions,
                        'allowed_mime_types' => $definition->allowed_mime_types,
                        'max_size_kb' => $definition->max_size_kb,
                        'min_duration_seconds' => $definition->min_duration_seconds,
                        'max_duration_seconds' => $definition->max_duration_seconds,
                        'is_required' => $definition->is_required,
                        'version' => $definition->version,
                    ],
                    'due_at' => $definition->due_at,
                    'status' => $definition->is_required ? 'missing' : 'waived',
                ]);
                $definition->forceFill(['locked_at' => $definition->locked_at ?? now()])->save();
            }

            $charges = FestivalChargeDefinition::query()
                ->where('festival_edition_id', $entry->festival_edition_id)
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('festival_category_id')->orWhere('festival_category_id', $entry->festival_category_id))
                ->lockForUpdate()
                ->get();

            foreach ($charges as $definition) {
                $entry->charges()->create([
                    'account_id' => $entry->account_id,
                    'festival_charge_definition_id' => $definition->id,
                    'code' => 'FCH-'.str()->upper(str()->random(12)),
                    'kind' => $definition->kind,
                    'name' => $definition->name,
                    'amount_cents' => $definition->amount_cents,
                    'currency' => $definition->currency,
                    'due_at' => $definition->due_at,
                    'status' => $definition->amount_cents === 0 ? 'paid' : 'pending',
                    'paid_at' => $definition->amount_cents === 0 ? now() : null,
                    'definition_snapshot' => [
                        'name' => $definition->name,
                        'kind' => $definition->kind,
                        'amount_cents' => $definition->amount_cents,
                        'currency' => $definition->currency,
                        'version' => $definition->version,
                    ],
                ]);
                $definition->forceFill(['locked_at' => $definition->locked_at ?? now()])->save();
            }

            $entry->category->forceFill(['locked_at' => $entry->category->locked_at ?? now()])->save();
            $this->activity->record($entry, 'entry.submitted', $entry->edition, $entry->portalUser);
            $this->notifications->queueForEntry($entry, 'entry_submitted', ['entry_code' => $entry->code]);

            return $entry->refresh()->load(['requirements', 'charges', 'participants']);
        }, 3);
    }

    private function assertParticipantLimit(FestivalEntry $entry, ?FestivalEditionPurchase $purchase): void
    {
        if (! $purchase) {
            return;
        }

        $participantIds = DB::table('festival_entry_participant')
            ->join('festival_entries', 'festival_entries.id', '=', 'festival_entry_participant.festival_entry_id')
            ->where('festival_entries.festival_edition_id', $entry->festival_edition_id)
            ->whereIn('festival_entries.status', [
                FestivalEntryStatus::Submitted->value,
                FestivalEntryStatus::UnderReview->value,
                FestivalEntryStatus::Accepted->value,
            ])
            ->where('festival_entries.id', '!=', $entry->id)
            ->distinct()
            ->pluck('festival_entry_participant.festival_participant_id')
            ->merge($entry->participants->modelKeys())
            ->unique();

        if ($participantIds->count() > $purchase->max_participants) {
            throw ValidationException::withMessages(['participants' => __('app.festival_participant_limit_exceeded', ['limit' => $purchase->max_participants])]);
        }
    }
}
