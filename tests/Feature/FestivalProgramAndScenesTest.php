<?php

namespace Tests\Feature;

use App\Actions\Festivals\FillFestivalTimelines;
use App\Actions\Festivals\SaveFestivalScheduleSlot;
use App\Enums\AccountRole;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalActivityLog;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Models\FestivalNotification;
use App\Models\FestivalPortalUser;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\FestivalTimeline;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FestivalProgramAndScenesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_scenes_are_a_settings_crud_for_owners_and_schedule_staff(): void
    {
        [$account, $edition, , $owner] = $this->festival();
        $first = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Main scene', 'sort_order' => 10]);
        $second = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Studio scene', 'sort_order' => 20]);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.stages.store', [$account, $edition]), [
            'name' => 'Outdoor scene',
            'description' => 'By the entrance',
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.stages', [$account, $edition]))->assertSessionHasNoErrors();

        $outdoor = FestivalStage::query()->where('festival_edition_id', $edition->id)->where('name', 'Outdoor scene')->firstOrFail();
        $this->assertSame(30, $outdoor->sort_order);
        $this->assertSame('By the entrance', $outdoor->description);

        $index = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.settings.stages', [$account, $edition, 'q' => 'Outdoor']));
        $index->assertOk()
            ->assertSee('Outdoor scene')
            ->assertSee(route('dashboard.accounts.festivals.timeline.show', [$account, $edition, $outdoor]), false)
            ->assertDontSee('Main scene');
        $this->assertInstanceOf(LengthAwarePaginator::class, $index->viewData('stages'));
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.stages.create', [$account, $edition]))
            ->assertOk()
            ->assertSee(route('help.show', 'festivals').'#help-section-festivals-program-scenes', false);

        $this->actingAs($owner)->patch(route('dashboard.accounts.festivals.stages.toggle', [$account, $edition, $outdoor]))->assertRedirect();
        $this->assertFalse($outdoor->refresh()->is_active);

        $this->actingAs($owner)->patch(route('dashboard.accounts.festivals.stages.move', [$account, $edition, $outdoor]), ['direction' => 'up'])->assertRedirect();
        $this->assertSame(20, $outdoor->refresh()->sort_order);
        $this->assertSame(30, $second->refresh()->sort_order);

        $scheduleStaff = $this->scheduleStaff($account);
        $this->actingAs($scheduleStaff)->get(route('dashboard.accounts.festivals.settings', [$account, $edition]))
            ->assertOk()->assertSee(__('app.festival_scenes'));
        $this->actingAs($scheduleStaff)->get(route('dashboard.accounts.festivals.settings.stages', [$account, $edition]))->assertOk();
        $this->actingAs($scheduleStaff)->post(route('dashboard.accounts.festivals.stages.store', [$account, $edition]), [
            'name' => 'Schedule staff scene',
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.stages', [$account, $edition]));

        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.stages.edit', [$otherAccount, $edition, $first]))->assertNotFound();

        $otherEdition = FestivalEdition::factory()->for(FestivalSeries::factory()->for($account))->create(['account_id' => $account->id]);
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.stages.edit', [$account, $otherEdition, $first]))->assertNotFound();
        $this->assertFalse(app('router')->has('dashboard.accounts.festivals.stages.destroy'));
    }

    public function test_read_only_editions_reject_scene_and_program_mutations(): void
    {
        [$account, $edition, , $owner] = $this->festival();
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'created_by_user_id' => $owner->id,
            'status' => FestivalEditionPurchaseStatus::PaymentReversed,
            'reversed_at' => now(),
        ]);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.stages.store', [$account, $edition]), [
            'name' => 'Blocked scene',
            'is_active' => 1,
        ])->assertStatus(423);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.schedule.store', [$account, $edition]), [
            'festival_stage_id' => $stage->id,
            'type' => 'free_header',
            'name' => 'Blocked header',
        ])->assertStatus(423);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.schedule.generate', [$account, $edition, $stage]), [
            'mode' => 'missing',
        ])->assertStatus(423);
    }

    public function test_schedule_backfill_is_deterministic_and_scene_history_blocks_hard_deletion(): void
    {
        [$account, $edition] = $this->festival();
        $firstStage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        $secondStage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        $baseTime = now($edition->timezone)->addMonth()->startOfHour();
        $later = FestivalScheduleSlot::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $firstStage->id,
            'type' => 'custom',
            'name' => 'Later',
            'starts_at' => $baseTime->copy()->addHour(),
            'ends_at' => $baseTime->copy()->addHour()->addMinutes(10),
            'sort_order' => 0,
        ]);
        $earlier = FestivalScheduleSlot::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $firstStage->id,
            'type' => 'custom',
            'name' => 'Earlier',
            'starts_at' => $baseTime,
            'ends_at' => $baseTime->copy()->addMinutes(10),
            'sort_order' => 0,
        ]);
        $sameTimeFirst = FestivalScheduleSlot::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $secondStage->id,
            'type' => 'custom',
            'name' => 'Same time first',
            'starts_at' => $baseTime,
            'ends_at' => $baseTime->copy()->addMinutes(10),
            'sort_order' => 0,
        ]);
        $sameTimeSecond = FestivalScheduleSlot::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $secondStage->id,
            'type' => 'custom',
            'name' => 'Same time second',
            'starts_at' => $baseTime,
            'ends_at' => $baseTime->copy()->addMinutes(10),
            'sort_order' => 0,
        ]);

        $migration = require database_path('migrations/2026_08_12_113659_backfill_festival_schedule_slot_sort_orders.php');
        $migration->up();

        $this->assertSame(10, $earlier->refresh()->sort_order);
        $this->assertSame(20, $later->refresh()->sort_order);
        $this->assertSame(10, $sameTimeFirst->refresh()->sort_order);
        $this->assertSame(20, $sameTimeSecond->refresh()->sort_order);

        try {
            $firstStage->delete();
            $this->fail('A scene with program history was hard-deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('festival_stages', ['id' => $firstStage->id]);
            $this->assertDatabaseHas('festival_schedule_slots', ['id' => $earlier->id]);
        }
    }

    public function test_program_has_linkable_scene_tabs_one_modal_and_only_loads_the_active_scene_tree(): void
    {
        [$account, $edition, $category, $owner] = $this->festival();
        $main = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Main scene', 'sort_order' => 10]);
        $inactive = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Inactive scene', 'is_active' => false, 'sort_order' => 20]);
        $mainHeader = $this->saveItem($edition, $owner, ['festival_stage_id' => $main->id, 'type' => 'category_header', 'festival_category_id' => $category->id]);
        $this->saveItem($edition, $owner, ['festival_stage_id' => $main->id, 'type' => 'free_header', 'name' => 'Nested header', 'parent_id' => $mainHeader->id]);
        $this->saveItem($edition, $owner, ['festival_stage_id' => $inactive->id, 'type' => 'free_header', 'name' => 'Inactive sentinel']);
        $category->update(['name' => 'Live category label']);

        $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.program', [$account, $edition, 'scene' => $main->id]));

        $response->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('aria-selected="true"', false)
            ->assertSee('tabindex="0"', false)
            ->assertSee('tabindex="-1"', false)
            ->assertSee('data-festival-program-modal', false)
            ->assertSee('data-festival-program-add', false)
            ->assertSee('draggable="true" data-festival-program-row data-festival-program-drag', false)
            ->assertSee('Live category label')
            ->assertSee('Nested header')
            ->assertSee('Inactive scene')
            ->assertSee(__('app.inactive'))
            ->assertDontSee('Inactive sentinel');
        $response->assertSee(route('dashboard.accounts.festivals.timeline.show', [$account, $edition, $main]), false)
            ->assertSee(route('dashboard.accounts.festivals.timeline.show', [$account, $edition, $inactive]), false);
        $festivalStartDateTime = $edition->starts_at->timezone($edition->timezone)->format('Y-m-d\TH:i');
        $response->assertSee('name="starts_at" value="'.$festivalStartDateTime.'"', false)
            ->assertSee('name="ends_at" value="'.$festivalStartDateTime.'"', false);
        $this->assertCount(2, $response->viewData('programItems'));
        $this->assertCount(1, $response->viewData('programTree'));
        $this->assertCount(1, $response->viewData('programTree')[0]['children']);

        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.program', [$account, $edition, 'scene' => 999999]))->assertNotFound();
    }

    public function test_missing_generation_reuses_headers_preserves_manual_items_and_is_idempotent(): void
    {
        [$account, $edition, $category, $owner, $portalUser] = $this->festival();
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Main scene']);
        $otherStage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Other scene']);
        $manualRoot = $this->saveItem($edition, $owner, [
            'festival_stage_id' => $stage->id,
            'type' => 'free_header',
            'name' => 'Opening ceremony',
        ]);
        $categoryHeader = $this->saveItem($edition, $owner, [
            'festival_stage_id' => $stage->id,
            'type' => 'category_header',
            'festival_category_id' => $category->id,
            'parent_id' => $manualRoot->id,
        ]);
        $manualChild = $this->saveItem($edition, $owner, [
            'festival_stage_id' => $stage->id,
            'type' => 'free_header',
            'name' => 'Manual category note',
            'parent_id' => $categoryHeader->id,
        ]);
        $alpha = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Alpha performance',
            'status' => 'accepted',
        ]);
        $beta = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Beta performance',
            'status' => 'accepted',
        ]);
        $elsewhere = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Already assigned',
            'status' => 'accepted',
        ]);
        $submitted = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Not accepted',
            'status' => 'submitted',
        ]);
        $startsAt = now($edition->timezone)->addMonth()->startOfHour();
        $otherScenePerformance = $this->saveItem($edition, $owner, [
            'festival_stage_id' => $otherStage->id,
            'festival_entry_id' => $elsewhere->id,
            'type' => 'performance',
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
        ]);

        $response = $this->actingAs($owner)->post(route('dashboard.accounts.festivals.schedule.generate', [$account, $edition, $stage]), [
            'mode' => 'missing',
        ]);

        $response->assertRedirect(route('dashboard.accounts.festivals.program', [$account, $edition, 'scene' => $stage->id]))
            ->assertSessionHas('status', __('app.festival_program_generated', [
                'created' => 2,
                'created_headers' => 0,
                'deleted' => 0,
                'skipped' => 1,
            ]));
        $this->assertDatabaseHas('festival_schedule_slots', ['id' => $manualRoot->id, 'name' => 'Opening ceremony']);
        $this->assertDatabaseHas('festival_schedule_slots', ['id' => $manualChild->id, 'parent_id' => $categoryHeader->id]);
        $this->assertDatabaseHas('festival_schedule_slots', ['id' => $otherScenePerformance->id, 'festival_stage_id' => $otherStage->id]);
        $generated = FestivalScheduleSlot::query()
            ->where('festival_stage_id', $stage->id)
            ->where('type', 'performance')
            ->with('entry')
            ->orderBy('sort_order')
            ->get();
        $this->assertSame(['Alpha performance', 'Beta performance'], $generated->pluck('entry.entry_name')->all());
        $this->assertSame([$categoryHeader->id, $categoryHeader->id], $generated->pluck('parent_id')->all());
        $this->assertSame([20, 30], $generated->pluck('sort_order')->all());
        $this->assertTrue($generated->every(fn (FestivalScheduleSlot $slot): bool => $slot->starts_at === null && $slot->ends_at === null && $slot->published_at === null));
        $this->assertFalse($generated->contains('festival_entry_id', $submitted->id));

        $activity = FestivalActivityLog::query()->where('action', 'schedule.generated')->latest('id')->firstOrFail();
        $this->assertSame($owner->id, $activity->actor_user_id);
        $this->assertSame(['mode' => 'missing', 'created' => 2, 'created_headers' => 0, 'deleted' => 0, 'skipped' => 1, 'timeline_removed' => false], $activity->payload);

        $slotCount = FestivalScheduleSlot::query()->where('festival_edition_id', $edition->id)->count();
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.schedule.generate', [$account, $edition, $stage]), [
            'mode' => 'missing',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($slotCount, FestivalScheduleSlot::query()->where('festival_edition_id', $edition->id)->count());
        $this->assertSame(0, FestivalActivityLog::query()->where('action', 'schedule.generated')->latest('id')->firstOrFail()->payload['created']);
    }

    public function test_full_generation_replaces_only_one_scene_in_configured_order_and_removes_its_prepared_timeline(): void
    {
        [$account, $edition, $laterCategory, $owner, $portalUser] = $this->festival();
        $laterCategory->update(['name' => 'Later category', 'sort_order' => 20]);
        $earlierCategory = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Earlier category',
            'sort_order' => 10,
        ]);
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Main scene']);
        $otherStage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Other scene']);
        $bravo = FestivalEntry::factory()->for($laterCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Bravo',
            'status' => 'accepted',
        ]);
        FestivalEntry::factory()->for($earlierCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Zulu',
            'status' => 'accepted',
        ]);
        FestivalEntry::factory()->for($earlierCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Alpha',
            'status' => 'accepted',
        ]);
        $elsewhere = FestivalEntry::factory()->for($laterCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Elsewhere',
            'status' => 'accepted',
        ]);
        $manual = $this->saveItem($edition, $owner, ['festival_stage_id' => $stage->id, 'type' => 'free_header', 'name' => 'Delete me']);
        $startsAt = now($edition->timezone)->addMonth()->startOfHour();
        $oldBravo = $this->saveItem($edition, $owner, [
            'festival_stage_id' => $stage->id,
            'festival_entry_id' => $bravo->id,
            'type' => 'performance',
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
            'is_published' => true,
        ]);
        $otherScenePerformance = $this->saveItem($edition, $owner, [
            'festival_stage_id' => $otherStage->id,
            'festival_entry_id' => $elsewhere->id,
            'type' => 'performance',
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
        ]);
        $timeline = FestivalTimeline::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $stage->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.schedule.generate', [$account, $edition, $stage]), [
            'mode' => 'full',
        ])->assertRedirect()->assertSessionHas('status', __('app.festival_program_generated', [
            'created' => 3,
            'created_headers' => 2,
            'deleted' => 2,
            'skipped' => 1,
        ]));

        $this->assertDatabaseMissing('festival_schedule_slots', ['id' => $manual->id]);
        $this->assertDatabaseMissing('festival_schedule_slots', ['id' => $oldBravo->id]);
        $this->assertDatabaseMissing('festival_timelines', ['id' => $timeline->id]);
        $this->assertDatabaseHas('festival_schedule_slots', ['id' => $otherScenePerformance->id, 'festival_stage_id' => $otherStage->id]);
        $rootHeaders = FestivalScheduleSlot::query()
            ->where('festival_stage_id', $stage->id)
            ->where('type', 'category_header')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();
        $this->assertSame([$earlierCategory->id, $laterCategory->id], $rootHeaders->pluck('festival_category_id')->all());
        $this->assertSame(['Alpha', 'Zulu'], FestivalScheduleSlot::query()
            ->where('parent_id', $rootHeaders->first()->id)
            ->with('entry')
            ->orderBy('sort_order')
            ->get()
            ->pluck('entry.entry_name')
            ->all());
        $this->assertSame(['Bravo'], FestivalScheduleSlot::query()
            ->where('parent_id', $rootHeaders->last()->id)
            ->with('entry')
            ->get()
            ->pluck('entry.entry_name')
            ->all());
        $this->assertSame(3, FestivalScheduleSlot::query()->where('festival_stage_id', $stage->id)->where('type', 'performance')->whereNull('starts_at')->whereNull('ends_at')->whereNull('published_at')->count());
        $this->assertSame(true, FestivalActivityLog::query()->where('action', 'schedule.generated')->latest('id')->firstOrFail()->payload['timeline_removed']);

        $emptyTarget = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Empty target']);
        $emptyTargetManual = $this->saveItem($edition, $owner, ['festival_stage_id' => $emptyTarget->id, 'type' => 'free_header', 'name' => 'Remove without replacements']);
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.schedule.generate', [$account, $edition, $emptyTarget]), [
            'mode' => 'full',
        ])->assertRedirect()->assertSessionHas('status', __('app.festival_program_generated', [
            'created' => 0,
            'created_headers' => 0,
            'deleted' => 1,
            'skipped' => 4,
        ]));
        $this->assertDatabaseMissing('festival_schedule_slots', ['id' => $emptyTargetManual->id]);
        $this->assertSame(0, FestivalScheduleSlot::query()->where('festival_stage_id', $emptyTarget->id)->count());
    }

    public function test_started_timeline_and_access_boundaries_reject_generation_atomically(): void
    {
        [$account, $edition, $category, $owner, $portalUser] = $this->festival();
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        $manual = $this->saveItem($edition, $owner, ['festival_stage_id' => $stage->id, 'type' => 'free_header', 'name' => 'Keep me']);
        FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => 'accepted',
        ]);
        $timeline = FestivalTimeline::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $stage->id,
            'started_at' => now(),
        ]);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.schedule.generate', [$account, $edition, $stage]), [
            'mode' => 'full',
        ])->assertRedirect(route('dashboard.accounts.festivals.program', [$account, $edition, 'scene' => $stage->id]))
            ->assertSessionHasErrorsIn('programGeneration', ['mode']);
        $this->assertDatabaseHas('festival_schedule_slots', ['id' => $manual->id, 'name' => 'Keep me']);
        $this->assertDatabaseHas('festival_timelines', ['id' => $timeline->id, 'started_at' => $timeline->started_at]);
        $this->assertDatabaseMissing('festival_activity_logs', ['festival_edition_id' => $edition->id, 'action' => 'schedule.generated']);

        $unauthorized = User::factory()->create();
        $this->actingAs($unauthorized)->post(route('dashboard.accounts.festivals.schedule.generate', [$account, $edition, $stage]), [
            'mode' => 'missing',
        ])->assertForbidden();
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.schedule.generate', [$account, $edition, $stage]), [
            'mode' => 'invalid',
        ])->assertSessionHasErrorsIn('programGeneration', ['mode']);

        $otherSeries = FestivalSeries::factory()->for($account)->create();
        $otherEdition = FestivalEdition::factory()->published()->for($otherSeries)->create(['account_id' => $account->id]);
        $otherEditionStage = FestivalStage::factory()->for($otherEdition)->create(['account_id' => $account->id]);
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.schedule.generate', [$account, $edition, $otherEditionStage]), [
            'mode' => 'missing',
        ])->assertNotFound();
    }

    public function test_untimed_generated_performances_are_incomplete_until_their_times_are_saved(): void
    {
        Queue::fake();
        [$account, $edition, $category, $owner, $portalUser] = $this->festival();
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Needs a time',
            'status' => 'accepted',
        ]);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.schedule.generate', [$account, $edition, $stage]), [
            'mode' => 'missing',
        ])->assertRedirect();
        $placeholder = FestivalScheduleSlot::query()->where('festival_entry_id', $entry->id)->where('type', 'performance')->firstOrFail();
        $header = FestivalScheduleSlot::query()->findOrFail($placeholder->parent_id);
        $this->assertFalse($placeholder->hasTimeRange());
        $this->assertFalse($entry->refresh()->isReady());
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications', [$account, $edition]))
            ->assertOk()
            ->assertSee('Needs a time')
            ->assertSee(__('app.festival_not_ready'));

        $program = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.program', [$account, $edition, 'scene' => $stage->id]));
        $program->assertOk()
            ->assertSee('data-festival-program-generation-modal', false)
            ->assertSee('data-festival-program-generation-missing', false)
            ->assertSee('data-festival-program-generation-full', false)
            ->assertSee('data-festival-program-generation-confirmation', false)
            ->assertSee('data-festival-program-generation-confirm', false)
            ->assertSee('data-festival-program-time-warning', false)
            ->assertSee(__('app.festival_program_time_required'))
            ->assertSee('border-violet-200 bg-violet-50/70', false)
            ->assertSee('border-emerald-200 bg-emerald-50/70', false);

        $placeholder->forceFill(['published_at' => now()])->save();
        $portal = $this->actingAs($portalUser, 'festival')->get(route('festival.portal.entries.index', $account->slug));
        $portal->assertOk();
        $portalEntry = $portal->viewData('entries')->firstWhere('id', $entry->id);
        $this->assertNotNull($portalEntry);
        $this->assertTrue($portalEntry->scheduleSlots->isEmpty());
        $portalDetail = $this->actingAs($portalUser, 'festival')->get(route('festival.portal.entries.show', [$account->slug, $entry]));
        $portalDetail->assertOk()->assertSee(__('app.festival_schedule_pending'));
        $this->assertTrue($portalDetail->viewData('entry')->scheduleSlots->isEmpty());
        $entryStep = FestivalEntryStep::factory()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $entry->id,
        ]);
        $portalStep = $this->actingAs($portalUser, 'festival')->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $entryStep]));
        $portalStep->assertOk()->assertSee(__('app.festival_schedule_pending'));
        $this->assertTrue($portalStep->viewData('entry')->scheduleSlots->isEmpty());
        $staffOverview = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.show', [$account, $edition]));
        $staffOverview->assertOk();
        $this->assertTrue($staffOverview->viewData('upcomingSlots')->isEmpty());
        $this->assertSame([], app(FillFestivalTimelines::class)->execute($edition, $owner));
        $this->assertDatabaseMissing('festival_timelines', ['festival_stage_id' => $stage->id]);

        $startsAt = now($edition->timezone)->addMonth()->startOfHour();
        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.schedule.update', [$account, $edition, $placeholder]), [
            'festival_stage_id' => $stage->id,
            'festival_entry_id' => $entry->id,
            'parent_id' => $header->id,
            'type' => 'performance',
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
            'reschedule_reason' => 'Set the generated performance time',
            'is_published' => true,
            'editing_item_id' => $placeholder->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($placeholder->refresh()->hasTimeRange());
        $this->assertTrue($entry->refresh()->isReady());
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications', [$account, $edition]))
            ->assertOk()
            ->assertSee(__('app.festival_ready'));
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.program', [$account, $edition, 'scene' => $stage->id]))
            ->assertOk()
            ->assertDontSee('data-festival-program-time-warning', false);
        $timelines = app(FillFestivalTimelines::class)->execute($edition, $owner);
        $this->assertCount(1, $timelines);
        $this->assertSame(1, $timelines[0]->items()->count());
    }

    public function test_all_program_item_types_enforce_their_shapes_and_custom_timeframes_overlap(): void
    {
        Queue::fake();
        [$account, $edition, $category, $owner, $portalUser] = $this->festival();
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => 'accepted',
        ]);
        $startsAt = now($edition->timezone)->addMonth()->startOfHour();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.schedule.store', [$account, $edition]), [
            'festival_stage_id' => $stage->id,
            'type' => 'performance',
        ])->assertSessionHasErrors(['festival_entry_id', 'starts_at', 'ends_at']);
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.schedule.store', [$account, $edition]), [
            'festival_stage_id' => $stage->id,
            'type' => 'custom',
        ])->assertSessionHasErrors(['name', 'starts_at', 'ends_at']);
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.schedule.store', [$account, $edition]), [
            'festival_stage_id' => $stage->id,
            'type' => 'free_header',
        ])->assertSessionHasErrors('name');
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.schedule.store', [$account, $edition]), [
            'festival_stage_id' => $stage->id,
            'type' => 'category_header',
        ])->assertSessionHasErrors('festival_category_id');

        $performance = $this->saveItem($edition, $owner, [
            'festival_stage_id' => $stage->id,
            'festival_entry_id' => $entry->id,
            'type' => 'performance',
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
        ]);
        $performance->forceFill(['published_at' => now()])->save();
        $this->saveItem($edition, $owner, [
            'festival_stage_id' => $stage->id,
            'festival_entry_id' => $entry->id,
            'type' => 'rehearsal',
            'starts_at' => $startsAt->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addMinutes(20)->format('Y-m-d H:i:s'),
        ]);
        $custom = $this->saveItem($edition, $owner, [
            'festival_stage_id' => $stage->id,
            'type' => 'custom',
            'name' => 'Фото-перерва',
            'starts_at' => $startsAt->copy()->addMinutes(20)->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
            'is_published' => true,
        ]);
        $freeHeader = $this->saveItem($edition, $owner, ['festival_stage_id' => $stage->id, 'type' => 'free_header', 'name' => 'Finals']);
        $categoryHeader = $this->saveItem($edition, $owner, ['festival_stage_id' => $stage->id, 'type' => 'category_header', 'festival_category_id' => $category->id]);

        $this->assertNull($custom->festival_entry_id);
        $this->assertNull($custom->festival_category_id);
        $this->assertSame('Фото-перерва', $custom->name);
        $this->assertNull($freeHeader->starts_at);
        $this->assertNull($freeHeader->ends_at);
        $this->assertNull($categoryHeader->name);
        $this->assertSame($category->id, $categoryHeader->festival_category_id);

        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.program', [$account, $edition, 'scene' => $stage->id]))
            ->assertOk()
            ->assertSee('border-violet-200 bg-violet-50/70', false)
            ->assertSee('border-sky-200 bg-sky-50/70', false)
            ->assertSee('border-emerald-200 bg-emerald-50/70', false)
            ->assertSee('border-amber-200 bg-amber-50/70', false)
            ->assertSee('border-rose-200 bg-rose-50/70', false);

        try {
            $this->saveItem($edition, $owner, [
                'festival_stage_id' => $stage->id,
                'type' => 'custom',
                'name' => 'Overlapping break',
                'starts_at' => $startsAt->copy()->addMinutes(5)->format('Y-m-d H:i:s'),
                'ends_at' => $startsAt->copy()->addMinutes(15)->format('Y-m-d H:i:s'),
            ]);
            $this->fail('An overlapping custom timeframe was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
        }

        $portal = $this->actingAs($portalUser, 'festival')->get(route('festival.portal.entries.index', $account->slug));
        $portal->assertOk()->assertSee($performance->entry->entry_name)->assertDontSee('Фото-перерва')->assertDontSee('Finals');
    }

    public function test_hierarchy_validation_rejects_leaf_parents_cycles_and_header_conversion(): void
    {
        [$account, $edition, , $owner] = $this->festival();
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        $startsAt = now($edition->timezone)->addMonth()->startOfHour();
        $header = $this->saveItem($edition, $owner, ['festival_stage_id' => $stage->id, 'type' => 'free_header', 'name' => 'Parent']);
        $childHeader = $this->saveItem($edition, $owner, ['festival_stage_id' => $stage->id, 'type' => 'free_header', 'name' => 'Child', 'parent_id' => $header->id]);
        $leaf = $this->saveItem($edition, $owner, [
            'festival_stage_id' => $stage->id,
            'type' => 'custom',
            'name' => 'Break',
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
            'parent_id' => $childHeader->id,
        ]);

        $this->expectValidationError('parent_id', fn () => $this->saveItem($edition, $owner, [
            'festival_stage_id' => $stage->id,
            'type' => 'free_header',
            'name' => 'Invalid child',
            'parent_id' => $leaf->id,
        ]));
        $this->expectValidationError('parent_id', fn () => $this->saveItem($edition, $owner, [
            'festival_stage_id' => $stage->id,
            'type' => 'free_header',
            'name' => 'Parent',
            'parent_id' => $childHeader->id,
        ], $header));
        $this->expectValidationError('type', fn () => $this->saveItem($edition, $owner, [
            'festival_stage_id' => $stage->id,
            'type' => 'custom',
            'name' => 'Converted parent',
            'starts_at' => $startsAt->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addMinutes(20)->format('Y-m-d H:i:s'),
        ], $header));

        $otherStage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        $foreignHeader = $this->saveItem($edition, $owner, ['festival_stage_id' => $otherStage->id, 'type' => 'free_header', 'name' => 'Other scene']);
        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.schedule.update', [$account, $edition, $leaf]), [
            'festival_stage_id' => $stage->id,
            'type' => 'custom',
            'name' => 'Break',
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
            'parent_id' => $foreignHeader->id,
            'editing_item_id' => $leaf->id,
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_moving_a_header_between_scenes_moves_its_complete_subtree_atomically(): void
    {
        Queue::fake();
        [$account, $edition, $category, $owner, $portalUser] = $this->festival();
        $source = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Source']);
        $destination = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Destination']);
        $destinationSibling = $this->saveItem($edition, $owner, ['festival_stage_id' => $destination->id, 'type' => 'free_header', 'name' => 'Existing destination item']);
        $startsAt = now($edition->timezone)->addMonth()->startOfHour();
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Nested performance',
            'status' => 'accepted',
        ]);
        $header = $this->saveItem($edition, $owner, ['festival_stage_id' => $source->id, 'type' => 'free_header', 'name' => 'Moving tree']);
        $childHeader = $this->saveItem($edition, $owner, ['festival_stage_id' => $source->id, 'type' => 'free_header', 'name' => 'Nested group', 'parent_id' => $header->id]);
        $leaf = $this->saveItem($edition, $owner, [
            'festival_stage_id' => $source->id,
            'festival_entry_id' => $entry->id,
            'type' => 'performance',
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
            'parent_id' => $childHeader->id,
            'is_published' => true,
        ]);

        $this->saveItem($edition, $owner, [
            'festival_stage_id' => $destination->id,
            'type' => 'free_header',
            'name' => 'Moving tree',
            'reschedule_reason' => 'Move the complete program group',
        ], $header);

        $this->assertSame($destination->id, $header->refresh()->festival_stage_id);
        $this->assertSame(20, $header->sort_order);
        $this->assertSame($destination->id, $childHeader->refresh()->festival_stage_id);
        $this->assertSame($header->id, $childHeader->parent_id);
        $this->assertSame($destination->id, $leaf->refresh()->festival_stage_id);
        $this->assertSame($childHeader->id, $leaf->parent_id);
        $this->assertSame(10, $destinationSibling->refresh()->sort_order);
        $this->assertSame(3, FestivalActivityLog::query()->where('festival_edition_id', $edition->id)->where('action', 'schedule.rescheduled')->whereIn('subject_id', [$header->id, $childHeader->id, $leaf->id])->count());
        $this->assertSame(1, FestivalNotification::query()->where('festival_entry_id', $entry->id)->where('type', 'schedule_changed')->count());

        $sourcePage = $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.program', [$account, $edition, 'scene' => $source->id]));
        $sourcePage->assertOk();
        $this->assertFalse($sourcePage->viewData('programItems')->contains('id', $header->id));
        $this->assertFalse($sourcePage->viewData('programItems')->contains('id', $childHeader->id));
        $this->assertFalse($sourcePage->viewData('programItems')->contains('id', $leaf->id));

        $destinationPage = $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.program', [$account, $edition, 'scene' => $destination->id]));
        $destinationPage->assertOk()->assertSee('Moving tree')->assertSee('Nested group')->assertSee('Nested performance');
        $this->assertTrue($destinationPage->viewData('programItems')->contains('id', $header->id));
        $this->assertTrue($destinationPage->viewData('programItems')->contains('id', $childHeader->id));
        $this->assertTrue($destinationPage->viewData('programItems')->contains('id', $leaf->id));
    }

    public function test_moving_a_header_subtree_rejects_timed_descendant_overlap_in_the_destination(): void
    {
        [, $edition, , $owner] = $this->festival();
        $source = FestivalStage::factory()->for($edition)->create(['account_id' => $edition->account_id]);
        $destination = FestivalStage::factory()->for($edition)->create(['account_id' => $edition->account_id]);
        $startsAt = now($edition->timezone)->addMonth()->startOfHour();
        $header = $this->saveItem($edition, $owner, ['festival_stage_id' => $source->id, 'type' => 'free_header', 'name' => 'Conflicting tree']);
        $leaf = $this->saveItem($edition, $owner, [
            'festival_stage_id' => $source->id,
            'type' => 'custom',
            'name' => 'Moving slot',
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
            'parent_id' => $header->id,
        ]);
        $this->saveItem($edition, $owner, [
            'festival_stage_id' => $destination->id,
            'type' => 'custom',
            'name' => 'Destination slot',
            'starts_at' => $startsAt->copy()->addMinutes(5)->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addMinutes(15)->format('Y-m-d H:i:s'),
        ]);
        $activityCount = FestivalActivityLog::query()->where('festival_edition_id', $edition->id)->count();
        $notificationCount = FestivalNotification::query()->where('festival_edition_id', $edition->id)->count();

        $this->expectValidationError('festival_stage_id', fn () => $this->saveItem($edition, $owner, [
            'festival_stage_id' => $destination->id,
            'type' => 'free_header',
            'name' => 'Conflicting tree',
            'reschedule_reason' => 'Move the complete program group',
        ], $header));

        $this->assertSame($source->id, $header->refresh()->festival_stage_id);
        $this->assertSame($source->id, $leaf->refresh()->festival_stage_id);
        $this->assertSame($activityCount, FestivalActivityLog::query()->where('festival_edition_id', $edition->id)->count());
        $this->assertSame($notificationCount, FestivalNotification::query()->where('festival_edition_id', $edition->id)->count());
    }

    public function test_reorder_requires_the_exact_scene_tree_and_changes_only_parent_and_sibling_order(): void
    {
        [$account, $edition, , $owner] = $this->festival();
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        $startsAt = now($edition->timezone)->addMonth()->startOfHour();
        $firstHeader = $this->saveItem($edition, $owner, ['festival_stage_id' => $stage->id, 'type' => 'free_header', 'name' => 'First']);
        $timedItem = $this->saveItem($edition, $owner, [
            'festival_stage_id' => $stage->id,
            'type' => 'custom',
            'name' => 'Break',
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
            'parent_id' => $firstHeader->id,
        ]);
        $secondHeader = $this->saveItem($edition, $owner, ['festival_stage_id' => $stage->id, 'type' => 'free_header', 'name' => 'Second']);
        $originalStartsAt = $timedItem->starts_at->toISOString();
        $originalEndsAt = $timedItem->ends_at->toISOString();

        $payload = [
            ['id' => $secondHeader->id, 'parent_id' => null],
            ['id' => $firstHeader->id, 'parent_id' => $secondHeader->id],
            ['id' => $timedItem->id, 'parent_id' => $firstHeader->id],
        ];
        $this->actingAs($owner)->patchJson(route('dashboard.accounts.festivals.schedule.reorder', [$account, $edition, $stage]), ['items' => $payload])
            ->assertOk()->assertJsonPath('message', __('app.festival_program_order_saved'));

        $this->assertNull($secondHeader->refresh()->parent_id);
        $this->assertSame(10, $secondHeader->sort_order);
        $this->assertSame($secondHeader->id, $firstHeader->refresh()->parent_id);
        $this->assertSame(10, $firstHeader->sort_order);
        $this->assertSame($firstHeader->id, $timedItem->refresh()->parent_id);
        $this->assertSame(10, $timedItem->sort_order);
        $this->assertSame($originalStartsAt, $timedItem->starts_at->toISOString());
        $this->assertSame($originalEndsAt, $timedItem->ends_at->toISOString());

        $beforeInvalid = $stage->slots()->get()->mapWithKeys(fn (FestivalScheduleSlot $slot): array => [$slot->id => [$slot->parent_id, $slot->sort_order]])->all();
        $this->actingAs($owner)->patchJson(route('dashboard.accounts.festivals.schedule.reorder', [$account, $edition, $stage]), ['items' => array_slice($payload, 0, 2)])
            ->assertUnprocessable();
        $this->assertSame($beforeInvalid, $stage->slots()->get()->mapWithKeys(fn (FestivalScheduleSlot $slot): array => [$slot->id => [$slot->parent_id, $slot->sort_order]])->all());

        $this->actingAs($owner)->patchJson(route('dashboard.accounts.festivals.schedule.reorder', [$account, $edition, $stage]), ['items' => [
            ['id' => $secondHeader->id, 'parent_id' => $firstHeader->id],
            ['id' => $firstHeader->id, 'parent_id' => $secondHeader->id],
            ['id' => $timedItem->id, 'parent_id' => $firstHeader->id],
        ]])->assertUnprocessable();

        $this->actingAs($owner)->patchJson(route('dashboard.accounts.festivals.schedule.reorder', [$account, $edition, $stage]), ['items' => [
            ['id' => $secondHeader->id, 'parent_id' => null],
            ['id' => $firstHeader->id, 'parent_id' => $timedItem->id],
            ['id' => $timedItem->id, 'parent_id' => $firstHeader->id],
        ]])->assertUnprocessable();

        $otherStage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        $otherHeader = $this->saveItem($edition, $owner, ['festival_stage_id' => $otherStage->id, 'type' => 'free_header', 'name' => 'Foreign']);
        $this->actingAs($owner)->patchJson(route('dashboard.accounts.festivals.schedule.reorder', [$account, $edition, $stage]), ['items' => [
            ...$payload,
            ['id' => $otherHeader->id, 'parent_id' => null],
        ]])->assertUnprocessable();
    }

    /** @return array{Account, FestivalEdition, FestivalCategory, User, FestivalPortalUser} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'timezone' => 'Europe/Kyiv',
        ]);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();

        return [$account, $edition, $category, $owner, $portalUser];
    }

    /** @param array<string, mixed> $attributes */
    private function saveItem(FestivalEdition $edition, User $actor, array $attributes, ?FestivalScheduleSlot $slot = null): FestivalScheduleSlot
    {
        return app(SaveFestivalScheduleSlot::class)->execute($edition, $attributes, $actor, $slot);
    }

    private function scheduleStaff(Account $account): User
    {
        $staff = User::factory()->create();
        $account->users()->attach($staff->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::ManageFestivalSchedule->value],
        ]);

        return $staff;
    }

    private function expectValidationError(string $field, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected validation error for {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
