<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalNotificationType;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalStage;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        $startsAt = CarbonImmutable::parse((string) $input['starts_at'], $edition->timezone)->utc();
        $endsAt = CarbonImmutable::parse((string) $input['ends_at'], $edition->timezone)->utc();

        return DB::transaction(function () use ($edition, $input, $actor, $slot, $startsAt, $endsAt): FestivalScheduleSlot {
            $stage = FestivalStage::query()->whereKey($input['festival_stage_id'])->where('festival_edition_id', $edition->id)->where('account_id', $edition->account_id)->lockForUpdate()->firstOrFail();
            $entry = FestivalEntry::query()->with('portalUser')->whereKey($input['festival_entry_id'])->where('festival_edition_id', $edition->id)->where('account_id', $edition->account_id)->lockForUpdate()->firstOrFail();
            $slot = $slot?->exists ? FestivalScheduleSlot::query()->whereKey($slot->id)->where('festival_edition_id', $edition->id)->lockForUpdate()->firstOrFail() : new FestivalScheduleSlot;
            $wasExisting = $slot->exists;
            $before = $wasExisting ? $slot->only(['festival_stage_id', 'starts_at', 'ends_at']) : [];

            $conflict = FestivalScheduleSlot::query()
                ->where('festival_stage_id', $stage->id)
                ->when($slot->exists, fn ($query) => $query->where('id', '!=', $slot->id))
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->lockForUpdate()
                ->exists();
            if ($conflict) {
                throw ValidationException::withMessages(['starts_at' => __('app.festival_schedule_overlap')]);
            }

            if ($wasExisting && ($slot->starts_at->ne($startsAt) || $slot->ends_at->ne($endsAt) || $slot->festival_stage_id !== $stage->id) && blank($input['reschedule_reason'] ?? null)) {
                throw ValidationException::withMessages(['reschedule_reason' => __('app.festival_reschedule_reason_required')]);
            }

            $slot->fill([
                'account_id' => $edition->account_id,
                'festival_edition_id' => $edition->id,
                'festival_stage_id' => $stage->id,
                'festival_entry_id' => $entry->id,
                'type' => $input['type'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'notes' => $input['notes'] ?? null,
                'published_at' => ($input['is_published'] ?? false) ? ($slot->published_at ?? now()) : null,
                'created_by' => $slot->created_by ?? $actor->id,
                'updated_by' => $actor->id,
                'reschedule_reason' => $input['reschedule_reason'] ?? null,
            ])->save();

            $this->activity->record($slot, $wasExisting ? 'schedule.rescheduled' : 'schedule.created', $edition, $actor, [
                'before' => $before,
                'after' => $slot->only(['festival_stage_id', 'starts_at', 'ends_at']),
                'reason' => $slot->reschedule_reason,
            ]);

            if ($slot->published_at) {
                $this->notifications->queueForEntry(
                    $entry,
                    $wasExisting ? FestivalNotificationType::ScheduleChanged : FestivalNotificationType::SchedulePublished,
                    [
                        'subject' => __('app.festival_schedule_notification_subject'),
                        'lines' => [__('app.festival_schedule_notification_copy', ['entry' => $entry->entry_name])],
                        'action_url' => route('festival.portal.entries.show', [$edition->account->slug, $entry]),
                        'action_label' => __('app.festival_view_schedule'),
                    ],
                    $slot->updated_at->getTimestamp().':'.$slot->id,
                );
            }

            return $slot->refresh();
        }, 3);
    }
}
