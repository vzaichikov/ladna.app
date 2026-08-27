<?php

namespace App\Support\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalPortalRole;
use App\Models\FestivalEdition;
use App\Models\FestivalMedia;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\FestivalTimeline;
use App\Models\TelegramChatAuthorization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FestivalTelegramMiniAppData
{
    public function __construct(
        private readonly FestivalTimelinePresenter $timelinePresenter,
        private readonly FestivalTelegramAuthorizationResolver $authorizations,
    ) {}

    /** @return array<string, mixed> */
    public function build(FestivalSeries $series, ?TelegramChatAuthorization $authorization = null): array
    {
        $series->loadMissing('account');
        $editions = FestivalEdition::query()
            ->where('account_id', $series->account_id)
            ->where('festival_series_id', $series->id)
            ->published()
            ->with([
                'account:id,slug',
                'coverMedia',
                'mobileCoverMedia',
                'documents' => fn ($query) => $query->where('visibility', 'public')->where('is_active', true),
                'results' => fn ($query) => $query->whereNotNull('published_at')->with('entry.category'),
                'scheduleSlots' => fn ($query) => $query->whereNotNull('published_at')->with(['stage', 'entry', 'category']),
            ])
            ->orderByDesc('starts_at')
            ->get();

        $payload = [
            'series' => [
                'id' => $series->id,
                'name' => $series->name,
                'summary' => $series->summary,
                'organizer' => $series->organizer_name,
                'phone' => $series->organizer_phone,
                'email' => $series->organizer_email,
                'telegram_url' => $series->organizer_telegram_url,
                'instagram_url' => $series->organizer_instagram_url,
                'brand_color' => $series->brand_color,
            ],
            'hero' => $this->hero($editions),
            'editions' => $editions->map(fn (FestivalEdition $edition): array => $this->edition($edition))->all(),
            'authorized' => $authorization !== null,
        ];

        if (! $authorization) {
            return $payload;
        }

        $registrant = $this->authorizations->linkedPortalUser($authorization, FestivalPortalRole::Registrant);
        $guest = $this->authorizations->linkedPortalUser($authorization, FestivalPortalRole::Guest);

        return [
            ...$payload,
            'identity' => [
                'telegram_user_id' => $authorization->telegram_user_id,
                'phone' => $authorization->phone,
                'registrant_linked' => $registrant !== null,
                'guest_linked' => $guest !== null,
            ],
            'registrant' => $registrant ? $this->registrant($series, $registrant) : null,
            'guest' => $guest ? $this->guest($series, $guest) : null,
        ];
    }

    /**
     * @param  Collection<int, FestivalEdition>  $editions
     * @return array<string, mixed>|null
     */
    private function hero(Collection $editions): ?array
    {
        $now = now();
        $withHero = $editions->filter(fn (FestivalEdition $edition): bool => $this->heroMedia($edition) !== null);
        $edition = $withHero
            ->filter(fn (FestivalEdition $edition): bool => $edition->starts_at?->lte($now) === true && $edition->ends_at?->gte($now) === true)
            ->sortBy('starts_at')
            ->first()
            ?? $withHero
                ->filter(fn (FestivalEdition $edition): bool => $edition->starts_at?->gt($now) === true)
                ->sortBy('starts_at')
                ->first()
            ?? $withHero->sortByDesc('starts_at')->first();

        if (! $edition instanceof FestivalEdition) {
            return null;
        }

        $desktop = $this->imageUrl($edition->coverMedia);
        $mobile = $this->imageUrl($edition->mobileCoverMedia);
        $fallback = $edition->coverMedia && $desktop ? $edition->coverMedia : $edition->mobileCoverMedia;

        return [
            'edition_id' => $edition->id,
            'title' => $edition->title,
            'period' => $this->period($edition),
            'desktop_url' => $desktop ?? $mobile,
            'mobile_url' => $mobile ?? $desktop,
            'alt' => $fallback?->alt_text ?: $edition->title,
        ];
    }

    private function heroMedia(FestivalEdition $edition): ?FestivalMedia
    {
        return $this->imageUrl($edition->coverMedia) ? $edition->coverMedia : ($this->imageUrl($edition->mobileCoverMedia) ? $edition->mobileCoverMedia : null);
    }

    private function imageUrl(?FestivalMedia $media): ?string
    {
        if (! $media || $media->kind !== 'image') {
            return null;
        }

        return $media->url();
    }

    /** @return array<string, mixed> */
    private function edition(FestivalEdition $edition): array
    {
        $timeline = $this->timeline($edition);

        return [
            'id' => $edition->id,
            'slug' => $edition->slug,
            'title' => $edition->title,
            'summary' => $edition->summary,
            'status' => $edition->status->value,
            'registration_status' => $edition->registration_status->value,
            'registration_open' => $edition->registrationIsOpen(),
            'period' => $this->period($edition),
            'starts_at' => $edition->starts_at?->toIso8601String(),
            'ends_at' => $edition->ends_at?->toIso8601String(),
            'timezone' => $edition->timezone,
            'venue_name' => $edition->venue_name,
            'venue_address' => $edition->venue_address,
            'public_url' => route('public.festivals.show', [$edition->account->slug, $edition->slug]),
            'documents' => $edition->documents->map(fn ($document): array => [
                'id' => $document->id,
                'title' => $document->title,
                'kind' => $document->kind,
                'url' => route('public.festival-documents.download', [$edition->account->slug, $document]),
            ])->all(),
            'schedule' => $edition->scheduleSlots->map(fn ($slot): array => [
                'id' => $slot->id,
                'name' => $slot->displayName(),
                'stage' => $slot->stage?->name,
                'starts_at' => $slot->starts_at?->toIso8601String(),
                'ends_at' => $slot->ends_at?->toIso8601String(),
            ])->all(),
            'results' => $edition->results->map(fn ($result): array => [
                'id' => $result->id,
                'entry_name' => $result->entry?->entry_name,
                'category' => $result->entry?->category?->name,
                'rank' => $result->rank,
                'medal' => $result->medal,
                'score' => $result->total_score,
            ])->all(),
            'timeline' => $timeline,
        ];
    }

    /** @return array<string, mixed> */
    private function registrant(FestivalSeries $series, FestivalPortalUser $registrant): array
    {
        $participants = $registrant->participants()->whereNull('archived_at')->orderBy('last_name')->orderBy('first_name')->get();
        $entries = $registrant->entries()
            ->whereHas('edition', fn ($query) => $query->where('festival_series_id', $series->id))
            ->with(['edition:id,title,slug,festival_series_id', 'category:id,name'])
            ->latest('id')
            ->get();
        $countedStatuses = [
            FestivalEntryStatus::Submitted->value,
            FestivalEntryStatus::UnderReview->value,
            FestivalEntryStatus::Accepted->value,
        ];
        $participantCount = (int) DB::table('festival_entry_participant')
            ->join('festival_entries', 'festival_entries.id', '=', 'festival_entry_participant.festival_entry_id')
            ->join('festival_editions', 'festival_editions.id', '=', 'festival_entries.festival_edition_id')
            ->where('festival_entries.festival_portal_user_id', $registrant->id)
            ->where('festival_editions.festival_series_id', $series->id)
            ->whereIn('festival_entries.status', $countedStatuses)
            ->distinct('festival_entry_participant.festival_participant_id')
            ->count('festival_entry_participant.festival_participant_id');

        return [
            'id' => $registrant->id,
            'name' => $registrant->displayName(),
            'profile_complete' => $registrant->profileIsComplete(),
            'participants' => $participants->map(fn ($participant): array => [
                'id' => $participant->id,
                'name' => trim($participant->first_name.' '.$participant->last_name),
            ])->all(),
            'entries' => $entries->map(fn ($entry): array => [
                'id' => $entry->id,
                'code' => $entry->code,
                'name' => $entry->entry_name,
                'edition' => $entry->edition->title,
                'category' => $entry->category?->name,
                'status' => $entry->status->value,
            ])->all(),
            'statistics' => [
                'applications' => $entries->filter(fn ($entry): bool => in_array($entry->status->value, $countedStatuses, true))->count(),
                'accepted' => $entries->where('status', FestivalEntryStatus::Accepted)->count(),
                'participants' => $participantCount,
            ],
            'preferences' => $this->preferences($registrant),
        ];
    }

    /** @return array<string, mixed> */
    private function guest(FestivalSeries $series, FestivalPortalUser $guest): array
    {
        $orders = $guest->ticketOrders()
            ->whereHas('edition', fn ($query) => $query->where('festival_series_id', $series->id))
            ->with(['edition:id,title,festival_series_id', 'tickets.streamEntitlement'])
            ->latest('id')
            ->get();

        return [
            'id' => $guest->id,
            'orders' => $orders->map(fn ($order): array => [
                'id' => $order->id,
                'order_id' => $order->order_id,
                'edition' => $order->edition->title,
                'status' => $order->status->value,
                'amount_cents' => $order->amount_cents,
                'currency' => $order->currency,
                'tickets_count' => $order->tickets->count(),
                'streaming' => $order->tickets->contains(fn ($ticket): bool => $ticket->streamEntitlement !== null),
            ])->all(),
        ];
    }

    /** @return array<string, bool> */
    private function preferences(FestivalPortalUser $registrant): array
    {
        $stored = $registrant->notificationPreferences()->pluck('is_enabled', 'type');

        return collect(FestivalNotificationType::cases())
            ->filter(fn (FestivalNotificationType $type): bool => $type->isOptional())
            ->mapWithKeys(fn (FestivalNotificationType $type): array => [
                $type->value => (bool) $stored->get($type->value, false),
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function timeline(FestivalEdition $edition): array
    {
        if (! $edition->starts_at || ! $edition->ends_at || ! $this->timelinePresenter->isWithinLocalDates($edition)) {
            return [];
        }

        $timelines = FestivalTimeline::query()
            ->where('account_id', $edition->account_id)
            ->where('festival_edition_id', $edition->id)
            ->whereNotNull('started_at')
            ->whereHas('stage', fn ($query) => $query->where('is_active', true))
            ->with(['stage', 'edition', 'items', 'activeItem', 'lastFinishedItem'])
            ->get();

        return $this->timelinePresenter->scenes($timelines, true)
            ->map(function (array $scene): array {
                $scene['items'] = collect($scene['items'])->map(function (array $item): array {
                    unset($item['model']);

                    return $item;
                })->all();

                return $scene;
            })->all();
    }

    private function period(FestivalEdition $edition): string
    {
        if ($edition->starts_at?->isFuture()) {
            return 'upcoming';
        }

        if ($edition->ends_at?->isPast()) {
            return 'previous';
        }

        return 'live';
    }
}
