<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\ClassPassSegment;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\TrainerType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublicPriceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_price_page_groups_active_plans_by_schedule_kind(): void
    {
        [$account, $location, $plans] = $this->priceContext();

        $response = $this->get(route('public.price', [$account->slug, $location->slug]));

        $response->assertOk()
            ->assertSee(__('app.public_price_title'))
            ->assertSee(__('app.group_classes_price'))
            ->assertSee(__('app.private_lessons_price'))
            ->assertSee(__('app.room_rental_price'))
            ->assertSee(__('app.powered_by_ladna'))
            ->assertSee('brand/ladna-mark.svg', false)
            ->assertDontSee(__('app.terms_of_service'))
            ->assertSee($plans['group']->name)
            ->assertSee(__('app.validity_days_after_first_class'))
            ->assertSee(__('app.total_validity_days'))
            ->assertSee($plans['private']->name)
            ->assertSee($plans['rental']->name)
            ->assertSee(__('app.public_contact_title', ['studio' => $account->name]))
            ->assertSee('https://instagram.example/price-studio', false)
            ->assertSee('assets/social/instagram.svg', false)
            ->assertDontSee($plans['inactive']->name);
    }

    public function test_price_api_returns_embeddable_grouped_json(): void
    {
        [$account, $location, $plans] = $this->priceContext();

        $response = $this->getJson("/api/v1/public/{$account->slug}/{$location->slug}/price");

        $response->assertOk()
            ->assertJsonPath('data.0.key', 'group_class')
            ->assertJsonPath('data.0.sections.0.key', 'all')
            ->assertJsonPath('data.0.sections.0.title', '')
            ->assertJsonPath('data.0.sections.0.plans.0.name', $plans['group']->name)
            ->assertJsonPath('data.0.sections.0.plans.0.schedule_kind', 'group_class')
            ->assertJsonPath('data.0.sections.0.plans.0.total_validity_days', 120)
            ->assertJsonPath('data.1.key', 'private_lesson')
            ->assertJsonPath('data.1.sections.0.key', 'all')
            ->assertJsonPath('data.1.sections.0.title', '')
            ->assertJsonPath('data.1.sections.0.plans.0.schedule_kind', 'private_lesson')
            ->assertJsonPath('data.1.sections.0.plans.0.trainer_types.0.name', 'Top trainer')
            ->assertJsonPath('data.2.key', 'room_rental')
            ->assertJsonPath('data.2.sections.0.key', 'all')
            ->assertJsonPath('data.2.sections.0.title', '')
            ->assertJsonPath('data.2.sections.0.plans.0.schedule_kind', 'room_rental')
            ->assertJsonPath('data.2.sections.0.plans.0.rooms.0.slug', 'big-hall')
            ->assertJsonMissing(['name' => $plans['inactive']->name])
            ->assertJsonMissing(['key' => 'morning'])
            ->assertJsonMissing(['key' => 'full_day'])
            ->assertJsonMissing(['key' => 'big-hall'])
            ->assertJsonMissing(['title' => 'Top trainer']);
    }

    public function test_logged_in_customer_can_return_to_customer_portal_from_public_price(): void
    {
        [$account, $location] = $this->priceContext();
        $customer = Customer::factory()->for($account)->create(['name' => 'Olena Client']);

        $this->actingAs($customer, 'customer')
            ->get(route('public.price', [$account->slug, $location->slug]))
            ->assertOk()
            ->assertSee(__('app.public_schedule_logged_in_as', ['name' => $customer->name]))
            ->assertSee(__('app.customer_portal'))
            ->assertSee('href="'.route('customer.dashboard', $account->slug).'"', false);
    }

    public function test_public_price_does_not_expose_customer_session_from_another_studio(): void
    {
        [$account, $location] = $this->priceContext();
        $otherAccount = Account::factory()->create();
        $customer = Customer::factory()->for($otherAccount)->create(['name' => 'Other Studio Client']);

        $this->actingAs($customer, 'customer')
            ->get(route('public.price', [$account->slug, $location->slug]))
            ->assertOk()
            ->assertDontSee($customer->name)
            ->assertDontSee('href="'.route('customer.dashboard', $account->slug).'"', false)
            ->assertSee('href="'.route('customer.studio.login', $account->slug).'"', false);
    }

    public function test_public_price_groups_segmented_plans_inside_schedule_kind(): void
    {
        [$account, $location, $plans] = $this->priceContext();
        $classType = $plans['group']->classTypes()->firstOrFail();
        $morningSegment = ClassPassSegment::factory()->for($account)->create([
            'name' => 'Morning passes',
            'slug' => 'morning-passes',
            'schedule_kind' => 'group_class',
            'sort_order' => 10,
        ]);
        $segment = ClassPassSegment::factory()->for($account)->create([
            'name' => 'Kids passes',
            'slug' => 'kids-passes',
            'schedule_kind' => 'group_class',
            'sort_order' => 20,
        ]);
        $morningPlan = ClassPassPlan::factory()->for($account)->for($morningSegment)->create([
            'name' => 'Morning 8 classes',
            'slug' => 'morning-8-classes',
            'schedule_kind' => 'group_class',
            'sort_order' => 15,
        ]);
        $morningPlan->classTypes()->sync([$classType->id]);
        $segmentedPlan = ClassPassPlan::factory()->for($account)->for($segment)->create([
            'name' => 'Kids 8 classes',
            'slug' => 'kids-8-classes',
            'schedule_kind' => 'group_class',
            'sort_order' => 5,
        ]);
        $segmentedPlan->classTypes()->sync([$classType->id]);

        $this->get(route('public.price', [$account->slug, $location->slug]))
            ->assertOk()
            ->assertSee(route('public.class-pass-plans.buy', [$account->slug, $location->slug, $plans['group']->slug]), false)
            ->assertDontSee(__('app.without_class_pass_segment'))
            ->assertDontSee(__('app.morning_format'))
            ->assertDontSee(__('app.full_day'))
            ->assertSee('Morning passes')
            ->assertSee('Kids passes')
            ->assertSee($segmentedPlan->name);

        $this->getJson("/api/v1/public/{$account->slug}/{$location->slug}/price")
            ->assertOk()
            ->assertJsonPath('data.0.key', 'group_class')
            ->assertJsonPath('data.0.sections.0.key', 'without_segment')
            ->assertJsonPath('data.0.sections.0.title', '')
            ->assertJsonPath('data.0.sections.0.plans.0.segment', null)
            ->assertJsonPath('data.0.sections.1.key', 'segment:morning-passes')
            ->assertJsonPath('data.0.sections.1.title', 'Morning passes')
            ->assertJsonPath('data.0.sections.1.plans.0.name', $morningPlan->name)
            ->assertJsonPath('data.0.sections.1.plans.0.segment.slug', 'morning-passes')
            ->assertJsonPath('data.0.sections.2.key', 'segment:kids-passes')
            ->assertJsonPath('data.0.sections.2.title', 'Kids passes')
            ->assertJsonPath('data.0.sections.2.plans.0.name', $segmentedPlan->name)
            ->assertJsonPath('data.0.sections.2.plans.0.segment.slug', 'kids-passes');
    }

    public function test_public_price_treats_inactive_and_mismatched_segments_as_unsegmented(): void
    {
        [$account, $location, $plans] = $this->priceContext();
        $classType = $plans['group']->classTypes()->firstOrFail();
        $inactiveSegment = ClassPassSegment::factory()->for($account)->create([
            'name' => 'Inactive segment',
            'slug' => 'inactive-segment',
            'schedule_kind' => 'group_class',
            'is_active' => false,
        ]);
        $mismatchedSegment = ClassPassSegment::factory()->for($account)->create([
            'name' => 'Private segment',
            'slug' => 'private-segment',
            'schedule_kind' => 'private_lesson',
        ]);
        $inactiveSegmentPlan = ClassPassPlan::factory()->for($account)->for($inactiveSegment)->create([
            'name' => 'Inactive segment plan',
            'slug' => 'inactive-segment-plan',
            'schedule_kind' => 'group_class',
            'sort_order' => 11,
        ]);
        $mismatchedSegmentPlan = ClassPassPlan::factory()->for($account)->for($mismatchedSegment)->create([
            'name' => 'Mismatched segment plan',
            'slug' => 'mismatched-segment-plan',
            'schedule_kind' => 'group_class',
            'sort_order' => 12,
        ]);
        $inactiveSegmentPlan->classTypes()->sync([$classType->id]);
        $mismatchedSegmentPlan->classTypes()->sync([$classType->id]);

        $this->getJson("/api/v1/public/{$account->slug}/{$location->slug}/price")
            ->assertOk()
            ->assertJsonPath('data.0.sections.0.key', 'all')
            ->assertJsonPath('data.0.sections.0.title', '')
            ->assertJsonPath('data.0.sections.0.plans.0.name', $plans['group']->name)
            ->assertJsonPath('data.0.sections.0.plans.0.segment', null)
            ->assertJsonPath('data.0.sections.0.plans.1.name', $inactiveSegmentPlan->name)
            ->assertJsonPath('data.0.sections.0.plans.1.segment', null)
            ->assertJsonPath('data.0.sections.0.plans.2.name', $mismatchedSegmentPlan->name)
            ->assertJsonPath('data.0.sections.0.plans.2.segment', null)
            ->assertJsonMissing(['key' => 'segment:inactive-segment'])
            ->assertJsonMissing(['key' => 'segment:private-segment']);
    }

    /**
     * @return array{0: Account, 1: Location, 2: array<string, ClassPassPlan>}
     */
    private function priceContext(): array
    {
        $account = Account::factory()->create([
            'slug' => 'price-api-studio',
            'default_language' => 'en',
            'default_currency' => 'UAH',
            'support_instagram_url' => 'https://instagram.example/price-studio',
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'main-studio', 'name' => 'Main studio']);
        $room = Room::factory()->for($account)->for($location)->create(['slug' => 'big-hall', 'name' => 'Big hall']);
        $groupType = ClassType::factory()->for($account)->create(['name' => 'Pole group', 'schedule_kind' => 'group_class']);
        $privateType = ClassType::factory()->for($account)->create(['name' => 'Private pole', 'schedule_kind' => 'private_lesson']);
        $rentalType = ClassType::factory()->for($account)->create(['name' => 'Rental', 'schedule_kind' => 'room_rental']);
        $trainerType = TrainerType::factory()->for($account)->create(['name' => 'Top trainer']);

        $groupPlan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Group 8 classes',
            'sort_order' => 10,
            'total_validity_days' => 120,
        ]);
        $groupPlan->classTypes()->sync([$groupType->id]);

        $privatePlan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Private top trainer',
            'schedule_kind' => 'private_lesson',
            'sort_order' => 20,
            'sessions_count' => 1,
        ]);
        $privatePlan->classTypes()->sync([$privateType->id]);
        $privatePlan->trainerTypes()->sync([$trainerType->id]);

        $rentalPlan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Big hall rental',
            'schedule_kind' => 'room_rental',
            'sort_order' => 30,
            'sessions_count' => 1,
        ]);
        $rentalPlan->classTypes()->sync([$rentalType->id]);
        $rentalPlan->rooms()->sync([$room->id]);

        $inactivePlan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Inactive hidden plan',
            'is_active' => false,
        ]);
        $inactivePlan->classTypes()->sync([$groupType->id]);

        return [$account, $location, [
            'group' => $groupPlan,
            'private' => $privatePlan,
            'rental' => $rentalPlan,
            'inactive' => $inactivePlan,
        ]];
    }
}
