<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Location;
use App\Models\Room;
use App\Models\User;
use App\Support\WorkingLocationContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WorkingLocationContextTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_select_an_account_specific_working_location(): void
    {
        [$owner, $account, $main, $secondary] = $this->multiLocationAccount();
        $cookieName = 'ladna_working_location_'.$account->id;

        $response = $this->actingAs($owner)->post(
            route('dashboard.accounts.working-location.update', $account),
            [
                'location_context' => (string) $secondary->id,
                'redirect_to' => route('dashboard.accounts.rooms.index', [
                    'account' => $account,
                    'location_id' => $main->id,
                    'page' => 2,
                ], absolute: false),
            ],
        );

        $response
            ->assertRedirectContains('/app/dashboard/accounts/'.$account->id.'/rooms')
            ->assertRedirectContains('location_context='.$secondary->id)
            ->assertCookie($cookieName, (string) $secondary->id);
        $this->assertStringNotContainsString('location_id=', (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString('page=', (string) $response->headers->get('Location'));
    }

    public function test_working_location_defaults_operational_pages_and_explicit_all_overrides_it(): void
    {
        [$owner, $account, $main, $secondary] = $this->multiLocationAccount();
        $mainRoom = Room::factory()->for($account)->for($main)->create(['name' => 'Main room']);
        $secondaryRoom = Room::factory()->for($account)->for($secondary)->create(['name' => 'Secondary room']);
        $mainEvent = Event::factory()->published()->for($account)->for($main)->create([
            'title' => 'Central showcase',
            'venue_kind' => 'studio',
        ]);
        $secondaryEvent = Event::factory()->published()->for($account)->for($secondary)->create([
            'title' => 'Riverside showcase',
            'venue_kind' => 'studio',
        ]);
        $customer = Customer::factory()->for($account)->create();
        $cookieName = 'ladna_working_location_'.$account->id;

        $roomsResponse = $this->actingAs($owner)
            ->withCookie($cookieName, (string) $secondary->id)
            ->get(route('dashboard.accounts.rooms.index', $account));

        $roomsResponse
            ->assertOk()
            ->assertSee(__('app.work_with_location'))
            ->assertSee($main->name)
            ->assertSee($secondary->name);
        $this->assertSame($secondary->id, $roomsResponse->viewData('selectedLocationId'));
        $this->assertSame([$secondaryRoom->id], $roomsResponse->viewData('rooms')->modelKeys());

        $scheduleResponse = $this->get(route('dashboard.accounts.scheduled-classes.index', $account));

        $scheduleResponse
            ->assertOk()
            ->assertSee('type="checkbox" name="locations[]"', false);
        $this->assertSame([$secondary->id], $scheduleResponse->viewData('selectedLocationIds'));
        $this->assertSame([$secondaryRoom->id], $scheduleResponse->viewData('filterRooms')->modelKeys());
        $this->assertSame($secondary->id, $scheduleResponse->viewData('workingLocationId'));

        $dashboardResponse = $this->get(route('dashboard.accounts.show', $account));

        $dashboardResponse
            ->assertOk()
            ->assertViewHas('hasMultipleWorkingLocations', true)
            ->assertSee(__('app.account_wide'))
            ->assertSee(__('app.dashboard_mixed_scope_notice'));

        $websiteLeadsResponse = $this->get(route('dashboard.accounts.website-leads.index', $account));

        $websiteLeadsResponse->assertOk();
        $this->assertSame($secondary->id, $websiteLeadsResponse->viewData('workingLocationId'));

        $customerEditResponse = $this->get(route('dashboard.accounts.customers.edit', [$account, $customer]));

        $customerEditResponse->assertOk();
        $this->assertSame($secondary->id, $customerEditResponse->viewData('workingLocationId'));

        $createResponse = $this->get(route('dashboard.accounts.rooms.create', $account));

        $createResponse->assertOk();
        $this->assertSame($secondary->id, $createResponse->viewData('room')->location_id);

        $eventsResponse = $this->get(route('dashboard.accounts.events.index', $account));

        $eventsResponse->assertOk();
        $this->assertSame([$secondaryEvent->id], $eventsResponse->viewData('events')->getCollection()->modelKeys());
        $this->assertNotContains($mainEvent->id, $eventsResponse->viewData('events')->getCollection()->modelKeys());

        $eventCreateResponse = $this->get(route('dashboard.accounts.events.create', $account));

        $eventCreateResponse->assertOk();
        $this->assertSame($secondary->id, $eventCreateResponse->viewData('event')->location_id);

        $this->app->forgetScopedInstances();
        $allRoomsResponse = $this->get(route('dashboard.accounts.rooms.index', [
            'account' => $account,
            'location_id' => '',
        ]));

        $allRoomsResponse->assertOk();
        $this->assertNull($allRoomsResponse->viewData('selectedLocationId'));
        $this->assertEqualsCanonicalizing(
            [$mainRoom->id, $secondaryRoom->id],
            $allRoomsResponse->viewData('rooms')->modelKeys(),
        );
    }

    public function test_inactive_or_foreign_location_cannot_become_the_working_context(): void
    {
        [$owner, $account, $main] = $this->multiLocationAccount();
        $inactive = Location::factory()->for($account)->create(['is_active' => false]);
        $foreignLocation = Location::factory()->create();
        $cookieName = 'ladna_working_location_'.$account->id;

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.working-location.update', $account), [
                'location_context' => (string) $inactive->id,
            ])
            ->assertSessionHasErrors('location_context');

        $this->post(route('dashboard.accounts.working-location.update', $account), [
            'location_context' => (string) $foreignLocation->id,
        ])->assertSessionHasErrors('location_context');

        $response = $this->withCookie($cookieName, (string) $inactive->id)
            ->get(route('dashboard.accounts.rooms.index', $account));

        $response->assertOk();
        $this->assertNull($response->viewData('selectedLocationId'));

        $this->app->forgetScopedInstances();
        $queryOverrideResponse = $this->get(route('dashboard.accounts.rooms.index', [
            'account' => $account,
            WorkingLocationContext::QueryKey => $main->id,
        ]));

        $queryOverrideResponse
            ->assertOk()
            ->assertCookie($cookieName, (string) $main->id);
        $this->assertSame($main->id, $queryOverrideResponse->viewData('selectedLocationId'));
    }

    public function test_class_pass_plans_show_account_wide_and_location_specific_availability(): void
    {
        [$owner, $account, $main, $secondary] = $this->multiLocationAccount();
        $mainRoom = Room::factory()->for($account)->for($main)->create();
        $secondaryRoom = Room::factory()->for($account)->for($secondary)->create();
        $accountWidePlan = ClassPassPlan::factory()->for($account)->create(['name' => 'Anywhere plan']);
        $mainPlan = ClassPassPlan::factory()->for($account)->create(['name' => 'Main only plan']);
        $secondaryPlan = ClassPassPlan::factory()->for($account)->create(['name' => 'Secondary only plan']);
        $mainPlan->rooms()->attach($mainRoom);
        $secondaryPlan->rooms()->attach($secondaryRoom);

        $response = $this->actingAs($owner)
            ->withCookie('ladna_working_location_'.$account->id, (string) $main->id)
            ->get(route('dashboard.accounts.class-pass-plans.index', $account));

        $response
            ->assertOk()
            ->assertSee($accountWidePlan->name)
            ->assertSee($mainPlan->name)
            ->assertDontSee($secondaryPlan->name)
            ->assertSee(__('app.available_at_all_locations'))
            ->assertSee(__('app.available_at_locations', ['locations' => $main->name]));
    }

    public function test_single_location_accounts_hide_redundant_scope_controls_and_keep_create_defaults(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['name' => 'Only studio']);
        $room = Room::factory()->for($account)->for($location)->create();
        $accountWidePlan = ClassPassPlan::factory()->for($account)->create(['name' => 'Anywhere plan']);
        $locationPlan = ClassPassPlan::factory()->for($account)->create(['name' => 'Only studio plan']);
        $locationPlan->rooms()->attach($room);

        $dashboardResponse = $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account));

        $dashboardResponse
            ->assertOk()
            ->assertViewHas('hasMultipleWorkingLocations', false)
            ->assertDontSee(__('app.all_locations'))
            ->assertDontSee(__('app.account_wide'))
            ->assertDontSee(__('app.dashboard_mixed_scope_notice'))
            ->assertDontSee('name="location_context"', false);

        $classPassPlansResponse = $this->get(route('dashboard.accounts.class-pass-plans.index', $account));

        $classPassPlansResponse
            ->assertOk()
            ->assertViewHas('hasMultipleWorkingLocations', false)
            ->assertSee($accountWidePlan->name)
            ->assertSee($locationPlan->name)
            ->assertDontSee(__('app.available_at_all_locations'))
            ->assertDontSee(__('app.available_at_locations', ['locations' => $location->name]));

        $scheduleResponse = $this->get(route('dashboard.accounts.scheduled-classes.index', $account));

        $scheduleResponse
            ->assertOk()
            ->assertDontSee('type="checkbox" name="locations[]"', false)
            ->assertSee('type="hidden" name="locations[]" value="'.$location->id.'"', false);

        $submittedScheduleResponse = $this->get(route('dashboard.accounts.scheduled-classes.index', [
            'account' => $account,
            'filters_submitted' => 1,
            'locations' => [$location->id],
        ]));

        $submittedScheduleResponse->assertOk();
        $this->assertSame([$location->id], $submittedScheduleResponse->viewData('selectedLocationIds'));

        $historyResponse = $this->get(route('dashboard.accounts.scheduled-classes-history.index', $account));

        $historyResponse
            ->assertOk()
            ->assertDontSee('type="checkbox" name="locations[]"', false)
            ->assertSee('type="hidden" name="locations[]" value="'.$location->id.'"', false);

        $createResponse = $this->get(route('dashboard.accounts.rooms.create', $account));

        $createResponse->assertOk();
        $this->assertSame($location->id, $createResponse->viewData('room')->location_id);
    }

    public function test_history_keeps_location_filter_when_an_inactive_location_also_exists(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $activeLocation = Location::factory()->for($account)->create(['name' => 'Active studio']);
        $inactiveLocation = Location::factory()->for($account)->create([
            'name' => 'Former studio',
            'is_active' => false,
        ]);

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes-history.index', $account));

        $response
            ->assertOk()
            ->assertSee('type="checkbox" name="locations[]"', false)
            ->assertSee($activeLocation->name)
            ->assertSee($inactiveLocation->name);
    }

    public function test_account_wide_page_badges_only_show_for_multiple_working_locations(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $this->actingAs($owner);

        foreach ($this->accountWidePageRoutes($account) as $route) {
            $response = $this->get($route);

            $response
                ->assertOk()
                ->assertDontSee(__('app.account_wide'));
        }

        $websiteLeadsResponse = $this->get(route('dashboard.accounts.website-leads.index', $account));
        $this->assertSame($location->id, $websiteLeadsResponse->viewData('workingLocationId'));

        Location::factory()->for($account)->create();
        $this->app->forgetScopedInstances();

        foreach ($this->accountWidePageRoutes($account) as $route) {
            $this->get($route)
                ->assertOk()
                ->assertSee(__('app.account_wide'));
        }
    }

    /**
     * @return array{0: User, 1: Account, 2: Location, 3: Location}
     */
    private function multiLocationAccount(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $main = Location::factory()->for($account)->create(['name' => 'Central']);
        $secondary = Location::factory()->for($account)->create(['name' => 'Riverside']);

        return [$owner, $account, $main, $secondary];
    }

    /**
     * @return list<string>
     */
    private function accountWidePageRoutes(Account $account): array
    {
        return [
            route('dashboard.accounts.activity-directions.index', $account),
            route('dashboard.accounts.group-classes.index', $account),
            route('dashboard.accounts.customer-class-passes.index', $account),
            route('dashboard.accounts.customers.index', $account),
            route('dashboard.accounts.website-leads.index', $account),
        ];
    }
}
