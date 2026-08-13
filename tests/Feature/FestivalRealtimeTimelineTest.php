<?php

namespace Tests\Feature;

use App\Actions\Festivals\ActivateFestivalTimelineItem;
use App\Actions\Festivals\AdvanceFestivalTimeline;
use App\Actions\Festivals\FillFestivalTimelines;
use App\Actions\Festivals\PauseFestivalTimeline;
use App\Actions\Festivals\ReorderFestivalTimeline;
use App\Actions\Festivals\ResumeFestivalTimeline;
use App\Actions\Festivals\SaveFestivalEdition;
use App\Actions\Festivals\StartFestivalTimelines;
use App\Actions\Festivals\ToggleFestivalTimelineItem;
use App\Enums\FestivalEditionStatus;
use App\Jobs\AdvanceFestivalTimelineJob;
use App\Models\Account;
use App\Models\FestivalActivityLog;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalPortalUser;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\FestivalTimeline;
use App\Models\FestivalTimelineItem;
use App\Models\User;
use App\Support\Festivals\FestivalTimelinePresenter;
use App\Support\ScheduledTaskRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FestivalRealtimeTimelineTest extends TestCase
{
    use DatabaseTransactions;

    public function test_fill_copies_all_timed_program_items_in_visible_tree_order_without_mutating_program(): void
    {
        [$account, $edition, $owner, $stage, $category, $entry] = $this->festival();
        $inactiveStage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'is_active' => false]);
        $base = CarbonImmutable::parse('2030-06-10 09:00:00', $edition->timezone)->utc();
        $header = $this->slot($edition, $stage, ['type' => 'free_header', 'name' => 'Morning', 'sort_order' => 10]);
        $nestedPerformance = $this->slot($edition, $stage, [
            'festival_entry_id' => $entry->id,
            'type' => 'performance',
            'parent_id' => $header->id,
            'starts_at' => $base,
            'ends_at' => $base->addMinutes(4),
            'notes' => 'Internal performance note',
            'published_at' => null,
            'sort_order' => 10,
        ]);
        $rehearsal = $this->slot($edition, $stage, [
            'festival_entry_id' => $entry->id,
            'type' => 'rehearsal',
            'starts_at' => $base->addMinutes(4),
            'ends_at' => $base->addMinutes(7),
            'sort_order' => 20,
        ]);
        $custom = $this->slot($edition, $stage, [
            'type' => 'custom',
            'name' => 'Coffee',
            'starts_at' => $base->addMinutes(7),
            'ends_at' => $base->addMinutes(12),
            'published_at' => now(),
            'sort_order' => 30,
        ]);
        $this->slot($edition, $stage, ['type' => 'category_header', 'festival_category_id' => $category->id, 'sort_order' => 40]);
        $this->slot($edition, $inactiveStage, [
            'type' => 'custom',
            'name' => 'Inactive sentinel',
            'starts_at' => $base,
            'ends_at' => $base->addMinute(),
        ]);
        $programBefore = FestivalScheduleSlot::query()->where('festival_edition_id', $edition->id)->get()->map->getAttributes()->all();

        app(FillFestivalTimelines::class)->execute($edition, $owner);

        $timeline = FestivalTimeline::query()->with('items')->sole();
        $this->assertSame($stage->id, $timeline->festival_stage_id);
        $this->assertSame([$nestedPerformance->id, $rehearsal->id, $custom->id], $timeline->items->pluck('festival_schedule_slot_id')->all());
        $this->assertSame(['performance', 'rehearsal', 'custom'], $timeline->items->pluck('type')->map->value->all());
        $this->assertSame([240, 180, 300], $timeline->items->pluck('duration_seconds')->all());
        $this->assertSame($entry->code, $timeline->items->first()->entry_reference);
        $this->assertSame('Internal performance note', $timeline->items->first()->notes);
        $this->assertTrue($timeline->items->every->is_enabled);
        $this->assertSame($programBefore, FestivalScheduleSlot::query()->where('festival_edition_id', $edition->id)->get()->map->getAttributes()->all());
        $this->assertDatabaseHas('festival_activity_logs', ['action' => 'timeline.filled', 'festival_edition_id' => $edition->id]);

        $custom->update(['name' => 'Updated coffee']);
        app(FillFestivalTimelines::class)->execute($edition, $owner);
        $this->assertSame(1, FestivalTimeline::query()->count());
        $this->assertSame(3, FestivalTimelineItem::query()->count());
        $this->assertDatabaseHas('festival_timeline_items', ['festival_schedule_slot_id' => $custom->id, 'label' => 'Updated coffee']);
    }

    public function test_start_is_date_gated_preserves_gaps_transitions_status_and_rejects_refill(): void
    {
        Queue::fake();
        [, $edition, $owner, $stage] = $this->festival();
        $now = CarbonImmutable::parse('2030-06-10 10:05:00', $edition->timezone)->utc();
        $this->travelTo($now);
        $first = $this->slot($edition, $stage, ['type' => 'custom', 'name' => 'First', 'starts_at' => $now->subMinutes(5), 'ends_at' => $now->addMinutes(5), 'sort_order' => 10]);
        $second = $this->slot($edition, $stage, ['type' => 'custom', 'name' => 'After gap', 'starts_at' => $now->addMinutes(15), 'ends_at' => $now->addMinutes(20), 'sort_order' => 20]);
        app(FillFestivalTimelines::class)->execute($edition, $owner);
        $importedTimes = FestivalTimelineItem::query()->pluck('planned_ends_at', 'festival_schedule_slot_id')->map->toISOString()->all();

        app(StartFestivalTimelines::class)->execute($edition, $owner);

        $timeline = FestivalTimeline::query()->firstOrFail();
        $this->assertSame(FestivalEditionStatus::InProgress, $edition->refresh()->status);
        $this->assertSame($first->id, $timeline->activeItem->festival_schedule_slot_id);
        $this->assertSame($importedTimes, FestivalTimelineItem::query()->pluck('planned_ends_at', 'festival_schedule_slot_id')->map->toISOString()->all());
        Queue::assertPushed(AdvanceFestivalTimelineJob::class, fn (AdvanceFestivalTimelineJob $job): bool => $job->timelineId === $timeline->id
            && $job->expectedActiveItemId === $timeline->active_item_id
            && $job->expectedTransitionTimestamp === $timeline->next_transition_at->getTimestamp());
        $this->expectValidation('timeline', fn () => app(FillFestivalTimelines::class)->execute($edition, $owner));

        Queue::fake();
        $this->travelTo($now->addMinutes(6));
        $timeline->refresh();
        app(AdvanceFestivalTimeline::class)->execute($timeline->id, $timeline->active_item_id, $timeline->last_finished_item_id, $timeline->next_transition_at->getTimestamp());
        $timeline->refresh();
        $this->assertNull($timeline->active_item_id);
        $this->assertSame($first->id, $timeline->lastFinishedItem->festival_schedule_slot_id);
        $this->assertSame($second->id, FestivalTimelineItem::query()->findOrFail($timeline->items()->where('festival_schedule_slot_id', $second->id)->value('id'))->festival_schedule_slot_id);
        Queue::assertPushed(AdvanceFestivalTimelineJob::class, fn (AdvanceFestivalTimelineJob $job): bool => $job->expectedActiveItemId === null);
    }

    public function test_operator_can_restart_move_backwards_reorder_toggle_pause_and_resume(): void
    {
        Queue::fake();
        [$account, $edition, $owner, $stage] = $this->festival();
        $now = CarbonImmutable::parse('2030-06-10 10:00:00', $edition->timezone)->utc();
        $this->travelTo($now);
        foreach (['One', 'Two', 'Three', 'Four'] as $index => $label) {
            $start = $now->addMinutes($index * 5);
            $this->slot($edition, $stage, ['type' => 'custom', 'name' => $label, 'starts_at' => $start, 'ends_at' => $start->addMinutes(5), 'sort_order' => ($index + 1) * 10]);
        }
        app(FillFestivalTimelines::class)->execute($edition, $owner);
        app(StartFestivalTimelines::class)->execute($edition, $owner);
        $timeline = FestivalTimeline::query()->with('items')->firstOrFail();
        $items = $timeline->items;

        $this->travelTo($now->addMinute());
        app(ActivateFestivalTimelineItem::class)->execute($timeline, $items[2], $owner);
        $timeline->refresh();
        $items = $timeline->items()->get();
        $this->assertSame($items[2]->id, $timeline->active_item_id);
        $this->assertSame($now->addMinutes(6)->toISOString(), $items[2]->planned_ends_at->toISOString());
        $this->assertSame($items[2]->planned_ends_at->toISOString(), $items[3]->planned_starts_at->toISOString());

        $this->travelTo($now->addMinutes(2));
        app(ActivateFestivalTimelineItem::class)->execute($timeline, $items[2], $owner);
        $this->assertSame($now->addMinutes(7)->toISOString(), $items[2]->refresh()->planned_ends_at->toISOString());
        app(ActivateFestivalTimelineItem::class)->execute($timeline, $items[0], $owner);
        $this->assertSame($items[0]->id, $timeline->refresh()->active_item_id);

        app(ReorderFestivalTimeline::class)->execute($timeline, [$items[3]->id, $items[0]->id, $items[1]->id, $items[2]->id], $owner);
        $presented = app(FestivalTimelinePresenter::class)->scene($timeline->fresh(['stage', 'edition', 'items', 'activeItem', 'lastFinishedItem']));
        $this->assertSame('passed', collect($presented['items'])->firstWhere('id', $items[3]->id)['status']);

        app(ToggleFestivalTimelineItem::class)->execute($timeline, $items[0], $owner);
        $this->assertFalse($items[0]->refresh()->is_enabled);
        $this->assertSame($items[1]->id, $timeline->refresh()->active_item_id);
        app(ToggleFestivalTimelineItem::class)->execute($timeline, $items[0], $owner);
        $this->assertTrue($items[0]->refresh()->is_enabled);

        app(PauseFestivalTimeline::class)->execute($timeline, $owner);
        $this->assertNotNull($timeline->refresh()->paused_at);
        app(ActivateFestivalTimelineItem::class)->execute($timeline, $items[2], $owner);
        $this->assertSame($items[2]->id, $timeline->refresh()->active_item_id);
        $this->travelTo($now->addMinutes(3));
        app(ResumeFestivalTimeline::class)->execute($timeline, $owner);
        $this->assertNull($timeline->refresh()->paused_at);
        $this->assertSame($now->addMinutes(8)->toISOString(), $items[2]->refresh()->planned_ends_at->toISOString());
        $this->assertGreaterThanOrEqual(1, FestivalActivityLog::query()->where('action', 'timeline.item_activated')->count());
    }

    public function test_reorder_and_toggle_during_an_imported_gap_preserve_the_boundary_and_recount_future_cards(): void
    {
        Queue::fake();
        [, $edition, $owner, $stage] = $this->festival();
        $now = CarbonImmutable::parse('2030-06-10 10:00:00', $edition->timezone)->utc();
        $this->travelTo($now);
        $this->slot($edition, $stage, ['type' => 'custom', 'name' => 'Finished', 'starts_at' => $now, 'ends_at' => $now->addMinutes(5), 'sort_order' => 10]);
        $this->slot($edition, $stage, ['type' => 'custom', 'name' => 'Next', 'starts_at' => $now->addMinutes(15), 'ends_at' => $now->addMinutes(19), 'sort_order' => 20]);
        $this->slot($edition, $stage, ['type' => 'custom', 'name' => 'Last', 'starts_at' => $now->addMinutes(19), 'ends_at' => $now->addMinutes(22), 'sort_order' => 30]);
        app(FillFestivalTimelines::class)->execute($edition, $owner);
        app(StartFestivalTimelines::class)->execute($edition, $owner);
        $timeline = FestivalTimeline::query()->with('items')->firstOrFail();
        [$finished, $next, $last] = $timeline->items->all();
        $this->travelTo($now->addMinutes(6));
        app(AdvanceFestivalTimeline::class)->execute(
            $timeline->id,
            $timeline->active_item_id,
            $timeline->last_finished_item_id,
            $timeline->next_transition_at->getTimestamp(),
        );
        $gapBoundary = $now->addMinutes(15);
        $this->assertNull($timeline->refresh()->active_item_id);
        $this->assertSame($gapBoundary->toISOString(), $timeline->next_transition_at->toISOString());

        app(ReorderFestivalTimeline::class)->execute($timeline, [$finished->id, $last->id, $next->id], $owner);
        $this->assertSame($gapBoundary->toISOString(), $last->refresh()->planned_starts_at->toISOString());
        $this->assertSame($gapBoundary->addMinutes(3)->toISOString(), $next->refresh()->planned_starts_at->toISOString());

        app(ToggleFestivalTimelineItem::class)->execute($timeline, $last, $owner);
        $this->assertSame($gapBoundary->toISOString(), $next->refresh()->planned_starts_at->toISOString());
        $this->assertSame($gapBoundary->toISOString(), $timeline->refresh()->next_transition_at->toISOString());

        app(ToggleFestivalTimelineItem::class)->execute($timeline, $last, $owner);
        $this->assertSame($gapBoundary->toISOString(), $last->refresh()->planned_starts_at->toISOString());
        $this->assertSame($gapBoundary->addMinutes(3)->toISOString(), $next->refresh()->planned_starts_at->toISOString());
    }

    public function test_staff_routes_are_authorized_scoped_and_render_without_scene_selector(): void
    {
        [$account, $edition, $owner, $stage] = $this->festival();
        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $otherStage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        $this->slot($edition, $stage, ['type' => 'custom', 'name' => 'Visible event', 'starts_at' => $edition->starts_at, 'ends_at' => $edition->starts_at->addMinutes(5)]);
        app(FillFestivalTimelines::class)->execute($edition, $owner);
        $item = FestivalTimelineItem::query()->sole();

        $this->get(route('dashboard.accounts.festivals.timeline.show', [$account, $edition, $stage]))->assertRedirect();
        $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.timeline.show', [$account, $edition, $stage]));
        $response->assertOk()
            ->assertSee('data-festival-timeline', false)
            ->assertSee('<h1', false)
            ->assertSee('>Таймлайн</h1>', false)
            ->assertSee('Visible event')
            ->assertDontSee('data-festival-scene-tabs', false)
            ->assertDontSee('data-filter-bar', false);
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.timeline.show', [$otherAccount, $edition, $stage]))->assertNotFound();
        $fragment = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.timeline.fragment', [$account, $edition, $stage]));
        $fragment->assertOk()->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', (string) $fragment->headers->get('Cache-Control'));
        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.timeline.toggle', [$account, $edition, $stage, $item]))
            ->assertRedirect();
        $this->assertFalse($item->refresh()->is_enabled);
        $toggleResponse = $this->actingAs($owner)
            ->patchJson(route('dashboard.accounts.festivals.timeline.toggle', [$account, $edition, $stage, $item]));
        $toggleResponse->assertOk()->assertJsonStructure(['message', 'fragment_html']);
        $this->assertStringContainsString('data-festival-timeline', $toggleResponse->json('fragment_html'));
        $this->assertTrue($item->refresh()->is_enabled);
        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.timeline.toggle', [$account, $edition, $otherStage, $item]))
            ->assertNotFound();
    }

    public function test_public_landing_stacks_live_scenes_hides_disabled_items_and_notes_and_polls_no_store(): void
    {
        Queue::fake();
        [$account, $edition, $owner, $stage] = $this->festival();
        $secondStage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Second scene', 'sort_order' => 20]);
        $now = CarbonImmutable::parse('2030-06-10 10:00:00', $edition->timezone)->utc();
        $this->travelTo($now);
        $this->slot($edition, $stage, ['type' => 'custom', 'name' => 'Public event', 'notes' => 'PRIVATE NOTES SENTINEL', 'starts_at' => $now, 'ends_at' => $now->addMinutes(5)]);
        $this->slot($edition, $secondStage, ['type' => 'custom', 'name' => 'Second public event', 'starts_at' => $now, 'ends_at' => $now->addMinutes(5)]);
        app(FillFestivalTimelines::class)->execute($edition, $owner);
        app(StartFestivalTimelines::class)->execute($edition, $owner);
        $disabled = FestivalTimelineItem::query()->where('label', 'Public event')->firstOrFail();
        app(ToggleFestivalTimelineItem::class)->execute($disabled->timeline, $disabled, $owner);

        foreach (['general', 'velvet_night'] as $template) {
            $edition->forceFill(['landing_template' => $template])->save();
            $response = $this->get(route('public.festivals.show', [$account->slug, $edition->slug]));
            $response->assertOk()
                ->assertSee('data-festival-timeline', false)
                ->assertSee('Second scene')
                ->assertSee('Second public event')
                ->assertDontSee('Public event')
                ->assertDontSee('PRIVATE NOTES SENTINEL');
        }

        $fragment = $this->get(route('public.festivals.timeline', [$account->slug, $edition->slug]));
        $fragment->assertOk()->assertDontSee('PRIVATE NOTES SENTINEL');
        $this->assertStringContainsString('no-store', (string) $fragment->headers->get('Cache-Control'));

        $this->travelTo($edition->starts_at->setTimezone($edition->timezone)->subDay()->startOfDay()->utc());
        $this->get(route('public.festivals.show', [$account->slug, $edition->slug]))
            ->assertOk()
            ->assertDontSee('data-festival-timeline', false);
        $this->travelTo($edition->ends_at->setTimezone($edition->timezone)->addDay()->endOfDay()->utc());
        $this->get(route('public.festivals.show', [$account->slug, $edition->slug]))
            ->assertOk()
            ->assertDontSee('data-festival-timeline', false);
    }

    public function test_active_card_uses_structured_clock_confirmation_and_keeps_pause_or_resume_in_focus(): void
    {
        Queue::fake();
        [$account, $edition, $owner, $stage] = $this->festival();
        $now = CarbonImmutable::parse('2030-06-10 10:00:00', $edition->timezone)->utc();
        $this->travelTo($now);
        $this->slot($edition, $stage, [
            'type' => 'custom',
            'name' => 'Current showcase',
            'notes' => 'Stage manager notes',
            'starts_at' => $now,
            'ends_at' => $now->addMinutes(5),
        ]);
        app(FillFestivalTimelines::class)->execute($edition, $owner);
        app(StartFestivalTimelines::class)->execute($edition, $owner);

        $pauseUrl = route('dashboard.accounts.festivals.timeline.pause', [$account, $edition, $stage]);
        $activeResponse = $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.timeline.show', [$account, $edition, $stage]));

        $activeResponse->assertOk()
            ->assertSee('data-confirm-icon="clock"', false)
            ->assertSee('data-confirm-details=', false)
            ->assertSee('Current showcase')
            ->assertSee('Stage manager notes')
            ->assertSee('data-timeline-current-control', false)
            ->assertSee('action="'.$pauseUrl.'"', false)
            ->assertSee(__('app.pause'));

        $pauseResponse = $this->actingAs($owner)->patchJson($pauseUrl);
        $pauseResponse->assertOk()->assertJsonStructure(['message', 'fragment_html']);
        $this->assertStringContainsString('data-timeline-action="resume"', $pauseResponse->json('fragment_html'));
        $resumeUrl = route('dashboard.accounts.festivals.timeline.resume', [$account, $edition, $stage]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.timeline.show', [$account, $edition, $stage]))
            ->assertOk()
            ->assertSee('data-timeline-current-control', false)
            ->assertSee('action="'.$resumeUrl.'"', false)
            ->assertSee(__('app.resume'));

        $resumeResponse = $this->actingAs($owner)->patchJson($resumeUrl);
        $resumeResponse->assertOk()->assertJsonStructure(['message', 'fragment_html']);
        $this->assertStringContainsString('data-timeline-action="pause"', $resumeResponse->json('fragment_html'));
    }

    public function test_boundary_progression_is_scene_scoped_and_completion_keeps_the_edition_in_progress(): void
    {
        Queue::fake();
        [$account, $edition, $owner, $stage] = $this->festival();
        $secondStage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Independent scene']);
        [, $otherEdition, $otherOwner, $otherStage] = $this->festival();
        $now = CarbonImmutable::parse('2030-06-10 10:00:00', $edition->timezone)->utc();
        $this->travelTo($now);
        $this->slot($edition, $stage, ['type' => 'custom', 'name' => 'First scene card', 'starts_at' => $now, 'ends_at' => $now->addMinutes(2)]);
        $this->slot($edition, $secondStage, ['type' => 'custom', 'name' => 'Second scene card', 'starts_at' => $now, 'ends_at' => $now->addMinutes(4)]);
        $this->slot($otherEdition, $otherStage, ['type' => 'custom', 'name' => 'Other Festival card', 'starts_at' => $now, 'ends_at' => $now->addMinutes(5)]);
        app(FillFestivalTimelines::class)->execute($edition, $owner);
        app(FillFestivalTimelines::class)->execute($otherEdition, $otherOwner);
        app(StartFestivalTimelines::class)->execute($edition, $owner);
        app(StartFestivalTimelines::class)->execute($otherEdition, $otherOwner);

        $firstTimeline = FestivalTimeline::query()->where('festival_stage_id', $stage->id)->firstOrFail();
        $secondTimeline = FestivalTimeline::query()->where('festival_stage_id', $secondStage->id)->firstOrFail();
        $otherTimeline = FestivalTimeline::query()->where('festival_stage_id', $otherStage->id)->firstOrFail();
        $stateKeys = array_flip(['active_item_id', 'last_finished_item_id', 'next_transition_at', 'completed_at']);
        $secondState = array_intersect_key($secondTimeline->getAttributes(), $stateKeys);
        $otherState = array_intersect_key($otherTimeline->getAttributes(), $stateKeys);
        $expectedActiveItemId = $firstTimeline->active_item_id;
        $expectedLastFinishedItemId = $firstTimeline->last_finished_item_id;
        $expectedTransitionTimestamp = $firstTimeline->next_transition_at->getTimestamp();
        $jobMiddleware = (new AdvanceFestivalTimelineJob(
            $firstTimeline->id,
            $expectedActiveItemId,
            $expectedLastFinishedItemId,
            $expectedTransitionTimestamp,
        ))->middleware();
        $this->assertCount(1, $jobMiddleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $jobMiddleware[0]);
        Queue::fake();
        $this->travelTo($now->addMinutes(3));

        $this->assertTrue(app(AdvanceFestivalTimeline::class)->execute(
            $firstTimeline->id,
            $expectedActiveItemId,
            $expectedLastFinishedItemId,
            $expectedTransitionTimestamp,
        ));
        $this->assertFalse(app(AdvanceFestivalTimeline::class)->execute(
            $firstTimeline->id,
            $expectedActiveItemId,
            $expectedLastFinishedItemId,
            $expectedTransitionTimestamp,
        ));

        $this->assertNotNull($firstTimeline->refresh()->completed_at);
        $this->assertSame($secondState, array_intersect_key($secondTimeline->refresh()->getAttributes(), $stateKeys));
        $this->assertSame($otherState, array_intersect_key($otherTimeline->refresh()->getAttributes(), $stateKeys));
        $this->assertSame(FestivalEditionStatus::InProgress, $edition->refresh()->status);
        $this->assertSame(FestivalEditionStatus::InProgress, $otherEdition->refresh()->status);
        $this->assertSame(1, FestivalActivityLog::query()->where('action', 'timeline.advanced')->where('festival_edition_id', $edition->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_stale_boundary_job_does_not_overwrite_a_manual_correction(): void
    {
        Queue::fake();
        [$account, $edition, $owner, $stage] = $this->festival();
        $now = CarbonImmutable::parse('2030-06-10 10:00:00', $edition->timezone)->utc();
        $this->travelTo($now);
        foreach (['First', 'Second'] as $index => $label) {
            $start = $now->addMinutes($index * 5);
            $this->slot($edition, $stage, ['type' => 'custom', 'name' => $label, 'starts_at' => $start, 'ends_at' => $start->addMinutes(5), 'sort_order' => ($index + 1) * 10]);
        }
        app(FillFestivalTimelines::class)->execute($edition, $owner);
        app(StartFestivalTimelines::class)->execute($edition, $owner);
        $oldJob = Queue::pushed(AdvanceFestivalTimelineJob::class)->first();
        $timeline = FestivalTimeline::query()->with('items')->firstOrFail();
        $second = $timeline->items[1];

        $this->travelTo($now->addMinute());
        app(ActivateFestivalTimelineItem::class)->execute($timeline, $second, $owner);
        $correctedEndsAt = $second->refresh()->planned_ends_at->toISOString();
        $activityCount = FestivalActivityLog::query()->where('action', 'timeline.advanced')->count();
        Queue::fake();

        app()->call([$oldJob, 'handle']);

        $this->assertSame($second->id, $timeline->refresh()->active_item_id);
        $this->assertSame($correctedEndsAt, $second->refresh()->planned_ends_at->toISOString());
        $this->assertSame($activityCount, FestivalActivityLog::query()->where('action', 'timeline.advanced')->count());
        Queue::assertNothingPushed();
    }

    public function test_manual_in_progress_status_and_out_of_date_start_are_rejected_and_no_timeline_cron_exists(): void
    {
        Queue::fake();
        [$account, $edition, $owner, $stage] = $this->festival();
        $this->slot($edition, $stage, ['type' => 'custom', 'name' => 'Scheduled event', 'starts_at' => $edition->starts_at, 'ends_at' => $edition->starts_at->addMinutes(5)]);
        app(FillFestivalTimelines::class)->execute($edition, $owner);

        $payload = [
            'festival_series_id' => $edition->festival_series_id,
            'title' => $edition->title,
            'status' => FestivalEditionStatus::InProgress->value,
            'registration_status' => $edition->registration_status->value,
            'summary' => $edition->summary,
            'timezone' => $edition->timezone,
            'starts_at' => $edition->starts_at->timezone($edition->timezone)->format('Y-m-d H:i:s'),
            'ends_at' => $edition->ends_at->timezone($edition->timezone)->format('Y-m-d H:i:s'),
            'age_reference_date' => $edition->age_reference_date->format('Y-m-d'),
        ];
        $this->expectValidation('status', fn () => app(SaveFestivalEdition::class)->execute($account, $payload, $owner, $edition));
        $this->assertSame(FestivalEditionStatus::Published, $edition->refresh()->status);
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.edit', [$account, $edition]))
            ->assertOk()
            ->assertDontSee('<option value="in_progress"', false);

        $this->travelTo(CarbonImmutable::parse('2030-06-09 12:00:00', $edition->timezone)->utc());
        $this->expectValidation('timeline', fn () => app(StartFestivalTimelines::class)->execute($edition, $owner));
        Queue::assertNothingPushed();

        $timelineTasks = collect(app(ScheduledTaskRegistry::class)->definitions())
            ->filter(fn (array $definition): bool => str_contains($definition['key'], 'timeline') || str_contains($definition['command'], 'timeline'));
        $this->assertCount(0, $timelineTasks);
    }

    /** @return array{Account, FestivalEdition, User, FestivalStage, FestivalCategory, FestivalEntry} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $startsAt = CarbonImmutable::parse('2030-06-10 08:00:00', 'Europe/Kyiv')->utc();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'timezone' => 'Europe/Kyiv',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHours(16),
        ]);
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Main scene', 'sort_order' => 10]);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Featured performance',
        ]);
        $owner = User::factory()->create();
        $account->addOwner($owner);

        return [$account, $edition, $owner, $stage, $category, $entry];
    }

    /** @param array<string, mixed> $attributes */
    private function slot(FestivalEdition $edition, FestivalStage $stage, array $attributes): FestivalScheduleSlot
    {
        return FestivalScheduleSlot::query()->create([
            'account_id' => $edition->account_id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $stage->id,
            'type' => 'custom',
            'sort_order' => 10,
            ...$attributes,
        ]);
    }

    private function expectValidation(string $field, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected validation error for {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
