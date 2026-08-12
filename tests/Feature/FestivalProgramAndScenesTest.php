<?php

namespace Tests\Feature;

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
use App\Models\FestivalNotification;
use App\Models\FestivalPortalUser;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
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
        $index->assertOk()->assertSee('Outdoor scene')->assertDontSee('Main scene');
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
        $festivalStartDateTime = $edition->starts_at->timezone($edition->timezone)->format('Y-m-d\TH:i');
        $response->assertSee('name="starts_at" value="'.$festivalStartDateTime.'"', false)
            ->assertSee('name="ends_at" value="'.$festivalStartDateTime.'"', false);
        $this->assertCount(2, $response->viewData('programItems'));
        $this->assertCount(1, $response->viewData('programTree'));
        $this->assertCount(1, $response->viewData('programTree')[0]['children']);

        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.program', [$account, $edition, 'scene' => 999999]))->assertNotFound();
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
