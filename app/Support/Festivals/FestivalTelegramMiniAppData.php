<?php

namespace App\Support\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalScheduleSlotType;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalMedia;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRubric;
use App\Models\FestivalRubricSection;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalSeries;
use App\Models\FestivalTimeline;
use App\Models\TelegramChatAuthorization;
use App\Support\StudioRulesHtmlSanitizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FestivalTelegramMiniAppData
{
    public function __construct(
        private readonly FestivalTimelinePresenter $timelinePresenter,
        private readonly FestivalTelegramAuthorizationResolver $authorizations,
        private readonly StudioRulesHtmlSanitizer $htmlSanitizer,
        private readonly FestivalCategoryLimitsPresenter $categoryLimits,
        private readonly FestivalProgramOrder $programOrder,
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
                'sections' => fn ($query) => $query->where('visibility', 'public')->where('is_active', true),
                'documents' => fn ($query) => $query->where('visibility', 'public')->where('is_active', true),
                'results' => fn ($query) => $query->whereNotNull('published_at')->with('entry.category'),
                'categories' => fn ($query) => $query
                    ->where('is_active', true)
                    ->whereHas('direction', fn ($query) => $query->where('is_active', true))
                    ->with('direction'),
                'festivalRubrics' => fn ($query) => $query
                    ->where('is_active', true)
                    ->with(['category', 'sections.criteria']),
                'scheduleSlots' => fn ($query) => $query
                    ->whereNotNull('published_at')
                    ->whereHas('stage', fn ($query) => $query->where('is_active', true))
                    ->with(['stage', 'entry', 'category']),
            ])
            ->orderByDesc('starts_at')
            ->get();
        $timelines = $this->timelinesForEditions($editions);

        $payload = [
            'series' => [
                'id' => $series->id,
                'name' => $series->name,
                'organizer' => $series->organizer_name,
                'phone' => $series->organizer_phone,
                'email' => $series->organizer_email,
                'telegram_url' => $series->organizer_telegram_url,
                'instagram_url' => $series->organizer_instagram_url,
                'brand_color' => $series->brand_color,
            ],
            'hero' => $this->hero($editions),
            'editions' => $editions->map(fn (FestivalEdition $edition): array => $this->edition(
                $edition,
                $timelines->get($edition->id, []),
            ))->all(),
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

    /** @return array<int, array<string, mixed>> */
    public function timelineUpdates(FestivalSeries $series): array
    {
        $editions = FestivalEdition::query()
            ->where('account_id', $series->account_id)
            ->where('festival_series_id', $series->id)
            ->published()
            ->get(['id', 'account_id', 'festival_series_id', 'status', 'starts_at', 'ends_at', 'timezone']);
        $timelines = $this->timelinesForEditions($editions);

        return $editions->map(fn (FestivalEdition $edition): array => [
            'id' => $edition->id,
            'status' => $edition->status->value,
            'period' => $this->period($edition),
            'timeline' => $timelines->get($edition->id, []),
        ])->all();
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
    private function edition(FestivalEdition $edition, array $timeline): array
    {
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
            'thumbnail_url' => $this->imageUrl($edition->mobileCoverMedia) ?? $this->imageUrl($edition->coverMedia),
            'thumbnail_alt' => $edition->mobileCoverMedia?->alt_text ?: ($edition->coverMedia?->alt_text ?: $edition->title),
            'public_url' => route('public.festivals.show', [$edition->account->slug, $edition->slug]),
            'description_html' => $this->htmlSanitizer->sanitize($edition->description_html),
            'rules_html' => $this->htmlSanitizer->sanitize($edition->rules_html),
            'sections' => $edition->sections->map(fn ($section): array => [
                'id' => $section->id,
                'title' => $section->title,
                'body_html' => $this->htmlSanitizer->sanitize($section->body_html),
            ])->filter(fn (array $section): bool => filled($section['body_html']))->values()->all(),
            'category_groups' => $this->categoryGroups($edition),
            'rubrics' => $this->rubrics($edition),
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
            'program' => $this->program($edition),
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

    /** @return array<int, array<string, mixed>> */
    private function categoryGroups(FestivalEdition $edition): array
    {
        return $edition->categories
            ->sortBy(fn (FestivalCategory $category): string => sprintf(
                '%010d:%020d:%010d:%020d',
                $category->direction->sort_order,
                $category->direction->id,
                $category->sort_order,
                $category->id,
            ))
            ->groupBy('festival_direction_id')
            ->map(function (Collection $categories): array {
                $direction = $categories->first()->direction;

                return [
                    'name' => $direction->name,
                    'categories' => $categories->map(function (FestivalCategory $category): array {
                        return [
                            'name' => $category->name,
                            'format' => __('app.festival_competition_format_'.$category->competition_format->value),
                            'limits' => array_values(array_filter($this->categoryLimits->present($category))),
                            'registration_closes_at' => $category->registration_closes_at?->toIso8601String(),
                            'requirements_html' => $this->htmlSanitizer->sanitize($category->requirements_html),
                        ];
                    })->all(),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function rubrics(FestivalEdition $edition): array
    {
        $publicCategoryIds = $edition->categories->modelKeys();

        return $edition->festivalRubrics
            ->filter(fn (FestivalRubric $rubric): bool => $rubric->festival_category_id === null
                || in_array($rubric->festival_category_id, $publicCategoryIds, true))
            ->map(fn (FestivalRubric $rubric): array => [
                'name' => $rubric->name,
                'category' => $rubric->category?->name,
                'sections' => $rubric->sections->map(fn (FestivalRubricSection $section): array => [
                    'name' => $section->name,
                    'contribution' => $section->contribution->value,
                    'weight' => (float) $section->weight,
                    'criteria' => $section->criteria->map(fn ($criterion): array => [
                        'name' => $criterion->name,
                        'max_score' => (float) $criterion->max_score,
                        'weight' => (float) $criterion->weight,
                    ])->all(),
                ])->all(),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function program(FestivalEdition $edition): array
    {
        return $edition->scheduleSlots
            ->groupBy('festival_stage_id')
            ->sortBy(fn (Collection $slots): string => sprintf(
                '%010d:%020d',
                $slots->first()->stage->sort_order,
                $slots->first()->stage->id,
            ))
            ->map(function (Collection $slots): array {
                $publicSlots = $slots->filter(fn (FestivalScheduleSlot $slot): bool => $slot->type->isHeader() || $slot->hasTimeRange());

                return [
                    'stage' => $slots->first()->stage->name,
                    'items' => collect($this->programOrder->tree($publicSlots))
                        ->map(fn (array $node): array => $this->programNode($node))
                        ->all(),
                ];
            })
            ->filter(fn (array $stage): bool => $stage['items'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array{item: FestivalScheduleSlot, children: array}  $node
     * @return array<string, mixed>
     */
    private function programNode(array $node): array
    {
        $slot = $node['item'];

        return [
            'name' => $slot->displayName(),
            'type' => $slot->type->value,
            'type_label' => __('app.festival_schedule_slot_type_'.$slot->type->value),
            'category' => $slot->type === FestivalScheduleSlotType::Performance ? $slot->category?->name : null,
            'starts_at' => $slot->starts_at?->toIso8601String(),
            'ends_at' => $slot->ends_at?->toIso8601String(),
            'children' => collect($node['children'])
                ->map(fn (array $child): array => $this->programNode($child))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function registrant(FestivalSeries $series, FestivalPortalUser $registrant): array
    {
        $participants = $registrant->participants()->active()->performers()->orderBy('last_name')->orderBy('first_name')->get();
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
                'member_type' => $participant->member_type->value,
            ])->all(),
            'entries' => $entries->map(fn ($entry): array => [
                'id' => $entry->id,
                'code' => $entry->code,
                'name' => $entry->entry_name,
                'edition_id' => $entry->festival_edition_id,
                'edition' => $entry->edition->title,
                'category' => $entry->category?->name,
                'status' => $entry->status->value,
            ])->all(),
            'statistics' => [
                'applications' => $entries->filter(fn ($entry): bool => in_array($entry->status->value, $countedStatuses, true))->count(),
                'accepted' => $entries->where('status', FestivalEntryStatus::Accepted)->count(),
                'participants' => $participantCount,
            ],
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

    /**
     * @param  Collection<int, FestivalEdition>  $editions
     * @return \Illuminate\Support\Collection<int, array<int, array<string, mixed>>>
     */
    private function timelinesForEditions(Collection $editions): \Illuminate\Support\Collection
    {
        $editionIds = $editions
            ->filter(fn (FestivalEdition $edition): bool => $edition->starts_at !== null
                && $edition->ends_at !== null
                && $this->timelinePresenter->isWithinLocalDates($edition))
            ->modelKeys();

        if ($editionIds === []) {
            return collect();
        }

        $timelines = FestivalTimeline::query()
            ->whereIn('festival_edition_id', $editionIds)
            ->whereNotNull('started_at')
            ->whereHas('stage', fn ($query) => $query->where('is_active', true))
            ->with(['stage', 'edition', 'items', 'activeItem', 'lastFinishedItem'])
            ->get();

        return $timelines
            ->groupBy('festival_edition_id')
            ->map(fn (Collection $editionTimelines): array => $this->timelinePresenter
                ->scenes($editionTimelines, true)
                ->map(fn (array $scene): array => $this->publicTimelineScene($scene))
                ->all());
    }

    /** @return array<string, mixed> */
    private function publicTimelineScene(array $scene): array
    {
        return [
            'scene_name' => $scene['scene_name'],
            'paused' => $scene['paused'],
            'completed' => $scene['completed'],
            'state' => $scene['state'],
            'next_label' => $scene['next_label'],
            'next_transition_iso' => $scene['next_transition_iso'],
            'timezone' => $scene['timezone'],
            'items' => collect($scene['items'])->map(fn (array $item): array => [
                'label' => $item['label'],
                'type' => $item['type'],
                'type_label' => $item['type_label'],
                'duration_label' => $item['duration_label'],
                'starts_at_iso' => $item['starts_at_iso'],
                'ends_at_iso' => $item['ends_at_iso'],
                'status' => $item['status'],
            ])->all(),
        ];
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
