<?php

namespace App\Actions;

use App\Enums\EventStatus;
use App\Enums\EventVenueKind;
use App\Models\Account;
use App\Models\Event;
use App\Support\ScheduleOccupancy;
use App\Support\SlugGenerator;
use App\Support\StudioRulesHtmlSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveEvent
{
    public function __construct(
        private readonly StudioRulesHtmlSanitizer $htmlSanitizer,
        private readonly ScheduleOccupancy $scheduleOccupancy,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(Account $account, array $input, ?Event $event = null): Event
    {
        return DB::transaction(function () use ($account, $input, $event): Event {
            $this->scheduleOccupancy->lockAccount($account);
            $event = $event
                ? Event::query()->whereBelongsTo($account)->whereKey($event->id)->lockForUpdate()->firstOrFail()
                : new Event(['account_id' => $account->id, 'currency' => $account->default_currency]);
            $timezone = (string) $input['timezone'];
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $input['starts_at'], $timezone)->utc();
            $endsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $input['ends_at'], $timezone)->utc();
            $roomIds = collect($input['room_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->sort()->values();

            if ($event->isPublished() && ($startsAt->isPast() || $endsAt->lessThanOrEqualTo($startsAt))) {
                throw ValidationException::withMessages(['starts_at' => __('app.event_future_dates_required')]);
            }

            $materialChange = $event->exists && $event->isPublished() && (
                ! $event->starts_at?->equalTo($startsAt)
                || ! $event->ends_at?->equalTo($endsAt)
                || $event->venue_kind->value !== $input['venue_kind']
                || (int) $event->location_id !== (int) ($input['location_id'] ?? 0)
                || $event->rooms()->pluck('rooms.id')->sort()->values()->all() !== $roomIds->all()
                || $event->external_venue_name !== ($input['external_venue_name'] ?? null)
                || $event->external_address !== ($input['external_address'] ?? null)
            );

            if ($materialChange && ! (bool) ($input['confirm_material_change'] ?? false)) {
                throw ValidationException::withMessages([
                    'confirm_material_change' => __('app.event_material_change_confirmation_required'),
                ]);
            }

            if ($event->isPublished() && $input['venue_kind'] === EventVenueKind::Studio->value) {
                foreach ($roomIds as $roomId) {
                    $this->scheduleOccupancy->lockResources($account, $roomId, []);
                }

                $this->assertNoRoomConflicts($account, $roomIds->all(), $startsAt, $endsAt, $event->id);
            }

            $slugSource = filled($input['slug'] ?? null) ? (string) $input['slug'] : (string) $input['title'];
            $slug = SlugGenerator::unique($slugSource, 'event', fn (string $candidate): bool => $account->events()
                ->where('slug', $candidate)
                ->when($event->exists, fn ($query) => $query->whereKeyNot($event->id))
                ->exists());

            $event->fill([
                ...Arr::only($input, [
                    'title', 'summary', 'venue_kind', 'external_venue_name', 'external_address',
                    'external_map_url', 'external_directions', 'timezone', 'capacity',
                ]),
                'slug' => $slug,
                'location_id' => $input['venue_kind'] === EventVenueKind::Studio->value ? $input['location_id'] : null,
                'description_html' => $this->htmlSanitizer->sanitize($input['description_html'] ?? null),
                'rules_html' => $this->htmlSanitizer->sanitize($input['rules_html'] ?? null),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'external_venue_name' => $input['venue_kind'] === EventVenueKind::External->value ? $input['external_venue_name'] : null,
                'external_address' => $input['venue_kind'] === EventVenueKind::External->value ? $input['external_address'] : null,
                'external_map_url' => $input['venue_kind'] === EventVenueKind::External->value ? ($input['external_map_url'] ?? null) : null,
                'external_directions' => $input['venue_kind'] === EventVenueKind::External->value ? ($input['external_directions'] ?? null) : null,
            ])->save();

            $event->rooms()->syncWithPivotValues(
                $input['venue_kind'] === EventVenueKind::Studio->value ? $roomIds->all() : [],
                ['account_id' => $account->id],
            );

            return $event->refresh();
        }, 3);
    }

    public function publish(Account $account, Event $event): Event
    {
        return DB::transaction(function () use ($account, $event): Event {
            $this->scheduleOccupancy->lockAccount($account);
            $event = Event::query()->whereBelongsTo($account)->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $event->load('rooms');

            if ($event->status !== EventStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('app.event_publish_draft_only')]);
            }

            if ($event->starts_at->isPast() || $event->ends_at->lessThanOrEqualTo($event->starts_at)) {
                throw ValidationException::withMessages(['starts_at' => __('app.event_future_dates_required')]);
            }

            if (! $event->ticketTypes()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['tickets' => __('app.event_ticket_type_required')]);
            }

            if ($event->venue_kind === EventVenueKind::Studio && ($event->location_id === null || $event->rooms->isEmpty())) {
                throw ValidationException::withMessages(['room_ids' => __('app.event_room_required')]);
            }

            foreach ($event->rooms->modelKeys() as $roomId) {
                $this->scheduleOccupancy->lockResources($account, $roomId, []);
            }

            $this->assertNoRoomConflicts($account, $event->rooms->modelKeys(), $event->starts_at, $event->ends_at, $event->id);
            $event->forceFill([
                'status' => EventStatus::Published,
                'published_at' => $event->published_at ?? now(),
            ])->save();

            return $event->refresh();
        }, 3);
    }

    /**
     * @param  array<int, int>  $roomIds
     */
    private function assertNoRoomConflicts(Account $account, array $roomIds, mixed $startsAt, mixed $endsAt, ?int $eventId): void
    {
        foreach ($roomIds as $roomId) {
            $scheduledConflict = $account->scheduledClasses()
                ->where('status', 'scheduled')
                ->where('room_id', $roomId)
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();

            if ($scheduledConflict || $this->scheduleOccupancy->hasEventRoomConflict($account, $roomId, $startsAt, $endsAt, $eventId)) {
                throw ValidationException::withMessages(['starts_at' => __('app.event_room_conflict')]);
            }
        }
    }
}
