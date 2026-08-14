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
use App\Models\EventTicketType;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCategory;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalContentSection;
use App\Models\FestivalDirection;
use App\Models\FestivalDocument;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalMedia;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalRubric;
use App\Models\FestivalScoreSheet;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\FestivalTariffPackage;
use App\Models\FestivalWorkflow;
use App\Models\FestivalWorkflowStep;
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
        $this->assertCount(183, $classifiedRouteNames);
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
                $parameters = $routeName === 'dashboard.accounts.events.ticket-types.edit'
                    ? [$account, $fixtures['event'], $fixtures['event_ticket_type']]
                    : [$account, $fixtures[$fixtureKey]];

                $this->assertPageRenders(
                    route($routeName, $parameters),
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
                $parameters = $this->accountWideRouteParameters($routeName, $fixtureKey, $fixtures);
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
        $eventTicketType = EventTicketType::factory()->for($account)->for($event)->create();
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
        $festivalStage = FestivalStage::factory()->for($festivalEdition)->create(['account_id' => $account->id]);
        $festivalAdmissionType = FestivalAdmissionType::factory()->for($festivalEdition)->create(['account_id' => $account->id]);
        $festivalDirection = FestivalDirection::factory()->for($festivalEdition)->create(['account_id' => $account->id]);
        $festivalWorkflow = FestivalWorkflow::factory()->for($festivalEdition)->create(['account_id' => $account->id]);
        $festivalWorkflowStep = FestivalWorkflowStep::factory()->for($festivalWorkflow, 'workflow')->create(['account_id' => $account->id]);
        $festivalCategory = FestivalCategory::factory()->for($festivalEdition)->for($festivalDirection)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $festivalWorkflow->id,
        ]);
        $festivalRequirement = FestivalRequirementDefinition::factory()->for($festivalEdition)->create([
            'account_id' => $account->id,
            'festival_category_id' => $festivalCategory->id,
            'festival_workflow_step_id' => $festivalWorkflowStep->id,
        ]);
        $festivalFee = FestivalChargeDefinition::factory()->for($festivalEdition)->create([
            'account_id' => $account->id,
            'festival_category_id' => $festivalCategory->id,
            'festival_workflow_step_id' => $festivalWorkflowStep->id,
        ]);
        $festivalContentSection = FestivalContentSection::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $festivalEdition->id,
            'key' => 'smoke-section',
            'title' => 'Smoke section',
        ]);
        $festivalDocument = FestivalDocument::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $festivalEdition->id,
            'title' => 'Smoke document',
            'path' => 'festivals/smoke.pdf',
            'original_name' => 'smoke.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
        $festivalMedia = FestivalMedia::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $festivalEdition->id,
            'kind' => 'image',
            'external_url' => 'https://example.test/festival-smoke.jpg',
            'caption' => 'Smoke media',
        ]);
        $festivalPortalUser = FestivalPortalUser::factory()->for($account)->create();
        $festivalParticipant = FestivalParticipant::factory()->create([
            'account_id' => $account->id,
            'festival_portal_user_id' => $festivalPortalUser->id,
        ]);
        $festivalEntry = FestivalEntry::factory()->for($festivalCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $festivalEdition->id,
            'festival_portal_user_id' => $festivalPortalUser->id,
            'status' => 'accepted',
            'accepted_at' => now(),
            'registration_completed_at' => now(),
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
            'event_ticket_type' => $eventTicketType,
            'festival_edition' => $festivalEdition,
            'festival_entry' => $festivalEntry,
            'festival_admission_type' => $festivalAdmissionType,
            'festival_category' => $festivalCategory,
            'festival_content_section' => $festivalContentSection,
            'festival_direction' => $festivalDirection,
            'festival_document' => $festivalDocument,
            'festival_purchase' => $festivalPurchase,
            'festival_fee' => $festivalFee,
            'festival_media' => $festivalMedia,
            'festival_participant' => $festivalParticipant,
            'festival_portal_user' => $festivalPortalUser,
            'festival_requirement' => $festivalRequirement,
            'festival_series' => $festivalSeries,
            'festival_judge_assignment' => $festivalJudgeAssignment,
            'festival_rubric' => $festivalRubric,
            'festival_score_sheet' => $festivalScoreSheet,
            'festival_stage' => $festivalStage,
            'festival_workflow' => $festivalWorkflow,
            'festival_workflow_step' => $festivalWorkflowStep,
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
        $this->assertStringContainsString('data-app-breadcrumbs', $response->getContent(), $routeName);

        if ($expectsLocationSelector) {
            $this->assertStringContainsString('name="location_context"', $response->getContent(), $routeName);
        } else {
            $this->assertStringNotContainsString('name="location_context"', $response->getContent(), $routeName);
            $this->assertStringNotContainsString(__('app.account_wide'), $response->getContent(), $routeName);
        }
    }

    /**
     * @param  array<string, mixed>  $fixtures
     * @return list<mixed>
     */
    private function accountWideRouteParameters(string $routeName, string $fixtureKey, array $fixtures): array
    {
        if ($routeName === 'dashboard.accounts.festivals.series.edit') {
            return [$fixtures['account'], $fixtures['festival_series']];
        }

        $parameters = [$fixtures['account'], $fixtures['festival_edition']];

        if ($routeName === 'dashboard.accounts.festivals.users.create') {
            return [...$parameters, 'registrant'];
        }

        if (in_array($routeName, [
            'dashboard.accounts.festivals.users.participants.edit',
            'dashboard.accounts.festivals.users.participants.archive',
        ], true)) {
            return [...$parameters, $fixtures['festival_portal_user'], $fixtures['festival_participant']];
        }

        if ($routeName === 'dashboard.accounts.festivals.workflow-steps.edit') {
            return [...$parameters, $fixtures['festival_workflow'], $fixtures['festival_workflow_step']];
        }

        if ($fixtureKey !== 'festival_edition') {
            $parameters[] = $fixtures[$fixtureKey];
        }

        return $parameters;
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
            'dashboard.accounts.events.ticket-types.index' => 'event',
            'dashboard.accounts.events.ticket-types.create' => 'event',
            'dashboard.accounts.events.ticket-types.edit' => 'event_ticket_type',
            'dashboard.accounts.events.tickets.index' => 'event',
            'dashboard.accounts.events.tickets.issue.create' => 'event',
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
            'dashboard.accounts.festivals.applications.show' => 'festival_entry',
            'dashboard.accounts.festivals.admission-types.create' => 'festival_edition',
            'dashboard.accounts.festivals.admission-types.edit' => 'festival_admission_type',
            'dashboard.accounts.festivals.communication' => 'festival_edition',
            'dashboard.accounts.festivals.edit' => 'festival_edition',
            'dashboard.accounts.festivals.judging.judges.index' => 'festival_edition',
            'dashboard.accounts.festivals.judging.judges.create' => 'festival_edition',
            'dashboard.accounts.festivals.judging.judges.edit' => 'festival_judge_assignment',
            'dashboard.accounts.festivals.judging.criteria.index' => 'festival_edition',
            'dashboard.accounts.festivals.judging.criteria.create' => 'festival_edition',
            'dashboard.accounts.festivals.judging.criteria.edit' => 'festival_rubric',
            'dashboard.accounts.festivals.judging.battle-votes.index' => 'festival_edition',
            'dashboard.accounts.festivals.judging.battles.index' => 'festival_edition',
            'dashboard.accounts.festivals.judging.score-sheets.index' => 'festival_edition',
            'dashboard.accounts.festivals.judging.score-sheets.edit' => 'festival_score_sheet',
            'dashboard.accounts.festivals.judging.results.index' => 'festival_edition',
            'dashboard.accounts.festivals.online-stream.edit' => 'festival_edition',
            'dashboard.accounts.festivals.program' => 'festival_edition',
            'dashboard.accounts.festivals.performances' => 'festival_edition',
            'dashboard.accounts.festivals.performances.show' => 'festival_entry',
            'dashboard.accounts.festivals.scanner' => 'festival_edition',
            'dashboard.accounts.festivals.settings' => 'festival_edition',
            'dashboard.accounts.festivals.settings.stages' => 'festival_edition',
            'dashboard.accounts.festivals.stages.create' => 'festival_edition',
            'dashboard.accounts.festivals.stages.edit' => 'festival_stage',
            'dashboard.accounts.festivals.settings.categories' => 'festival_edition',
            'dashboard.accounts.festivals.categories.create' => 'festival_edition',
            'dashboard.accounts.festivals.categories.edit' => 'festival_category',
            'dashboard.accounts.festivals.directions.create' => 'festival_edition',
            'dashboard.accounts.festivals.directions.edit' => 'festival_direction',
            'dashboard.accounts.festivals.workflows.create' => 'festival_edition',
            'dashboard.accounts.festivals.workflows.edit' => 'festival_workflow',
            'dashboard.accounts.festivals.workflow-steps.index' => 'festival_workflow',
            'dashboard.accounts.festivals.workflow-steps.create' => 'festival_workflow',
            'dashboard.accounts.festivals.workflow-steps.edit' => 'festival_workflow_step',
            'dashboard.accounts.festivals.requirements.create' => 'festival_edition',
            'dashboard.accounts.festivals.requirements.edit' => 'festival_requirement',
            'dashboard.accounts.festivals.charge-definitions.create' => 'festival_edition',
            'dashboard.accounts.festivals.charge-definitions.edit' => 'festival_fee',
            'dashboard.accounts.festivals.settings.content' => 'festival_edition',
            'dashboard.accounts.festivals.settings.content.sections' => 'festival_edition',
            'dashboard.accounts.festivals.content.create' => 'festival_edition',
            'dashboard.accounts.festivals.content.edit' => 'festival_content_section',
            'dashboard.accounts.festivals.settings.content.documents' => 'festival_edition',
            'dashboard.accounts.festivals.documents.create' => 'festival_edition',
            'dashboard.accounts.festivals.documents.edit' => 'festival_document',
            'dashboard.accounts.festivals.settings.content.media' => 'festival_edition',
            'dashboard.accounts.festivals.media.create' => 'festival_edition',
            'dashboard.accounts.festivals.media.edit' => 'festival_media',
            'dashboard.accounts.festivals.settings.directions' => 'festival_edition',
            'dashboard.accounts.festivals.settings.fees' => 'festival_edition',
            'dashboard.accounts.festivals.settings.requirements' => 'festival_edition',
            'dashboard.accounts.festivals.settings.workflows' => 'festival_edition',
            'dashboard.accounts.festivals.show' => 'festival_edition',
            'dashboard.accounts.festivals.tickets' => 'festival_edition',
            'dashboard.accounts.festivals.tickets.issue' => 'festival_edition',
            'dashboard.accounts.festivals.timeline.show' => 'festival_stage',
            'dashboard.accounts.festivals.users.create' => 'festival_edition',
            'dashboard.accounts.festivals.users.edit' => 'festival_portal_user',
            'dashboard.accounts.festivals.users.index' => 'festival_edition',
            'dashboard.accounts.festivals.users.participants.archive' => 'festival_participant',
            'dashboard.accounts.festivals.users.participants.create' => 'festival_portal_user',
            'dashboard.accounts.festivals.users.participants.edit' => 'festival_participant',
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
            'dashboard.accounts.festivals.submissions.view',
            'dashboard.accounts.festivals.judging.results.preview',
            'dashboard.accounts.festivals.online-stream.preview',
            'dashboard.accounts.festivals.online-stream.status',
            'dashboard.accounts.festivals.timeline.fragment',
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
            'dashboard.accounts.festivals.judging.index',
            'dashboard.accounts.festivals.score-sheets.edit',
            'dashboard.accounts.festivals.timeline.index',
            'dashboard.accounts.studio-settings.index',
        ];
    }
}
