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
            ->assertSee('data-public-price-kind-tabs', false)
            ->assertSee('data-public-price-kind-tab="group_class"', false)
            ->assertSee('data-public-price-kind-tab="private_lesson"', false)
            ->assertSee('data-public-price-kind-tab="room_rental"', false)
            ->assertSee('data-public-price-kind-panel="group_class"', false)
            ->assertSee(__('app.public_price_group_class_tab'))
            ->assertSee(__('app.public_price_private_lesson_tab'))
            ->assertSee(__('app.public_price_room_rental_tab'))
            ->assertSee(__('app.powered_by_ladna'))
            ->assertSee('brand/ladna-mark.svg', false)
            ->assertDontSee('data-customer-page-topbar', false)
            ->assertSee('data-customer-locale-switcher', false)
            ->assertSee('data-customer-footer-locale-switcher', false)
            ->assertDontSee(__('app.terms_of_service'))
            ->assertSee($plans['group']->name)
            ->assertSee(__('app.validity_days_after_first_class'))
            ->assertSee(__('app.total_validity_days'))
            ->assertSee($plans['private']->name)
            ->assertSee($plans['rental']->name)
            ->assertSee(__('app.public_contact_title', ['studio' => $account->name]))
            ->assertSee('https://instagram.example/price-studio', false)
            ->assertSee('assets/social/instagram.svg', false)
            ->assertSee('data-customer-footer-legal-links', false)
            ->assertSee('data-public-rules-footer-link', false)
            ->assertSee('data-public-offer-footer-link', false)
            ->assertSeeInOrder([
                __('app.public_contact_title', ['studio' => $account->name]),
                'data-customer-footer-legal-links',
                'data-public-rules-footer-link',
                'data-public-offer-footer-link',
                __('app.powered_by_ladna'),
            ], false)
            ->assertDontSee($plans['inactive']->name);
    }

    public function test_public_price_collapses_class_types_after_the_first_two(): void
    {
        [$account, $location, $plans] = $this->priceContext();
        $extraClassTypes = ClassType::factory()
            ->count(3)
            ->for($account)
            ->create(['schedule_kind' => 'group_class']);

        $plans['group']->classTypes()->sync(
            $plans['group']->classTypes()->pluck('class_types.id')
                ->concat($extraClassTypes->modelKeys()),
        );

        $response = $this->get(route('public.price', [$account->slug, $location->slug]));

        $response->assertOk()
            ->assertSee('data-class-type-list', false)
            ->assertSee('data-class-type-list-toggle', false)
            ->assertSee('type="button"', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee(__('app.more_class_types', ['count' => 2]));

        $this->assertSame(2, substr_count($response->getContent(), 'data-class-type-list-extra'));
    }

    public function test_public_price_embed_omits_customer_topbar_and_offer_footer(): void
    {
        [$account, $location] = $this->priceContext();

        $this->get(route('public.price.embed', [$account->slug, $location->slug]))
            ->assertOk()
            ->assertDontSee('data-customer-page-topbar', false)
            ->assertDontSee('data-customer-locale-switcher', false)
            ->assertDontSee('data-customer-footer-locale-switcher', false)
            ->assertDontSee('data-customer-footer-legal-links', false)
            ->assertDontSee('data-public-rules-footer-link', false)
            ->assertDontSee('data-public-offer-footer-link', false)
            ->assertSee(route('public.studio-rules', $account->slug), false);
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

        $response = $this->actingAs($customer, 'customer')
            ->get(route('public.price', [$account->slug, $location->slug]))
            ->assertOk()
            ->assertSee(__('app.public_schedule_logged_in_as', ['name' => $customer->name]))
            ->assertSee('data-customer-dashboard-link', false)
            ->assertDontSee(__('app.customer_portal'))
            ->assertSee('href="'.route('customer.dashboard', $account->slug).'"', false);

        $this->assertSame(
            1,
            substr_count($response->getContent(), 'href="'.route('customer.dashboard', $account->slug).'"'),
        );
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
            ->assertSee('data-public-price-segment-tabs', false)
            ->assertSee('role="tablist"', false)
            ->assertSee('data-public-price-segment-tab="without_segment"', false)
            ->assertSee('data-public-price-segment-panel="segment:morning-passes"', false)
            ->assertSee('aria-selected="true"', false)
            ->assertSee(__('app.public_price_other_options'))
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
            'studio_rules_html' => '<p>Price rules</p>',
            'public_offer_html' => '<p>Price offer</p>',
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
