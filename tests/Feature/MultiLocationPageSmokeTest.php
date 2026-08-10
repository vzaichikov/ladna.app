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
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRubric;
use App\Models\FestivalScoreSheet;
use App\Models\FestivalSeries;
use App\Models\FestivalTariffPackage;
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
            ...$this->accountWideHtmlRoutes(),
            ...array_keys($this->parameterizedHtmlRoutes()),
            ...array_keys($this->parameterizedAccountWideHtmlRoutes()),
            ...$this->nonPageGetRoutes(),
            ...$this->legacyRedirectGetRoutes(),
        ];

        $this->assertEqualsCanonicalizing($actualRouteNames, $classifiedRouteNames);
        $this->assertCount(123, $classifiedRouteNames);
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

            foreach ($this->accountWideHtmlRoutes() as $routeName) {
                $parameters = $routeName === 'dashboard.accounts.festivals.create'
                    ? [$account, 'purchase' => $fixtures['festival_purchase']->id]
                    : $account;
                $this->assertPageRenders(route($routeName, $parameters), $routeName, false);
            }

            foreach ($this->parameterizedAccountWideHtmlRoutes() as $routeName => $fixtureKey) {
                $parameters = $routeName === 'dashboard.accounts.festivals.score-sheets.edit'
                    ? [$account, $fixtures['festival_edition'], $fixtures['festival_score_sheet']]
                    : [$account, $fixtures[$fixtureKey]];
                $this->assertPageRenders(route($routeName, $parameters), $routeName, false);
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
            'enable_festivals' => true,
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
        $festivalSeries = FestivalSeries::factory()->for($account)->create();
        $festivalPackage = FestivalTariffPackage::factory()->create([
            'subscription_plan_id' => $subscriptionPlan->id,
            'name' => 'Smoke '.str()->random(8),
        ]);
        $festivalPurchase = FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $subscriptionPlan->id,
            'festival_tariff_package_id' => $festivalPackage->id,
            'created_by_user_id' => $owner->id,
        ]);
        $festivalEdition = FestivalEdition::factory()->for($festivalSeries)->create(['account_id' => $account->id]);
        $festivalCategory = FestivalCategory::factory()->for($festivalEdition)->create(['account_id' => $account->id]);
        $festivalPortalUser = FestivalPortalUser::factory()->for($account)->create();
        $festivalEntry = FestivalEntry::factory()->for($festivalCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $festivalEdition->id,
            'festival_portal_user_id' => $festivalPortalUser->id,
        ]);
        $festivalJudgeAssignment = FestivalJudgeAssignment::factory()->for($festivalEdition)->for($owner)->create(['account_id' => $account->id]);
        $festivalJudgeAssignment->categories()->attach($festivalCategory, ['account_id' => $account->id]);
        $festivalRubric = FestivalRubric::factory()->for($festivalEdition)->create(['account_id' => $account->id, 'festival_category_id' => $festivalCategory->id]);
        $festivalSection = $festivalRubric->sections()->create(['account_id' => $account->id, 'name' => 'Technique', 'weight' => 1]);
        $festivalSection->criteria()->create(['account_id' => $account->id, 'name' => 'Execution', 'max_score' => 10, 'weight' => 1]);
        $festivalScoreSheet = FestivalScoreSheet::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $festivalEntry->id,
            'festival_judge_assignment_id' => $festivalJudgeAssignment->id,
            'festival_rubric_id' => $festivalRubric->id,
        ]);
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
            'festival_edition' => $festivalEdition,
            'festival_purchase' => $festivalPurchase,
            'festival_series' => $festivalSeries,
            'festival_score_sheet' => $festivalScoreSheet,
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
     * @return list<string>
     */
    private function accountWideHtmlRoutes(): array
    {
        return [
            'dashboard.accounts.festivals.create',
            'dashboard.accounts.festivals.index',
            'dashboard.accounts.festivals.series.create',
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
     * @return array<string, string>
     */
    private function parameterizedAccountWideHtmlRoutes(): array
    {
        return [
            'dashboard.accounts.festivals.applications' => 'festival_edition',
            'dashboard.accounts.festivals.communication' => 'festival_edition',
            'dashboard.accounts.festivals.edit' => 'festival_edition',
            'dashboard.accounts.festivals.judging.index' => 'festival_edition',
            'dashboard.accounts.festivals.program' => 'festival_edition',
            'dashboard.accounts.festivals.scanner' => 'festival_edition',
            'dashboard.accounts.festivals.score-sheets.edit' => 'festival_score_sheet',
            'dashboard.accounts.festivals.settings' => 'festival_edition',
            'dashboard.accounts.festivals.settings.categories' => 'festival_edition',
            'dashboard.accounts.festivals.settings.classifications' => 'festival_edition',
            'dashboard.accounts.festivals.settings.content' => 'festival_edition',
            'dashboard.accounts.festivals.settings.directions' => 'festival_edition',
            'dashboard.accounts.festivals.settings.fees' => 'festival_edition',
            'dashboard.accounts.festivals.settings.requirements' => 'festival_edition',
            'dashboard.accounts.festivals.settings.workflows' => 'festival_edition',
            'dashboard.accounts.festivals.show' => 'festival_edition',
            'dashboard.accounts.festivals.tickets' => 'festival_edition',
            'dashboard.accounts.festivals.series.edit' => 'festival_series',
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
            'dashboard.accounts.festivals.submissions.download',
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
