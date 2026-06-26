<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassPassSegment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ClassPassSegmentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_create_and_update_class_pass_segment_with_multiple_directions(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $poleDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Pole']);
        $kidsDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Kids']);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-segments.store', $account), [
                'name' => 'Дитячі абонементи',
                'slug' => 'kids-passes',
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'sort_order' => 10,
                'activity_direction_ids' => [$poleDirection->id, $kidsDirection->id],
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.class-pass-segments.index', $account));

        $classPassSegment = ClassPassSegment::whereBelongsTo($account)
            ->where('slug', 'kids-passes')
            ->firstOrFail();

        $this->assertSame(ScheduleKind::GroupClass, $classPassSegment->schedule_kind);
        $this->assertTrue($classPassSegment->is_active);
        $this->assertEqualsCanonicalizing(
            [$poleDirection->id, $kidsDirection->id],
            $classPassSegment->activityDirections()->pluck('activity_directions.id')->all(),
        );

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.class-pass-segments.update', [$account, $classPassSegment]), [
                'name' => 'Kids only',
                'slug' => 'kids-only',
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'sort_order' => 20,
                'activity_direction_ids' => [$kidsDirection->id],
                'is_active' => '0',
            ])
            ->assertRedirect(route('dashboard.accounts.class-pass-segments.index', $account));

        $classPassSegment->refresh();

        $this->assertSame('Kids only', $classPassSegment->name);
        $this->assertSame('kids-only', $classPassSegment->slug);
        $this->assertSame(20, $classPassSegment->sort_order);
        $this->assertFalse($classPassSegment->is_active);
        $this->assertEquals([$kidsDirection->id], $classPassSegment->activityDirections()->pluck('activity_directions.id')->all());
    }

    public function test_class_pass_segment_rejects_foreign_account_direction(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $foreignDirection = ActivityDirection::factory()->for($otherAccount)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-segments.store', $account), [
                'name' => 'Foreign direction',
                'slug' => 'foreign-direction',
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'sort_order' => 10,
                'activity_direction_ids' => [$foreignDirection->id],
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('activity_direction_ids.0');

        $this->assertFalse(ClassPassSegment::whereBelongsTo($account)->where('slug', 'foreign-direction')->exists());
    }

    public function test_non_owner_cannot_manage_class_pass_segments(): void
    {
        $manager = User::factory()->create();
        $account = Account::factory()->create();
        $classPassSegment = ClassPassSegment::factory()->for($account)->create();

        $account->users()->attach($manager->id, [
            'role' => AccountRole::Manager->value,
            'permissions' => null,
        ]);

        $this->actingAs($manager)
            ->get(route('dashboard.accounts.class-pass-segments.index', $account))
            ->assertForbidden();

        $this->actingAs($manager)
            ->get(route('dashboard.accounts.class-pass-segments.edit', [$account, $classPassSegment]))
            ->assertForbidden();
    }
}
