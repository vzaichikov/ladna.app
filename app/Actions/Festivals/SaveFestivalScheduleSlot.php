<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalScheduleSlotType;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalStage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveFestivalScheduleSlot
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalNotificationOutbox $notifications,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(FestivalEdition $edition, array $input, User $actor, ?FestivalScheduleSlot $slot = null): FestivalScheduleSlot
    {
        $edition->loadMissing('account');
        $type = $input['type'] instanceof FestivalScheduleSlotType
            ? $input['type']
            : FestivalScheduleSlotType::from((string) $input['type']);
        $startsAt = $type->isTimed() ? CarbonImmutable::parse((string) $input['starts_at'], $edition->timezone)->utc() : null;
        $endsAt = $type->isTimed() ? CarbonImmutable::parse((string) $input['ends_at'], $edition->timezone)->utc() : null;

        return DB::transaction(function () use ($edition, $input, $actor, $slot, $type, $startsAt, $endsAt): FestivalScheduleSlot {
            $stage = FestivalStage::query()->whereKey($input['festival_stage_id'])->where('festival_edition_id', $edition->id)->where('account_id', $edition->account_id)->lockForUpdate()->firstOrFail();
            $entry = $type->requiresEntry()
                ? FestivalEntry::query()->with('portalUser')->whereKey($input['festival_entry_id'])->where('festival_edition_id', $edition->id)->where('account_id', $edition->account_id)->lockForUpdate()->firstOrFail()
                : null;
            $category = $type === FestivalScheduleSlotType::CategoryHeader
                ? FestivalCategory::query()->whereKey($input['festival_category_id'])->where('festival_edition_id', $edition->id)->where('account_id', $edition->account_id)->lockForUpdate()->firstOrFail()
                : null;
            $parent = filled($input['parent_id'] ?? null)
                ? FestivalScheduleSlot::query()->whereKey($input['parent_id'])->where('festival_stage_id', $stage->id)->where('festival_edition_id', $edition->id)->where('account_id', $edition->account_id)->lockForUpdate()->firstOrFail()
                : null;
            $slot = $slot?->exists ? FestivalScheduleSlot::query()->whereKey($slot->id)->where('festival_edition_id', $edition->id)->lockForUpdate()->firstOrFail() : new FestivalScheduleSlot;
            $wasExisting = $slot->exists;

            if ($entry && $entry->status !== FestivalEntryStatus::Accepted && (! $wasExisting || $slot->festival_entry_id !== $entry->id)) {
                throw ValidationException::withMessages(['festival_entry_id' => __('app.festival_performances_copy')]);
            }

            $before = $wasExisting ? $slot->only(['festival_stage_id', 'festival_entry_id', 'festival_category_id', 'parent_id', 'type', 'name', 'starts_at', 'ends_at', 'sort_order', 'published_at']) : [];
            $previousEntry = $wasExisting && $slot->festival_entry_id
                ? FestivalEntry::query()->with('portalUser')->whereKey($slot->festival_entry_id)->first()
                : null;
            $previouslyPublished = $wasExisting && $slot->published_at !== null;
            $descendants = new EloquentCollection;

            if ($wasExisting && $type->isHeader() && $slot->festival_stage_id !== $stage->id) {
                $editionSlots = FestivalScheduleSlot::query()
                    ->where('account_id', $edition->account_id)
                    ->where('festival_edition_id', $edition->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $descendants = $this->descendantsOf($slot, $editionSlots);
                $this->assertNoDestinationOverlap($slot, $stage, $descendants, $editionSlots);
                $descendants->loadMissing('entry.portalUser');
            }

            if ($parent && ! $parent->type->isHeader()) {
                throw ValidationException::withMessages(['parent_id' => __('app.festival_program_parent_must_be_header')]);
            }

            if ($parent && $this->parentCreatesCycle($slot, $parent)) {
                throw ValidationException::withMessages(['parent_id' => __('app.festival_program_hierarchy_cycle')]);
            }

            if ($wasExisting && ! $type->isHeader() && $slot->children()->exists()) {
                throw ValidationException::withMessages(['type' => __('app.festival_program_header_has_children')]);
            }

            if ($type->isTimed()) {
                $conflict = FestivalScheduleSlot::query()
                    ->where('festival_stage_id', $stage->id)
                    ->when($slot->exists, fn ($query) => $query->where('id', '!=', $slot->id))
                    ->whereNotNull('starts_at')
                    ->whereNotNull('ends_at')
                    ->where('starts_at', '<', $endsAt)
                    ->where('ends_at', '>', $startsAt)
                    ->lockForUpdate()
                    ->exists();
                if ($conflict) {
                    throw ValidationException::withMessages(['starts_at' => __('app.festival_schedule_overlap')]);
                }
            }

            $wasRescheduled = $wasExisting && ($slot->festival_stage_id !== $stage->id || ! $this->sameInstant($slot->starts_at, $startsAt) || ! $this->sameInstant($slot->ends_at, $endsAt));

            if ($wasRescheduled && blank($input['reschedule_reason'] ?? null)) {
                throw ValidationException::withMessages(['reschedule_reason' => __('app.festival_reschedule_reason_required')]);
            }

            $placementChanged = $wasExisting && ($slot->festival_stage_id !== $stage->id || $slot->parent_id !== $parent?->id);
            $sortOrder = $wasExisting && ! $placementChanged
                ? $slot->sort_order
                : ((int) FestivalScheduleSlot::query()
                    ->where('festival_stage_id', $stage->id)
                    ->where('parent_id', $parent?->id)
                    ->when($slot->exists, fn ($query) => $query->where('id', '!=', $slot->id))
                    ->max('sort_order')) + 10;

            $slot->fill([
                'account_id' => $edition->account_id,
                'festival_edition_id' => $edition->id,
                'festival_stage_id' => $stage->id,
                'festival_entry_id' => $entry?->id,
                'festival_category_id' => $category?->id,
                'parent_id' => $parent?->id,
                'type' => $type,
                'name' => in_array($type, [FestivalScheduleSlotType::Custom, FestivalScheduleSlotType::FreeHeader], true) ? $input['name'] : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'sort_order' => $sortOrder,
                'notes' => $input['notes'] ?? null,
                'published_at' => ($input['is_published'] ?? false) ? ($slot->published_at ?? now()) : null,
                'created_by' => $slot->created_by ?? $actor->id,
                'updated_by' => $actor->id,
                'reschedule_reason' => $input['reschedule_reason'] ?? null,
            ])->save();

            foreach ($descendants as $descendant) {
                $descendantBefore = $descendant->only(['festival_stage_id', 'festival_entry_id', 'festival_category_id', 'parent_id', 'type', 'name', 'starts_at', 'ends_at', 'sort_order', 'published_at']);
                $descendant->forceFill([
                    'festival_stage_id' => $stage->id,
                    'updated_by' => $actor->id,
                    'reschedule_reason' => $input['reschedule_reason'] ?? null,
                ])->save();

                $this->activity->record($descendant, 'schedule.rescheduled', $edition, $actor, [
                    'before' => $descendantBefore,
                    'after' => $descendant->only(['festival_stage_id', 'festival_entry_id', 'festival_category_id', 'parent_id', 'type', 'name', 'starts_at', 'ends_at', 'sort_order', 'published_at']),
                    'reason' => $descendant->reschedule_reason,
                ]);

                if ($descendant->entry && $descendant->published_at) {
                    $this->queueScheduleChangedNotification($descendant->entry, $edition, $descendant);
                }
            }

            $this->activity->record($slot, $wasRescheduled ? 'schedule.rescheduled' : ($wasExisting ? 'schedule.updated' : 'schedule.created'), $edition, $actor, [
                'before' => $before,
                'after' => $slot->only(['festival_stage_id', 'festival_entry_id', 'festival_category_id', 'parent_id', 'type', 'name', 'starts_at', 'ends_at', 'sort_order', 'published_at']),
                'reason' => $slot->reschedule_reason,
            ]);

            if ($previousEntry && $previouslyPublished && (! $entry || ! $previousEntry->is($entry))) {
                $this->queueScheduleChangedNotification($previousEntry, $edition, $slot);
            }

            if ($entry && $slot->published_at) {
                $this->notifications->queueForEntry(
                    $entry,
                    $wasExisting ? FestivalNotificationType::ScheduleChanged : FestivalNotificationType::SchedulePublished,
                    [
                        'entry_code' => $entry->code,
                        'entry_name' => $entry->entry_name,
                        'action_url' => route('festival.portal.entries.show', [$edition->account->slug, $entry]),
                    ],
                    $slot->updated_at->getTimestamp().':'.$slot->id,
                );
            }

            return $slot->refresh();
        }, 3);
    }

    private function sameInstant(mixed $current, ?CarbonImmutable $next): bool
    {
        if ($current === null || $next === null) {
            return $current === null && $next === null;
        }

        return $current->equalTo($next);
    }

    private function parentCreatesCycle(FestivalScheduleSlot $slot, FestivalScheduleSlot $parent): bool
    {
        if (! $slot->exists) {
            return false;
        }

        $current = $parent;

        while ($current) {
            if ($current->is($slot)) {
                return true;
            }

            $current = $current->parent;
        }

        return false;
    }

    /**
     * @param  EloquentCollection<int, FestivalScheduleSlot>  $editionSlots
     * @return EloquentCollection<int, FestivalScheduleSlot>
     */
    private function descendantsOf(FestivalScheduleSlot $slot, EloquentCollection $editionSlots): EloquentCollection
    {
        $descendantIds = [];
        $visitedIds = [$slot->id];
        $pendingParentIds = [$slot->id];

        while ($pendingParentIds !== []) {
            $children = $editionSlots->whereIn('parent_id', $pendingParentIds);

            if ($children->contains(fn (FestivalScheduleSlot $candidate): bool => in_array($candidate->id, $visitedIds, true))) {
                throw ValidationException::withMessages(['parent_id' => __('app.festival_program_hierarchy_cycle')]);
            }

            if ($children->contains(fn (FestivalScheduleSlot $candidate): bool => $candidate->festival_stage_id !== $slot->festival_stage_id)) {
                throw ValidationException::withMessages(['parent_id' => __('app.festival_program_hierarchy_invalid')]);
            }

            $pendingParentIds = $children->pluck('id')->all();
            array_push($descendantIds, ...$pendingParentIds);
            array_push($visitedIds, ...$pendingParentIds);
        }

        return $editionSlots->whereIn('id', $descendantIds)->values();
    }

    /**
     * @param  EloquentCollection<int, FestivalScheduleSlot>  $descendants
     * @param  EloquentCollection<int, FestivalScheduleSlot>  $editionSlots
     */
    private function assertNoDestinationOverlap(FestivalScheduleSlot $slot, FestivalStage $stage, EloquentCollection $descendants, EloquentCollection $editionSlots): void
    {
        $movingIds = [$slot->id, ...$descendants->pluck('id')->all()];
        $movingTimedSlots = $descendants->filter(fn (FestivalScheduleSlot $candidate): bool => $candidate->starts_at !== null && $candidate->ends_at !== null);
        $destinationTimedSlots = $editionSlots
            ->where('festival_stage_id', $stage->id)
            ->reject(fn (FestivalScheduleSlot $candidate): bool => in_array($candidate->id, $movingIds, true))
            ->filter(fn (FestivalScheduleSlot $candidate): bool => $candidate->starts_at !== null && $candidate->ends_at !== null);

        foreach ($movingTimedSlots as $movingSlot) {
            $hasOverlap = $destinationTimedSlots
                ->concat($movingTimedSlots->where('id', '!=', $movingSlot->id))
                ->contains(fn (FestivalScheduleSlot $destinationSlot): bool => $destinationSlot->starts_at->lt($movingSlot->ends_at)
                    && $destinationSlot->ends_at->gt($movingSlot->starts_at));

            if ($hasOverlap) {
                throw ValidationException::withMessages(['festival_stage_id' => __('app.festival_schedule_overlap')]);
            }
        }
    }

    private function queueScheduleChangedNotification(FestivalEntry $entry, FestivalEdition $edition, FestivalScheduleSlot $slot): void
    {
        $this->notifications->queueForEntry(
            $entry,
            FestivalNotificationType::ScheduleChanged,
            [
                'entry_code' => $entry->code,
                'entry_name' => $entry->entry_name,
                'action_url' => route('festival.portal.entries.show', [$edition->account->slug, $entry]),
            ],
            $slot->updated_at->getTimestamp().':'.$slot->id.':old',
        );
    }
}
