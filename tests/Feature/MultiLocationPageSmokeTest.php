<?php

namespace Tests\Feature;

use App\Enums\ScheduleKind;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassPassPlan;
use App\Models\ClassPassSegment;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Event;
use App\Models\Location;
use App\Models\Room;
use App\Models\SalaryModel;
use App\Models\ScheduleSeries;
use App\Models\ServiceRoom;
use App\Models\SubscriptionPlan;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MultiLocationPageSmokeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_every_account_get_route_is_explicitly_classified(): void
    {
        $actualRouteNames = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => in_array('GET', $route->methods(), true))
            ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'dashboard.accounts.'))
            ->filter(fn ($route): bool => str_contains($route->uri(), 'accounts/{account'))
            ->map(fn ($route): string => (string) $route->getName())
            ->values()
            ->all();

        $classifiedRouteNames = [
            ...$this->accountOnlyHtmlRoutes(),
            ...array_keys($this->parameterizedHtmlRoutes()),
            ...$this->nonPageGetRoutes(),
            ...$this->legacyRedirectGetRoutes(),
        ];

        $this->assertEqualsCanonicalizing($actualRouteNames, $classifiedRouteNames);
        $this->assertCount(101, $classifiedRouteNames);
    }

    public function test_every_account_html_page_renders_for_single_and_multi_location_studios(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        foreach ([1, 2] as $activeLocationCount) {
            $fixtures = $this->createAccountFixtures($activeLocationCount);
            $account = $fixtures['account'];
            $this->actingAs($fixtures['owner']);

            foreach ($this->accountOnlyHtmlRoutes() as $routeName) {
                $this->assertPageRenders(
                    route($routeName, $account),
                    $routeName,
                    $activeLocationCount > 1,
                );
            }

            foreach ($this->parameterizedHtmlRoutes() as $routeName => $fixtureKey) {
                $this->assertPageRenders(
                    route($routeName, [$account, $fixtures[$fixtureKey]]),
                    $routeName,
                    $activeLocationCount > 1,
                );
            }
        }
    }

    /**
     * @return array<string, Account|ActivityDirection|ClassPassPlan|ClassPassSegment|ClassType|Customer|CustomerClassPass|Event|Location|Room|SalaryModel|ScheduleSeries|ServiceRoom|Trainer|User>
     */
    private function createAccountFixtures(int $activeLocationCount): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'allow_rtsp_cameras' => true,
            'enable_people_counter' => true,
            'enabled_schedule_kinds' => array_map(
                fn (ScheduleKind $scheduleKind): string => $scheduleKind->value,
                ScheduleKind::cases(),
            ),
            'trainer_private_timeframes_enabled' => true,
        ]);
        $account->addOwner($owner);
        $subscriptionPlan = SubscriptionPlan::factory()->create([
            'plan_type' => 'standard',
            'is_active' => true,
        ]);
        $account->subscription()->create([
            'subscription_plan_id' => $subscriptionPlan->id,
            'status' => SubscriptionStatus::Active->value,
            'started_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
        ]);
        $locations = Location::factory()
            ->count($activeLocationCount)
            ->for($account)
            ->create(['is_active' => true]);
        $location = $locations->first();
        $activityDirection = ActivityDirection::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $cameraRoom = Room::factory()->for($account)->for($location)->create([
            'rtsp_url' => 'rtsp://127.0.0.1/test-stream',
            'rtsp_enabled' => true,
        ]);
        $serviceRoom = ServiceRoom::factory()->for($account)->for($location)->create();
        $trainer = Trainer::factory()->for($account)->for($owner, 'user')->create();
        $trainer->locations()->attach($locations->modelKeys(), ['account_id' => $account->id]);
        $groupClassType = $this->createClassType($account, $activityDirection, ScheduleKind::GroupClass);
        $privateLessonClassType = $this->createClassType($account, $activityDirection, ScheduleKind::PrivateLesson);
        $roomRentalClassType = $this->createClassType($account, $activityDirection, ScheduleKind::RoomRental);
        $internalClassType = $this->createClassType($account, $activityDirection, ScheduleKind::InternalClass);
        $classPassPlan = ClassPassPlan::factory()->for($account)->create();
        $classPassPlan->classTypes()->attach($groupClassType);
        $classPassSegment = ClassPassSegment::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();
        $customerClassPass = CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($classPassPlan)
            ->create(['issued_location_id' => $location->id]);
        $event = Event::factory()->published()->for($account)->create();
        $salaryModel = SalaryModel::factory()->for($account)->create();
        $scheduleSeries = ScheduleSeries::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($groupClassType, 'classType')
            ->for($trainer)
            ->create();

        return [
            'owner' => $owner,
            'account' => $account,
            'activity_direction' => $activityDirection,
            'class_pass_plan' => $classPassPlan,
            'class_pass_segment' => $classPassSegment,
            'customer_class_pass' => $customerClassPass,
            'customer' => $customer,
            'event' => $event,
            'group_class_type' => $groupClassType,
            'internal_class_type' => $internalClassType,
            'location' => $location,
            'private_lesson_class_type' => $privateLessonClassType,
            'trainer' => $trainer,
            'room_rental_class_type' => $roomRentalClassType,
            'room' => $room,
            'camera_room' => $cameraRoom,
            'salary_model' => $salaryModel,
            'schedule_series' => $scheduleSeries,
            'service_room' => $serviceRoom,
        ];
    }

    private function createClassType(
        Account $account,
        ActivityDirection $activityDirection,
        ScheduleKind $scheduleKind,
    ): ClassType {
        return ClassType::factory()
            ->for($account)
            ->for($activityDirection)
            ->create(['schedule_kind' => $scheduleKind->value]);
    }

    private function assertPageRenders(string $url, string $routeName, bool $expectsLocationSelector): void
    {
        $response = $this->get($url);

        $this->assertSame(200, $response->getStatusCode(), $routeName);
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'), $routeName);

        if ($expectsLocationSelector) {
            $this->assertStringContainsString('name="location_context"', $response->getContent(), $routeName);
        } else {
            $this->assertStringNotContainsString('name="location_context"', $response->getContent(), $routeName);
            $this->assertStringNotContainsString(__('app.account_wide'), $response->getContent(), $routeName);
        }
    }

    /**
     * @return list<string>
     */
    private function accountOnlyHtmlRoutes(): array
    {
        return [
            'dashboard.accounts.activity-directions.create',
            'dashboard.accounts.activity-directions.index',
            'dashboard.accounts.activity-logs.index',
            'dashboard.accounts.cameras.index',
            'dashboard.accounts.cash.index',
            'dashboard.accounts.class-pass-plans.create',
            'dashboard.accounts.class-pass-plans.index',
            'dashboard.accounts.class-pass-segments.create',
            'dashboard.accounts.class-pass-segments.index',
            'dashboard.accounts.customer-class-passes.index',
            'dashboard.accounts.customer-notification-logs.index',
            'dashboard.accounts.customers.create',
            'dashboard.accounts.customers.index',
            'dashboard.accounts.events.create',
            'dashboard.accounts.events.index',
            'dashboard.accounts.expenses.index',
            'dashboard.accounts.general-settings.edit',
            'dashboard.accounts.group-classes.create',
            'dashboard.accounts.group-classes.index',
            'dashboard.accounts.integrations.index',
            'dashboard.accounts.internal-classes.create',
            'dashboard.accounts.internal-classes.index',
            'dashboard.accounts.locations.create',
            'dashboard.accounts.locations.index',
            'dashboard.accounts.notification-settings.edit',
            'dashboard.accounts.owner-profile.edit',
            'dashboard.accounts.payments.index',
            'dashboard.accounts.payroll.index',
            'dashboard.accounts.private-lessons.create',
            'dashboard.accounts.private-lessons.index',
            'dashboard.accounts.qr-links.show',
            'dashboard.accounts.reports.earnings',
            'dashboard.accounts.reports.financial',
            'dashboard.accounts.reports.index',
            'dashboard.accounts.reports.people-counter',
            'dashboard.accounts.reports.rentals',
            'dashboard.accounts.reports.trainers',
            'dashboard.accounts.reports.unknown-presence',
            'dashboard.accounts.reports.unpaid-class-payments',
            'dashboard.accounts.room-rentals.create',
            'dashboard.accounts.room-rentals.index',
            'dashboard.accounts.rooms.create',
            'dashboard.accounts.rooms.index',
            'dashboard.accounts.salary-models.create',
            'dashboard.accounts.salary-models.index',
            'dashboard.accounts.schedule-series.create',
            'dashboard.accounts.schedule-series.index',
            'dashboard.accounts.scheduled-classes-history.index',
            'dashboard.accounts.scheduled-classes.index',
            'dashboard.accounts.service-rooms.create',
            'dashboard.accounts.service-rooms.index',
            'dashboard.accounts.show',
            'dashboard.accounts.sms-account.show',
            'dashboard.accounts.tariff-payments.show',
            'dashboard.accounts.telegram-connections.index',
            'dashboard.accounts.trainer-private-timeframes.mine',
            'dashboard.accounts.trainer-telegram-alert-logs.index',
            'dashboard.accounts.trainer-types.index',
            'dashboard.accounts.trainers.create',
            'dashboard.accounts.trainers.index',
            'dashboard.accounts.website-leads.index',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parameterizedHtmlRoutes(): array
    {
        return [
            'dashboard.accounts.activity-directions.edit' => 'activity_direction',
            'dashboard.accounts.class-pass-plans.edit' => 'class_pass_plan',
            'dashboard.accounts.class-pass-segments.edit' => 'class_pass_segment',
            'dashboard.accounts.customer-class-passes.edit' => 'customer_class_pass',
            'dashboard.accounts.customers.edit' => 'customer',
            'dashboard.accounts.events.edit' => 'event',
            'dashboard.accounts.events.orders.index' => 'event',
            'dashboard.accounts.events.scanner' => 'event',
            'dashboard.accounts.group-classes.edit' => 'group_class_type',
            'dashboard.accounts.internal-classes.edit' => 'internal_class_type',
            'dashboard.accounts.locations.edit' => 'location',
            'dashboard.accounts.private-lessons.edit' => 'private_lesson_class_type',
            'dashboard.accounts.reports.trainers.salary' => 'trainer',
            'dashboard.accounts.room-rentals.edit' => 'room_rental_class_type',
            'dashboard.accounts.rooms.edit' => 'room',
            'dashboard.accounts.rooms.people-counter-mask.edit' => 'camera_room',
            'dashboard.accounts.salary-models.edit' => 'salary_model',
            'dashboard.accounts.schedule-series.edit' => 'schedule_series',
            'dashboard.accounts.service-rooms.edit' => 'service_room',
            'dashboard.accounts.trainers.edit' => 'trainer',
            'dashboard.accounts.trainers.private-timeframes.edit' => 'trainer',
        ];
    }

    /**
     * @return list<string>
     */
    private function nonPageGetRoutes(): array
    {
        return [
            'dashboard.accounts.assistant.show',
            'dashboard.accounts.assistant.attachments.show',
            'dashboard.accounts.customer-telegram-bot.webhook-status',
            'dashboard.accounts.customers.example',
            'dashboard.accounts.customers.export',
            'dashboard.accounts.customers.search',
            'dashboard.accounts.people-counter-samples.image',
            'dashboard.accounts.quick-bookings.group-availability',
            'dashboard.accounts.quick-bookings.manual-availability',
            'dashboard.accounts.reports.trainers.private-lessons',
            'dashboard.accounts.rooms.people-counter-mask.snapshot',
            'dashboard.accounts.scheduled-classes.corrections.pass-preview',
            'dashboard.accounts.trainers.substitutions.classes',
        ];
    }

    /**
     * @return list<string>
     */
    private function legacyRedirectGetRoutes(): array
    {
        return [
            'dashboard.accounts.brand.edit',
            'dashboard.accounts.class-types.create',
            'dashboard.accounts.class-types.edit',
            'dashboard.accounts.class-types.index',
            'dashboard.accounts.edit',
            'dashboard.accounts.studio-settings.index',
        ];
    }
}
