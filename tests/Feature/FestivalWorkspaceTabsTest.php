<?php

namespace Tests\Feature;

use App\Actions\Festivals\FestivalTicketIssuer;
use App\Actions\Festivals\InitializeFestivalEntryWorkflow;
use App\Actions\Festivals\StoreFestivalResponse;
use App\Actions\Festivals\StoreFestivalSubmission;
use App\Enums\AccountRole;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalActivityLog;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalDirection;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalNotificationSetting;
use App\Models\FestivalParticipant;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalRubric;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\FestivalTicketOrder;
use App\Models\FiscalReceipt;
use App\Models\User;
use App\Support\Festivals\FestivalApplicationIndex;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FestivalWorkspaceTabsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_deep_link_to_each_workflow_with_one_active_sidebar_item(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $timelineStage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);

        $routes = [
            'dashboard.accounts.festivals.show' => 'festival_tab_overview',
            'dashboard.accounts.festivals.applications' => 'festival_tab_applications',
            'dashboard.accounts.festivals.performances' => 'festival_tab_performances',
            'dashboard.accounts.festivals.program' => 'festival_tab_program',
            'dashboard.accounts.festivals.timeline.show' => 'festival_timeline_title',
            'dashboard.accounts.festivals.judging.judges.index' => 'festival_judges',
            'dashboard.accounts.festivals.judging.judges.create' => 'festival_judges',
            'dashboard.accounts.festivals.judging.criteria.index' => 'festival_criteria',
            'dashboard.accounts.festivals.judging.criteria.create' => 'festival_criteria',
            'dashboard.accounts.festivals.judging.score-sheets.index' => 'festival_score_sheets',
            'dashboard.accounts.festivals.judging.results.index' => 'festival_results',
            'dashboard.accounts.festivals.tickets' => 'festival_tab_tickets_entrance',
            'dashboard.accounts.festivals.admission-types.create' => 'festival_tab_tickets_entrance',
            'dashboard.accounts.festivals.communication' => 'festival_tab_communication',
            'dashboard.accounts.festivals.settings' => 'festival_settings_overview',
            'dashboard.accounts.festivals.settings.stages' => 'festival_scenes',
            'dashboard.accounts.festivals.stages.create' => 'festival_scenes',
            'dashboard.accounts.festivals.settings.directions' => 'festival_taxonomy_directions',
            'dashboard.accounts.festivals.directions.create' => 'festival_taxonomy_directions',
            'dashboard.accounts.festivals.settings.categories' => 'festival_categories',
            'dashboard.accounts.festivals.categories.create' => 'festival_categories',
            'dashboard.accounts.festivals.settings.workflows' => 'festival_registration_workflows',
            'dashboard.accounts.festivals.workflows.create' => 'festival_registration_workflows',
            'dashboard.accounts.festivals.settings.requirements' => 'festival_registration_fields',
            'dashboard.accounts.festivals.requirements.create' => 'festival_registration_fields',
            'dashboard.accounts.festivals.settings.fees' => 'festival_fees',
            'dashboard.accounts.festivals.charge-definitions.create' => 'festival_fees',
            'dashboard.accounts.festivals.settings.content' => 'festival_content_media',
            'dashboard.accounts.festivals.settings.content.sections' => 'festival_content_media',
            'dashboard.accounts.festivals.content.create' => 'festival_content_media',
            'dashboard.accounts.festivals.settings.content.documents' => 'festival_content_media',
            'dashboard.accounts.festivals.documents.create' => 'festival_content_media',
            'dashboard.accounts.festivals.settings.content.media' => 'festival_content_media',
            'dashboard.accounts.festivals.media.create' => 'festival_content_media',
            'dashboard.accounts.festivals.edit' => 'festival_settings_overview',
            'dashboard.accounts.festivals.scanner' => 'festival_tab_tickets_entrance',
        ];

        foreach ($routes as $route => $activeLabel) {
            $parameters = $route === 'dashboard.accounts.festivals.timeline.show'
                ? [$account, $edition, $timelineStage]
                : [$account, $edition];
            $response = $this->actingAs($owner)->get(route($route, $parameters));
            $response->assertOk()
                ->assertSee(__('app.'.$activeLabel))
                ->assertSee('data-workspace="festival"', false)
                ->assertSee(__('app.festival_workspace_back'))
                ->assertSee(__('app.festival_workspace_back_to_studio'))
                ->assertDontSee(__('app.my_studio'));
            $expectedCurrentItems = in_array($route, [
                'dashboard.accounts.festivals.applications',
                'dashboard.accounts.festivals.edit',
                'dashboard.accounts.festivals.tickets',
                'dashboard.accounts.festivals.communication',
            ], true) ? 3 : 2;
            $this->assertSame($expectedCurrentItems, substr_count($response->getContent(), 'aria-current="page"'), $route);
        }

        $category = $edition->categories()->firstOrFail();
        $editResponse = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.categories.edit', [$account, $edition, $category]));
        $editResponse->assertOk()->assertSee(__('app.festival_categories'));
        $this->assertSame(2, substr_count($editResponse->getContent(), 'aria-current="page"'));
    }

    public function test_festival_group_orders_overview_content_and_users_before_other_workflows(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.show', [$account, $edition]));
        $response->assertOk();
        $response->assertSeeInOrder([
            __('app.festival_workspace_group_festival'),
            __('app.festival_tab_overview'),
            __('app.festival_content_media'),
            __('app.festival_users'),
            __('app.festival_workspace_group_participants'),
        ]);
        $this->assertSame(1, substr_count(
            $response->getContent(),
            'href="'.route('dashboard.accounts.festivals.settings.content', [$account, $edition]).'"',
        ));
    }

    public function test_festival_workspace_finishes_with_public_and_judge_links(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $publicUrl = route('public.festivals.show', [$account->slug, $edition->slug]);
        $judgeCabinetUrl = route('festival.portal.judge.dashboard', $account->slug);

        $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.show', [$account, $edition]));

        $response->assertOk()
            ->assertSeeInOrder([
                __('app.festival_workspace_group_settings'),
                __('app.links'),
                __('app.festival_public_page'),
                __('app.festival_judge_cabinet'),
                __('app.festival_workspace_back_to_studio'),
            ])
            ->assertSee('href="'.$publicUrl.'"', false)
            ->assertSee('href="'.$judgeCabinetUrl.'"', false)
            ->assertSee(__('app.festival_public_page_sidebar_help'))
            ->assertSee(__('app.festival_judge_cabinet_sidebar_help'));
        $this->assertSame(2, substr_count($response->getContent(), 'target="_blank" rel="noopener"'));

        $edition->update(['status' => 'draft']);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.show', [$account, $edition]))
            ->assertOk()
            ->assertDontSee('href="'.$publicUrl.'"', false)
            ->assertSee('href="'.$judgeCabinetUrl.'"', false);
    }

    public function test_overview_links_total_categories_criteria_and_judges_for_managers(): void
    {
        [$account, $edition] = $this->festival();
        FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $rubric = FestivalRubric::factory()->for($edition)->create(['account_id' => $account->id]);
        $section = $rubric->sections()->create(['account_id' => $account->id, 'name' => 'Technique']);
        $section->criteria()->createMany([
            ['account_id' => $account->id, 'name' => 'Control', 'max_score' => 10],
            ['account_id' => $account->id, 'name' => 'Lines', 'max_score' => 10],
        ]);
        FestivalJudgeAssignment::factory()->count(2)->for($edition)->create(['account_id' => $account->id]);
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.show', [$account, $edition]));

        $response->assertOk()
            ->assertViewHas('festivalCriteriaCount', 2)
            ->assertSee(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]), false)
            ->assertSee(route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition]), false)
            ->assertSee(route('dashboard.accounts.festivals.judging.judges.index', [$account, $edition]), false);
        $this->assertSame(2, $response->viewData('edition')->categories_count);
        $this->assertSame(2, $response->viewData('edition')->judge_assignments_count);

        $registrationStaff = $this->staff($account, [StudioPermission::ManageFestivalRegistrations]);
        $this->actingAs($registrationStaff)
            ->get(route('dashboard.accounts.festivals.show', [$account, $edition]))
            ->assertOk()
            ->assertDontSee(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]), false)
            ->assertDontSee(route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition]), false)
            ->assertDontSee(route('dashboard.accounts.festivals.judging.judges.index', [$account, $edition]), false);
    }

    public function test_workflow_routes_enforce_permission_specific_data_boundaries(): void
    {
        [$account, $edition, $category, $portalUser, $entry] = $this->festivalWithEntry();
        $registrationStaff = $this->staff($account, [StudioPermission::ManageFestivalRegistrations]);
        $scheduleStaff = $this->staff($account, [StudioPermission::ManageFestivalSchedule]);
        $financeStaff = $this->staff($account, [StudioPermission::ManageFestivalFinance]);
        $checkInStaff = $this->staff($account, [StudioPermission::CheckInFestivalTickets]);

        $chargeDefinition = FestivalChargeDefinition::factory()->for($edition)->create(['account_id' => $account->id]);
        FestivalCharge::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $entry->id,
            'festival_charge_definition_id' => $chargeDefinition->id,
            'code' => 'FC-WORKSPACE',
            'kind' => 'participation',
            'name' => 'Private participation charge',
            'amount_cents' => 150000,
            'currency' => 'UAH',
        ]);
        $admissionType = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id]);
        $ticketOrder = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'status' => 'paid',
            'buyer_name' => 'Finance Buyer Sentinel',
            'buyer_email' => 'finance-buyer-secret@example.test',
            'amount_cents' => 30000,
            'paid_at' => now(),
        ]);
        $ticketOrder->items()->create([
            'account_id' => $account->id,
            'festival_admission_type_id' => $admissionType->id,
            'admission_name' => $admissionType->name,
            'unit_price_cents' => 30000,
            'quantity' => 1,
            'total_cents' => 30000,
        ]);
        app(FestivalTicketIssuer::class)->execute($ticketOrder);

        $this->actingAs($registrationStaff)
            ->get(route('dashboard.accounts.festivals.applications', [$account, $edition]))
            ->assertOk()
            ->assertSee($portalUser->email)
            ->assertSee(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]), false)
            ->assertSee(route('dashboard.accounts.festivals.performances', [$account, $edition]), false)
            ->assertDontSee(__('app.festival_application_review'))
            ->assertDontSee(__('app.festival_admission_revenue'));
        $this->actingAs($registrationStaff)
            ->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]))
            ->assertOk()
            ->assertSee(__('app.festival_application_review'))
            ->assertDontSee(__('app.festival_admission_revenue'));
        $this->actingAs($registrationStaff)
            ->get(route('dashboard.accounts.festivals.performances', [$account, $edition]))
            ->assertOk();
        $this->actingAs($registrationStaff)->get(route('dashboard.accounts.festivals.tickets', [$account, $edition]))->assertForbidden();

        $this->actingAs($scheduleStaff)->get(route('dashboard.accounts.festivals.program', [$account, $edition]))->assertOk();
        $this->actingAs($scheduleStaff)->get(route('dashboard.accounts.festivals.timeline.index', [$account, $edition]))->assertRedirect();
        $this->actingAs($scheduleStaff)->get(route('dashboard.accounts.festivals.settings', [$account, $edition]))->assertOk();
        $this->actingAs($scheduleStaff)->get(route('dashboard.accounts.festivals.settings.stages', [$account, $edition]))->assertOk();
        $this->actingAs($scheduleStaff)->get(route('dashboard.accounts.festivals.applications', [$account, $edition]))->assertForbidden();
        $this->actingAs($scheduleStaff)->get(route('dashboard.accounts.festivals.performances', [$account, $edition]))->assertForbidden();

        $this->actingAs($financeStaff)
            ->get(route('dashboard.accounts.festivals.applications', [$account, $edition]))
            ->assertOk()
            ->assertDontSee('Private participation charge')
            ->assertDontSee($portalUser->email)
            ->assertDontSee(route('dashboard.accounts.festivals.performances', [$account, $edition]), false)
            ->assertDontSee(__('app.festival_application_review'));
        $this->actingAs($financeStaff)
            ->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]))
            ->assertOk()
            ->assertSee('Private participation charge')
            ->assertSee('1 500 ₴')
            ->assertDontSee($portalUser->email)
            ->assertDontSee(__('app.festival_application_review'));
        $this->actingAs($financeStaff)->get(route('dashboard.accounts.festivals.performances', [$account, $edition]))->assertForbidden();
        $this->actingAs($financeStaff)
            ->get(route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'sold']))
            ->assertOk()
            ->assertSee('finance-buyer-secret@example.test')
            ->assertSee(__('app.festival_admission_revenue'));
        $this->actingAs($financeStaff)->get(route('dashboard.accounts.festivals.settings', [$account, $edition]))->assertOk();
        $this->actingAs($financeStaff)->get(route('dashboard.accounts.festivals.settings.fees', [$account, $edition]))->assertOk();
        $this->actingAs($financeStaff)->get(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]))->assertForbidden();

        $this->actingAs($checkInStaff)
            ->get(route('dashboard.accounts.festivals.tickets', [$account, $edition]))
            ->assertOk()
            ->assertSee(__('app.festival_open_scanner'))
            ->assertDontSee('finance-buyer-secret@example.test')
            ->assertDontSee(__('app.festival_admission_revenue'));
        $this->actingAs($checkInStaff)->get(route('dashboard.accounts.festivals.scanner', [$account, $edition]))->assertOk();
    }

    public function test_ticket_and_communication_tabs_support_safe_defaults_deep_links_and_active_only_data(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'invalid']))
            ->assertOk()
            ->assertViewHas('tab', 'types')
            ->assertViewHas('tickets', null);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'sold']))
            ->assertOk()
            ->assertViewHas('tab', 'sold')
            ->assertViewHas('admissionTypes', null);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.communication', [$account, $edition, 'tab' => 'invalid']))
            ->assertOk()
            ->assertViewHas('tab', 'history')
            ->assertViewHas('announcements', null);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.communication', [$account, $edition, 'tab' => 'announcements']))
            ->assertOk()
            ->assertViewHas('tab', 'announcements')
            ->assertViewHas('notifications', null);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.communication', [$account, $edition, 'tab' => 'settings']))
            ->assertOk()
            ->assertViewHas('tab', 'settings')
            ->assertViewHas('notifications', null)
            ->assertViewHas('announcements', null);
    }

    public function test_application_history_is_paginated_scoped_and_permission_safe(): void
    {
        [$account, $edition, , $portalUser, $entry] = $this->festivalWithEntry();
        $owner = User::factory()->create(['name' => 'History Owner']);
        $account->addOwner($owner);
        $registrationStaff = $this->staff($account, [StudioPermission::ManageFestivalRegistrations]);
        $financeStaff = $this->staff($account, [StudioPermission::ManageFestivalFinance]);

        foreach (range(1, 21) as $index) {
            FestivalActivityLog::query()->create([
                'account_id' => $account->id,
                'festival_edition_id' => $edition->id,
                'festival_entry_id' => $entry->id,
                'actor_portal_user_id' => $portalUser->id,
                'action' => 'entry.updated',
                'subject_type' => $entry->getMorphClass(),
                'subject_id' => $entry->id,
                'payload' => [
                    'fields' => ['comments'],
                    'comment' => 'History event '.$index,
                ],
                'occurred_at' => now()->subMinutes(30 - $index),
            ]);
        }
        FestivalActivityLog::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_entry_id' => $entry->id,
            'actor_user_id' => $owner->id,
            'action' => 'entry.reviewed',
            'subject_type' => $entry->getMorphClass(),
            'subject_id' => $entry->id,
            'payload' => [
                'status' => FestivalEntryStatus::Submitted->value,
                'comment' => 'Newest review detail',
                'raw_secret' => 'RAW-PAYLOAD-MUST-NOT-RENDER',
            ],
            'occurred_at' => now(),
        ]);
        FestivalActivityLog::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_entry_id' => $entry->id,
            'actor_portal_user_id' => $portalUser->id,
            'action' => 'payment.started',
            'subject_type' => $entry->getMorphClass(),
            'subject_id' => $entry->id,
            'payload' => ['provider' => 'private-provider', 'status' => 'pending'],
            'occurred_at' => now()->addSecond(),
        ]);

        $defaultPage = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]));
        $defaultPage->assertOk()
            ->assertViewHas('tab', 'details')
            ->assertSee(__('app.festival_application_tab_details'))
            ->assertSee(__('app.festival_application_tab_history'));

        $historyPage = $this->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry, 'tab' => 'history']));
        $historyPage->assertOk()
            ->assertViewHas('tab', 'history')
            ->assertSeeInOrder([
                __('app.festival_activity_action_payment_started'),
                __('app.festival_activity_action_entry_reviewed'),
                'Newest review detail',
            ])
            ->assertSee('History Owner')
            ->assertSee('private-provider')
            ->assertSee(__('app.festival_payment_status_pending'))
            ->assertDontSee('RAW-PAYLOAD-MUST-NOT-RENDER');
        $this->assertSame(20, $historyPage->viewData('activityHistory')->count());
        $this->assertSame(23, $historyPage->viewData('activityHistory')->total());

        $secondPage = $this->get(route('dashboard.accounts.festivals.applications.show', [
            $account,
            $edition,
            $entry,
            'tab' => 'history',
            'history_page' => 2,
        ]));
        $secondPage->assertOk();
        $this->assertSame(3, $secondPage->viewData('activityHistory')->count());

        $this->actingAs($registrationStaff)
            ->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry, 'tab' => 'history']))
            ->assertOk()
            ->assertViewHas('tab', 'history')
            ->assertSee(__('app.festival_activity_action_payment_started'))
            ->assertDontSee('private-provider')
            ->assertDontSee(__('app.festival_payment_status_pending'));

        $this->actingAs($financeStaff)
            ->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry, 'tab' => 'history']))
            ->assertOk()
            ->assertViewHas('tab', 'details')
            ->assertDontSee(__('app.festival_application_tab_history'));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry, 'tab' => 'invalid']))
            ->assertOk()
            ->assertViewHas('tab', 'details');
    }

    public function test_ticket_revenue_keeps_mixed_historical_currencies_separate(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'status' => 'paid',
            'amount_cents' => 150000,
            'currency' => 'UAH',
            'paid_at' => now(),
        ]);
        FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'status' => 'paid',
            'amount_cents' => 2500,
            'currency' => 'USD',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.tickets', [$account, $edition]));

        $response->assertOk()
            ->assertSee('1 500 ₴')
            ->assertSee('25 $');
        $this->assertSame([
            'UAH' => 150000,
            'USD' => 2500,
        ], $response->viewData('admissionReport')['revenue_by_currency']->all());
    }

    public function test_bought_ticket_tab_paginates_real_tickets_and_preserves_search_and_filter_values(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $type = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'inventory' => 30]);
        $order = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'status' => 'paid',
            'buyer_name' => 'Pagination Buyer',
            'buyer_email' => 'pagination-buyer@example.test',
            'buyer_phone' => '+380501112233',
            'provider' => 'monopay',
            'gateway_payment_id' => 'festival-gateway-reference',
            'amount_cents' => 630000,
            'paid_at' => now(),
            'expires_at' => null,
        ]);
        $order->items()->create([
            'account_id' => $account->id,
            'festival_admission_type_id' => $type->id,
            'admission_name' => 'Frozen pagination admission',
            'unit_price_cents' => 30000,
            'quantity' => 21,
            'total_cents' => 630000,
        ]);
        app(FestivalTicketIssuer::class)->execute($order);

        $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.tickets', [
            $account,
            $edition,
            'tab' => 'sold',
            'q' => 'Pagination Buyer',
            'type' => $type->id,
            'status' => 'valid',
        ]));

        $response->assertOk()
            ->assertSee('pagination-buyer@example.test')
            ->assertSee('+380501112233')
            ->assertSee('Frozen pagination admission')
            ->assertSee('festival-gateway-reference')
            ->assertSee('q=Pagination%20Buyer', false);
        $this->assertSame(21, $response->viewData('tickets')->total());
        $this->assertSame(20, $response->viewData('tickets')->perPage());
    }

    public function test_festival_notification_settings_group_scenarios_and_toggle_participant_and_owner_channels(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $url = route('dashboard.accounts.festivals.communication', [$account, $edition, 'tab' => 'settings']);

        $this->actingAs($owner)
            ->get($url)
            ->assertOk()
            ->assertSeeInOrder([
                __('app.festival_notification_group_registration'),
                __('app.festival_notification_group_payments'),
                __('app.festival_notification_group_program'),
                __('app.festival_notification_group_tickets'),
                __('app.festival_notification_group_announcements'),
            ])
            ->assertSee('owner_telegram['.FestivalNotificationType::EntrySubmitted->value.']', false)
            ->assertSee(trans_choice('app.festival_owner_telegram_connections', 0, ['count' => 0]));

        $this->actingAs($owner)
            ->from($url)
            ->put(route('dashboard.accounts.festivals.notification-settings.update', $account), [
                'sms' => [FestivalNotificationType::Announcement->value => '1'],
                'owner_telegram' => [FestivalNotificationType::EntrySubmitted->value => '1'],
            ])
            ->assertRedirect($url);

        $this->assertTrue(FestivalNotificationSetting::query()
            ->whereBelongsTo($account)
            ->where('type', FestivalNotificationType::Announcement->value)
            ->firstOrFail()
            ->send_sms);
        $this->assertTrue(FestivalNotificationSetting::query()
            ->whereBelongsTo($account)
            ->where('type', FestivalNotificationType::EntrySubmitted->value)
            ->firstOrFail()
            ->notify_owner_telegram);
        $this->assertFalse(FestivalNotificationSetting::query()
            ->whereBelongsTo($account)
            ->where('type', FestivalNotificationType::EntrySubmitted->value)
            ->firstOrFail()
            ->send_sms);
        $this->assertFalse(FestivalNotificationSetting::query()
            ->whereBelongsTo($account)
            ->where('type', FestivalNotificationType::Announcement->value)
            ->firstOrFail()
            ->notify_owner_telegram);
        $this->assertSame(FestivalNotificationType::cases(), FestivalNotificationSetting::query()
            ->whereBelongsTo($account)
            ->orderBy('id')
            ->get()
            ->pluck('type')
            ->all());
    }

    public function test_admission_type_create_and_edit_pages_have_ticket_breadcrumbs_and_tenant_boundaries(): void
    {
        [$account, $edition] = $this->festival();
        [$otherAccount, $otherEdition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $type = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id]);
        $otherType = FestivalAdmissionType::factory()->for($otherEdition)->create(['account_id' => $otherAccount->id]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.admission-types.create', [$account, $edition]))
            ->assertOk()
            ->assertSee(__('app.festival_tickets'))
            ->assertSee(__('app.festival_add_admission_type'));
        $edit = $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.admission-types.edit', [$account, $edition, $type]));
        $edit->assertOk()
            ->assertSee(__('app.festival_tickets'))
            ->assertSee(__('app.festival_edit_admission_type'));
        $this->assertSame(2, substr_count($edit->getContent(), 'aria-current="page"'));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.admission-types.edit', [$account, $edition, $otherType]))
            ->assertNotFound();
    }

    public function test_applications_support_compact_work_queues_and_query_preserving_filters(): void
    {
        $this->assertSame('Заявки', trans('app.festival_applications_title', [], 'uk'));
        $this->assertSame('Робочі черги', trans('app.festival_application_work_queues', [], 'uk'));
        $this->assertSame('Заявок ще немає.', trans('app.festival_applications_empty', [], 'uk'));
        $this->assertSame('Чекліст', trans('app.festival_requirements_open', [], 'uk'));

        [$account, $edition, $firstCategory, $portalUser, $submittedEntry] = $this->festivalWithEntry();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $secondCategory = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Featured category',
        ]);
        $acceptedEntry = FestivalEntry::factory()->for($secondCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Featured performance',
            'status' => FestivalEntryStatus::Accepted,
            'submitted_at' => now()->subMinute(),
        ]);
        $draftEntry = FestivalEntry::factory()->for($firstCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Draft performance',
            'status' => FestivalEntryStatus::Draft,
        ]);
        $submittedEntry = app(InitializeFestivalEntryWorkflow::class)->execute($submittedEntry);
        $submittedCurrentStep = $submittedEntry->steps->firstOrFail();
        $submittedEntry->charges()->create([
            'account_id' => $account->id,
            'festival_entry_step_id' => $submittedCurrentStep->id,
            'code' => 'FCH-APPLICATIONS-UNPAID',
            'kind' => 'qualification',
            'name' => 'Application fee',
            'amount_cents' => 50000,
            'currency' => 'UAH',
        ]);
        $acceptedEntry = app(InitializeFestivalEntryWorkflow::class)->execute($acceptedEntry);
        $acceptedCurrentStep = $acceptedEntry->steps->firstOrFail();
        $acceptedEntry->charges()->create([
            'account_id' => $account->id,
            'festival_entry_step_id' => $acceptedCurrentStep->id,
            'code' => 'FCH-APPLICATIONS-PAID',
            'kind' => 'qualification',
            'name' => 'Paid application fee',
            'status' => FestivalChargeStatus::Paid,
            'amount_cents' => 50000,
            'currency' => 'UAH',
            'paid_at' => now(),
        ]);

        $byName = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications', [
            $account,
            $edition,
            'q' => 'Featured',
        ]));
        $byName->assertOk()
            ->assertSee($acceptedEntry->entry_name)
            ->assertDontSee($submittedEntry->entry_name)
            ->assertSee('aria-label="'.__('app.festival_application_work_queues').'"', false)
            ->assertDontSee('<h3 id="festival-application-work-queues"', false)
            ->assertDontSee('<details class="sm:col-span-2"', false)
            ->assertViewHas('filters', fn (array $filters): bool => $filters['q'] === 'Featured')
            ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 1);

        $byStatus = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications', [
            $account,
            $edition,
            'status' => FestivalEntryStatus::Draft->value,
        ]));
        $byStatus->assertOk()
            ->assertSee($draftEntry->entry_name)
            ->assertDontSee($acceptedEntry->entry_name)
            ->assertSee('<span class="crm-status-muted">'.__('app.festival_entry_status_draft').'</span>', false)
            ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 1);

        $submitted = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications', [
            $account,
            $edition,
            'status' => FestivalEntryStatus::Submitted->value,
        ]));
        $submitted->assertOk()
            ->assertSee($submittedEntry->entry_name)
            ->assertSee('<span class="crm-status-scheduled">'.__('app.festival_entry_status_submitted').'</span>', false)
            ->assertSee('crm-status-danger', false)
            ->assertSee(__('app.festival_application_payment_unpaid'))
            ->assertSee(__('app.festival_current_step'))
            ->assertSee($submittedCurrentStep->workflowStep->title)
            ->assertSee(__('app.festival_step_status_'.$submittedCurrentStep->status->value));

        $byCategory = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications', [
            $account,
            $edition,
            'category' => $secondCategory->id,
        ]));
        $byCategory->assertOk()
            ->assertSee($acceptedEntry->entry_name)
            ->assertDontSee($draftEntry->entry_name)
            ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 1);

        $combinedUrl = route('dashboard.accounts.festivals.applications', [
            $account,
            $edition,
            'q' => 'Featured',
            'category' => $secondCategory->id,
            'status' => FestivalEntryStatus::Accepted->value,
        ]);
        $combined = $this->actingAs($owner)->get($combinedUrl);
        $allQueuesUrl = route('dashboard.accounts.festivals.applications', [
            $account,
            $edition,
            'q' => 'Featured',
            'status' => FestivalEntryStatus::Accepted->value,
            'category' => $secondCategory->id,
        ]);
        $combined->assertOk()
            ->assertSee(__('app.festival_applications_title'))
            ->assertSee(__('app.festival_requirements_open'))
            ->assertSee($acceptedEntry->entry_name)
            ->assertSee(__('app.festival_charge_status_paid'))
            ->assertSee(__('app.festival_current_step'))
            ->assertSee($acceptedCurrentStep->workflowStep->title)
            ->assertDontSee($draftEntry->entry_name)
            ->assertSee($allQueuesUrl)
            ->assertDontSee(__('app.festival_application_statistics'))
            ->assertDontSee(__('app.festival_entries_by_category'))
            ->assertDontSee(__('app.festival_entries_by_direction'))
            ->assertDontSee('crm-tab whitespace-nowrap', false)
            ->assertSee('border-slate-200 bg-slate-50', false)
            ->assertSee('border-sky-200 bg-sky-50 text-sky-900', false)
            ->assertSee('border-amber-200 bg-amber-50 text-amber-900', false)
            ->assertSee('border-violet-200 bg-violet-50 text-violet-900', false)
            ->assertSee('border-emerald-200 bg-emerald-50 text-emerald-900', false)
            ->assertSee('border-rose-200 bg-rose-50 text-rose-900', false)
            ->assertSee('border-slate-300 bg-slate-100 text-slate-800', false)
            ->assertViewHas('filters', [
                'q' => 'Featured',
                'status' => FestivalEntryStatus::Accepted->value,
                'category' => (string) $secondCategory->id,
                'queue' => '',
                'current_step' => '',
                'checklist' => '',
                'payment' => '',
            ])
            ->assertViewHas('queueCounts', function ($counts): bool {
                return $counts->all() === [
                    'all' => 1,
                    'awaiting_review' => 0,
                    'corrections_requested' => 0,
                    'payment_incomplete' => 0,
                    'not_submitted' => 1,
                    'complete' => 0,
                    'closed' => 0,
                ];
            });
        $this->assertSame(7, substr_count($combined->getContent(), 'data-queue-pill='));
        $this->assertMatchesRegularExpression('/data-queue-pill="all"\s+aria-current="page"/', $combined->getContent());
        $combined->assertDontSee('data-status-card=', false);
        $this->assertSame(3, substr_count($combined->getContent(), 'aria-current="page"'));
        foreach (app(FestivalApplicationIndex::class)->queueKeys() as $queue) {
            $combined->assertSee(__('app.festival_application_queue_'.$queue));
        }

        FestivalEntry::factory()->count(20)->for($firstCategory)->state(fn (): array => [
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Performer pagination '.fake()->unique()->numerify('###'),
            'status' => FestivalEntryStatus::Submitted,
            'submitted_at' => now()->subMinutes(2),
        ])->create();
        $paginated = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications', [
            $account,
            $edition,
            'q' => 'Performer',
            'status' => FestivalEntryStatus::Submitted->value,
            'category' => $firstCategory->id,
        ]));
        $paginated->assertOk()->assertViewHas('entries', fn ($entries): bool => $entries->total() === 21 && $entries->perPage() === 20);
        $nextPageUrl = $paginated->viewData('entries')->nextPageUrl();
        $this->assertNotNull($nextPageUrl);
        $this->assertStringContainsString('q=Performer', $nextPageUrl);
        $this->assertStringContainsString('status=submitted', $nextPageUrl);
        $this->assertStringContainsString('category='.$firstCategory->id, $nextPageUrl);

        $filteredEmpty = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications', [
            $account,
            $edition,
            'q' => 'No matching performance',
        ]));
        $filteredEmpty->assertOk()
            ->assertSee(__('app.no_data'))
            ->assertSee(__('app.reset_filters'))
            ->assertViewHas('hasFilters', true);

        $emptyEdition = FestivalEdition::factory()->published()->for($edition->series)->create([
            'account_id' => $account->id,
            'timezone' => 'Europe/Kyiv',
        ]);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.applications', [$account, $emptyEdition]))
            ->assertOk()
            ->assertSee(__('app.festival_applications_empty'));

        [, $otherEdition, $otherCategory] = $this->festival();
        $invalid = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications', [
            $account,
            $edition,
            'status' => 'unknown-status',
            'category' => $otherCategory->id,
            'queue' => 'unknown-queue',
            'current_step' => 999999999,
            'checklist' => 'unknown-checklist',
            'payment' => 'unknown-payment',
        ]));
        $invalid->assertOk()
            ->assertDontSee($otherEdition->title)
            ->assertDontSee($otherCategory->name)
            ->assertViewHas('filters', [
                'q' => '',
                'status' => '',
                'category' => '',
                'queue' => '',
                'current_step' => '',
                'checklist' => '',
                'payment' => '',
            ])
            ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 23);
    }

    public function test_applications_show_live_direction_and_category_rules(): void
    {
        [$account, $edition, $firstCategory] = $this->festivalWithEntry();
        $registrationStaff = $this->staff($account, [StudioPermission::ManageFestivalRegistrations]);
        $movedDirection = FestivalDirection::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Moved direction',
            'code' => 'moved-direction',
        ]);
        $categoryDeadline = now()->addWeek()->startOfMinute();
        $firstCategory->update([
            'festival_direction_id' => $movedDirection->id,
            'requirements_html' => '<p>Live staff condition.</p>',
            'min_members' => 2,
            'max_members' => 4,
            'registration_closes_at' => $categoryDeadline,
        ]);

        $entry = $edition->entries()->firstOrFail();
        $currentRules = $this->actingAs($registrationStaff)
            ->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]));

        $currentRules->assertOk()
            ->assertSee('Moved direction')
            ->assertSee('Live staff condition.', false)
            ->assertSee(__('app.festival_participants_range', ['min' => 2, 'max' => 4]))
            ->assertSee(__('app.festival_category_deadline_value', [
                'date' => $categoryDeadline->timezone($edition->timezone)->format('d.m.Y H:i'),
                'timezone' => $edition->timezone,
            ]));
    }

    public function test_festival_manager_may_delete_an_application_with_stronger_confirmation_for_payment_history(): void
    {
        Storage::fake('local');
        [$account, $edition, $category, $portalUser, $entry] = $this->festivalWithEntry();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $registrationStaff = $this->staff($account, [StudioPermission::ManageFestivalRegistrations]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $entry->participants()->sync([$participant->id => ['account_id' => $account->id, 'sort_order' => 0]]);
        $fileDefinition = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'type' => 'custom_document',
            'stage' => 'qualification',
            'allowed_extensions' => ['png'],
            'allowed_mime_types' => ['image/png'],
        ]);
        $textDefinition = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'type' => 'custom_document',
            'input_type' => 'short_text',
            'name' => 'Application note',
            'stage' => 'qualification',
            'allowed_extensions' => [],
            'allowed_mime_types' => [],
        ]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);
        $submission = app(StoreFestivalSubmission::class)->execute(
            $entry->requirements->firstWhere('festival_requirement_definition_id', $fileDefinition->id),
            $portalUser,
            UploadedFile::fake()->image('delete-with-application.png'),
        );
        $textSubmission = app(StoreFestivalResponse::class)->execute(
            $entry->requirements->firstWhere('festival_requirement_definition_id', $textDefinition->id),
            $portalUser,
            'Text response without a file path',
        );
        $this->assertNull($textSubmission->path);
        $applicationUrl = route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]);
        $deleteUrl = route('dashboard.accounts.festivals.applications.destroy', [$account, $edition, $entry]);

        $this->actingAs($registrationStaff)
            ->get($applicationUrl)
            ->assertOk()
            ->assertDontSee('data-confirm-delete', false);
        $this->actingAs($registrationStaff)
            ->delete($deleteUrl)
            ->assertForbidden();
        $this->actingAs($owner)
            ->get($applicationUrl)
            ->assertOk()
            ->assertSee($deleteUrl, false)
            ->assertSee('data-confirm-delete', false)
            ->assertDontSee('data-confirm-phrase=', false)
            ->assertSee(__('app.festival_delete_application_title'));

        $this->actingAs($owner)
            ->delete($deleteUrl)
            ->assertRedirect(route('dashboard.accounts.festivals.applications', [$account, $edition]))
            ->assertSessionHas('status', __('app.festival_application_deleted'));
        $this->assertDatabaseMissing('festival_entries', ['id' => $entry->id]);
        $this->assertDatabaseHas('festival_activity_logs', [
            'subject_type' => $entry->getMorphClass(),
            'subject_id' => $entry->id,
            'actor_user_id' => $owner->id,
            'action' => 'entry.deleted',
        ]);
        Storage::disk('local')->assertMissing($submission->path);

        $protectedEntry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Protected payment history',
        ]);
        $charge = FestivalCharge::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $protectedEntry->id,
            'code' => 'FCH-DELETE-PROTECTED',
            'kind' => 'participation',
            'name' => 'Participation fee',
            'amount_cents' => 50000,
            'currency' => 'UAH',
        ]);
        FestivalPaymentAttempt::query()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $charge->id,
            'provider' => 'monopay',
            'order_id' => 'FCHP-DELETE-PROTECTED',
            'amount_cents' => $charge->amount_cents,
            'currency' => $charge->currency,
            'status' => 'expired',
        ]);
        $protectedApplicationUrl = route('dashboard.accounts.festivals.applications.show', [$account, $edition, $protectedEntry]);
        $protectedDeleteUrl = route('dashboard.accounts.festivals.applications.destroy', [$account, $edition, $protectedEntry]);

        $this->actingAs($owner)
            ->get($protectedApplicationUrl)
            ->assertOk()
            ->assertSee($protectedDeleteUrl, false)
            ->assertSee('data-confirm-delete', false)
            ->assertSee('data-confirm-phrase="delete"', false)
            ->assertSee('name="approval"', false)
            ->assertSee(__('app.festival_delete_paid_application_title'));
        $this->actingAs($owner)
            ->from($protectedApplicationUrl)
            ->delete($protectedDeleteUrl)
            ->assertRedirect($protectedApplicationUrl)
            ->assertSessionHasErrors('festival_application');
        $this->assertDatabaseHas('festival_entries', ['id' => $protectedEntry->id]);

        $changedAfterPageLoadEntry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Payment changed after page load',
        ]);
        $changedAfterPageLoadUrl = route('dashboard.accounts.festivals.applications.show', [$account, $edition, $changedAfterPageLoadEntry]);
        $changedAfterPageLoadDeleteUrl = route('dashboard.accounts.festivals.applications.destroy', [$account, $edition, $changedAfterPageLoadEntry]);
        $this->actingAs($owner)
            ->get($changedAfterPageLoadUrl)
            ->assertOk()
            ->assertDontSee('data-confirm-phrase=', false);
        FestivalCharge::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $changedAfterPageLoadEntry->id,
            'code' => 'FCH-DELETE-AFTER-PAGE-LOAD',
            'kind' => 'participation',
            'name' => 'Paid after page load',
            'status' => 'paid',
            'amount_cents' => 50000,
            'currency' => 'UAH',
            'paid_at' => now(),
        ]);
        $this->actingAs($owner)
            ->from($changedAfterPageLoadUrl)
            ->delete($changedAfterPageLoadDeleteUrl)
            ->assertRedirect($changedAfterPageLoadUrl)
            ->assertSessionHasErrors('festival_application');
        $this->assertDatabaseHas('festival_entries', ['id' => $changedAfterPageLoadEntry->id]);

        $declinedEntry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Manually declined payment',
            'status' => FestivalEntryStatus::Draft,
        ]);
        $declinedCharge = FestivalCharge::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $declinedEntry->id,
            'code' => 'FCH-DELETE-DECLINED',
            'kind' => 'participation',
            'name' => 'Declined participation fee',
            'amount_cents' => 50000,
            'currency' => 'UAH',
        ]);
        FestivalPaymentAttempt::query()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $declinedCharge->id,
            'provider' => 'monopay',
            'order_id' => 'FCHP-DELETE-DECLINED',
            'amount_cents' => $declinedCharge->amount_cents,
            'currency' => $declinedCharge->currency,
            'status' => 'pending',
        ]);
        $declinedApplicationUrl = route('dashboard.accounts.festivals.applications.show', [$account, $edition, $declinedEntry]);
        $declinedDeleteUrl = route('dashboard.accounts.festivals.applications.destroy', [$account, $edition, $declinedEntry]);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.charges.manual-review', [$account, $edition, $declinedCharge]), [
                'decision' => 'reject',
                'notes' => 'Declined by an administrator.',
            ])
            ->assertRedirect();
        $this->assertSame(FestivalChargeStatus::Failed, $declinedCharge->refresh()->status);
        $this->assertSame($owner->id, $declinedCharge->approved_by);
        $this->actingAs($owner)
            ->get($declinedApplicationUrl)
            ->assertOk()
            ->assertSee($declinedDeleteUrl, false)
            ->assertDontSee('data-confirm-phrase=', false);
        $this->actingAs($owner)
            ->delete($declinedDeleteUrl)
            ->assertRedirect(route('dashboard.accounts.festivals.applications', [$account, $edition]))
            ->assertSessionHas('status', __('app.festival_application_deleted'));
        $this->assertDatabaseMissing('festival_entries', ['id' => $declinedEntry->id]);

        $manualPaymentEntry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Protected manual payment',
        ]);
        $manualPaymentCharge = FestivalCharge::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $manualPaymentEntry->id,
            'code' => 'FCH-DELETE-MANUAL',
            'kind' => 'participation',
            'name' => 'Manual participation fee',
            'status' => 'paid',
            'amount_cents' => 50000,
            'currency' => 'UAH',
            'paid_at' => now(),
        ]);
        $paidAttempt = FestivalPaymentAttempt::query()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $manualPaymentCharge->id,
            'provider' => 'monopay',
            'order_id' => 'FCHP-DELETE-PAID',
            'amount_cents' => $manualPaymentCharge->amount_cents,
            'currency' => $manualPaymentCharge->currency,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        $fiscalReceipt = FiscalReceipt::factory()
            ->forAccountScope($account)
            ->fiscalized('CHK-FESTIVAL-DELETE')
            ->create([
                'payment_type' => $paidAttempt->getMorphClass(),
                'payment_id' => $paidAttempt->id,
            ]);
        $manualApplicationUrl = route('dashboard.accounts.festivals.applications.show', [$account, $edition, $manualPaymentEntry]);
        $manualDeleteUrl = route('dashboard.accounts.festivals.applications.destroy', [$account, $edition, $manualPaymentEntry]);

        $this->actingAs($owner)
            ->get($manualApplicationUrl)
            ->assertOk()
            ->assertSee($manualDeleteUrl, false)
            ->assertSee('data-confirm-phrase="delete"', false)
            ->assertSee(__('app.festival_delete_paid_application_copy'));
        $this->actingAs($owner)
            ->from($manualApplicationUrl)
            ->delete($manualDeleteUrl)
            ->assertRedirect($manualApplicationUrl)
            ->assertSessionHasErrors('festival_application');
        $this->assertDatabaseHas('festival_entries', ['id' => $manualPaymentEntry->id]);

        $this->actingAs($owner)
            ->from($manualApplicationUrl)
            ->delete($manualDeleteUrl, ['approval' => 'remove'])
            ->assertRedirect($manualApplicationUrl)
            ->assertSessionHasErrors('approval');
        $this->assertDatabaseHas('festival_entries', ['id' => $manualPaymentEntry->id]);

        $this->actingAs($owner)
            ->delete($manualDeleteUrl, ['approval' => 'delete'])
            ->assertRedirect(route('dashboard.accounts.festivals.applications', [$account, $edition]))
            ->assertSessionHas('status', __('app.festival_application_deleted'));
        $this->assertDatabaseMissing('festival_entries', ['id' => $manualPaymentEntry->id]);
        $this->assertDatabaseMissing('festival_charges', ['id' => $manualPaymentCharge->id]);
        $this->assertDatabaseMissing('festival_payment_attempts', ['id' => $paidAttempt->id]);
        $this->assertModelExists($fiscalReceipt);
        $this->assertDatabaseHas('festival_activity_logs', [
            'subject_type' => $manualPaymentEntry->getMorphClass(),
            'subject_id' => $manualPaymentEntry->id,
            'action' => 'entry.deleted',
        ]);
        $forcedDeleteActivity = FestivalActivityLog::query()
            ->where('subject_type', $manualPaymentEntry->getMorphClass())
            ->where('subject_id', $manualPaymentEntry->id)
            ->where('action', 'entry.deleted')
            ->firstOrFail();
        $this->assertTrue($forcedDeleteActivity->payload['payment_history_force_deleted']);
    }

    public function test_performances_list_only_fully_confirmed_entries_with_filters_and_separate_summary_and_application_pages(): void
    {
        [$account, $edition, $category, $portalUser, $submittedEntry] = $this->festivalWithEntry();
        $portalUser->update(['first_name' => 'Olena', 'last_name' => 'Applicant']);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $confirmedCategory = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Confirmed category',
        ]);
        $confirmedEntry = FestivalEntry::factory()->for($confirmedCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Confirmed performance',
            'status' => FestivalEntryStatus::Accepted,
            'accepted_at' => now(),
            'registration_completed_at' => now(),
        ]);

        $index = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.performances', [$account, $edition]));

        $index->assertOk()
            ->assertSee(__('app.festival_performances_title'))
            ->assertSee($confirmedEntry->entry_name)
            ->assertSee($portalUser->displayName())
            ->assertSee($confirmedCategory->name)
            ->assertDontSee($submittedEntry->entry_name)
            ->assertSee(route('dashboard.accounts.festivals.performances.show', [$account, $edition, $confirmedEntry]), false)
            ->assertSee(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $confirmedEntry]), false)
            ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 1 && $entries->perPage() === 20);

        $byApplicant = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.performances', [
            $account,
            $edition,
            'q' => 'Olena Applicant',
        ]));
        $byApplicant->assertOk()->assertSee($confirmedEntry->entry_name)->assertViewHas('filters', [
            'q' => 'Olena Applicant',
            'status' => '',
            'category' => '',
        ]);

        $byCategory = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.performances', [
            $account,
            $edition,
            'category' => $confirmedCategory->id,
        ]));
        $byCategory->assertOk()->assertSee($confirmedEntry->entry_name)->assertViewHas('entries', fn ($entries): bool => $entries->total() === 1);

        $summary = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.performances.show', [$account, $edition, $confirmedEntry]));
        $summary->assertOk()
            ->assertSee(__('app.festival_readonly_summary_copy'))
            ->assertSee($confirmedEntry->entry_name)
            ->assertSee($portalUser->displayName())
            ->assertSee($confirmedCategory->name)
            ->assertSee('class="mt-4 flex flex-col gap-3"', false)
            ->assertDontSee('data-async-form', false)
            ->assertSee(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $confirmedEntry]), false);

        $application = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $confirmedEntry]));
        $application->assertOk()
            ->assertSee(__('app.festival_application'))
            ->assertSee('data-festival-application-fragment-key="charges-'.$confirmedEntry->id.'"', false)
            ->assertSee('class="mt-3 flex flex-col gap-3"', false);
        $this->assertLessThan(
            strpos($application->getContent(), __('app.festival_checklist')),
            strpos($application->getContent(), __('app.festival_payments')),
        );
        $this->assertLessThan(
            strpos($application->getContent(), __('app.festival_payments')),
            strpos($application->getContent(), __('app.festival_application_review')),
        );

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.performances.show', [$account, $edition, $submittedEntry]))
            ->assertNotFound();

        FestivalEntry::factory()->count(20)->for($confirmedCategory)->state(fn (): array => [
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Confirmed pagination '.fake()->unique()->numerify('###'),
            'status' => FestivalEntryStatus::Accepted,
            'accepted_at' => now()->subMinute(),
            'registration_completed_at' => now()->subMinute(),
        ])->create();
        $paginated = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.performances', [
            $account,
            $edition,
            'q' => 'Confirmed',
            'category' => $confirmedCategory->id,
        ]));
        $paginated->assertOk()->assertViewHas('entries', fn ($entries): bool => $entries->total() === 21 && $entries->perPage() === 20);
        $nextPageUrl = $paginated->viewData('entries')->nextPageUrl();
        $this->assertNotNull($nextPageUrl);
        $this->assertStringContainsString('q=Confirmed', $nextPageUrl);
        $this->assertStringContainsString('category='.$confirmedCategory->id, $nextPageUrl);

        [, , $otherCategory] = $this->festival();
        $invalidCategory = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.performances', [
            $account,
            $edition,
            'category' => $otherCategory->id,
        ]));
        $invalidCategory->assertOk()
            ->assertDontSee($otherCategory->name)
            ->assertViewHas('filters', ['q' => '', 'status' => '', 'category' => ''])
            ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 21);
    }

    public function test_staff_judge_requires_an_active_assignment_for_the_edition(): void
    {
        [$account, $edition, $category] = $this->festival();
        $judge = $this->staff($account, [StudioPermission::JudgeFestivals]);

        $this->actingAs($judge)->get(route('dashboard.accounts.festivals.show', [$account, $edition]))->assertForbidden();
        $this->actingAs($judge)->get(route('dashboard.accounts.festivals.judging.index', [$account, $edition]))->assertForbidden();

        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($judge)->create(['account_id' => $account->id]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);

        $this->actingAs($judge)
            ->get(route('dashboard.accounts.festivals.show', [$account, $edition]))
            ->assertOk()
            ->assertSee(__('app.festival_score_sheets'))
            ->assertDontSee(__('app.festival_tab_applications'));
        $this->actingAs($judge)
            ->get(route('dashboard.accounts.festivals.judging.index', [$account, $edition]))
            ->assertRedirect(route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $edition]));
    }

    public function test_each_workflow_is_not_found_for_an_edition_from_another_account(): void
    {
        [$account] = $this->festival();
        [$otherAccount, $otherEdition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);

        foreach ([
            'dashboard.accounts.festivals.show',
            'dashboard.accounts.festivals.applications',
            'dashboard.accounts.festivals.performances',
            'dashboard.accounts.festivals.program',
            'dashboard.accounts.festivals.judging.index',
            'dashboard.accounts.festivals.judging.judges.index',
            'dashboard.accounts.festivals.judging.criteria.index',
            'dashboard.accounts.festivals.judging.score-sheets.index',
            'dashboard.accounts.festivals.judging.results.index',
            'dashboard.accounts.festivals.tickets',
            'dashboard.accounts.festivals.admission-types.create',
            'dashboard.accounts.festivals.communication',
            'dashboard.accounts.festivals.settings',
            'dashboard.accounts.festivals.settings.stages',
            'dashboard.accounts.festivals.stages.create',
            'dashboard.accounts.festivals.settings.directions',
            'dashboard.accounts.festivals.directions.create',
            'dashboard.accounts.festivals.settings.categories',
            'dashboard.accounts.festivals.categories.create',
            'dashboard.accounts.festivals.settings.workflows',
            'dashboard.accounts.festivals.workflows.create',
            'dashboard.accounts.festivals.settings.requirements',
            'dashboard.accounts.festivals.requirements.create',
            'dashboard.accounts.festivals.settings.fees',
            'dashboard.accounts.festivals.charge-definitions.create',
            'dashboard.accounts.festivals.settings.content',
            'dashboard.accounts.festivals.settings.content.sections',
            'dashboard.accounts.festivals.content.create',
            'dashboard.accounts.festivals.settings.content.documents',
            'dashboard.accounts.festivals.documents.create',
            'dashboard.accounts.festivals.settings.content.media',
            'dashboard.accounts.festivals.media.create',
            'dashboard.accounts.festivals.scanner',
        ] as $route) {
            $this->actingAs($owner)->get(route($route, [$account, $otherEdition]))->assertNotFound();
        }

        $this->assertNotSame($account->id, $otherAccount->id);
    }

    public function test_mutations_return_to_their_owning_workflow(): void
    {
        [$account, $edition, $category, $portalUser, $entry] = $this->festivalWithEntry();
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.categories.store', [$account, $edition]), [
            'code' => 'new-category',
            'name' => 'New category',
            'festival_direction_id' => $category->festival_direction_id,
            'min_members' => 1,
            'max_members' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]));

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.stages.store', [$account, $edition]), [
            'name' => 'Second stage',
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.stages', [$account, $edition]));

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.admission-types.store', [$account, $edition]), [
            'name' => 'Balcony',
            'inventory' => 20,
            'price' => '500.00',
            'max_per_order' => 4,
        ])->assertRedirect(route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'types']));

        $this->actingAs($owner)->patch(route('dashboard.accounts.festivals.entries.review', [$account, $edition, $entry]), [
            'status' => 'accepted',
            'qualification_status' => 'not_required',
        ])->assertRedirect(route('dashboard.accounts.festivals.applications', [$account, $edition]));

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.announcements.store', [$account, $edition]), [
            'subject' => 'Workflow update',
            'body' => 'The schedule is ready.',
        ])->assertRedirect(route('dashboard.accounts.festivals.communication', [$account, $edition, 'tab' => 'announcements']));

        $this->assertSame($edition->id, $category->festival_edition_id);
    }

    public function test_step_less_staff_acceptance_cannot_exceed_category_capacity(): void
    {
        [$account, $edition, $category, $portalUser, $entry] = $this->festivalWithEntry();
        $originalStatus = $entry->status;
        $category->forceFill(['maximum_accepted_entries' => 1])->save();
        $occupied = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => FestivalEntryStatus::Accepted,
            'accepted_at' => now(),
            'registration_completed_at' => now(),
        ]);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $reviewUrl = route('dashboard.accounts.festivals.entries.review', [$account, $edition, $entry]);
        $payload = [
            'status' => FestivalEntryStatus::Accepted->value,
            'qualification_status' => 'not_required',
        ];

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]))
            ->patch($reviewUrl, $payload)
            ->assertSessionHasErrors('festival_category_id');
        $this->assertSame($originalStatus, $entry->refresh()->status);
        $this->assertNull($entry->registration_completed_at);

        $occupied->forceFill(['status' => FestivalEntryStatus::Rejected, 'registration_completed_at' => null])->save();
        $this->actingAs($owner)
            ->patch($reviewUrl, $payload)
            ->assertRedirect(route('dashboard.accounts.festivals.applications', [$account, $edition]));
        $this->assertSame(FestivalEntryStatus::Accepted, $entry->refresh()->status);
        $this->assertNotNull($entry->accepted_at);
        $this->assertNotNull($entry->registration_completed_at);
    }

    public function test_settings_pages_render_localized_system_labels_and_registration_fields_copy(): void
    {
        [$account, $edition] = $this->festival();
        $account->update(['default_language' => 'uk']);
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.directions', [$account, $edition]))
            ->assertOk()
            ->assertSee('Напрямки')
            ->assertSee('Залежності')
            ->assertDontSee('Класифікації');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]))
            ->assertOk()
            ->assertSee('Поля реєстрації')
            ->assertDontSee('Registration fields');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.workflows.create', [$account, $edition]))
            ->assertOk()
            ->assertSee('Перевірка заявки')
            ->assertSee('Автоматично')
            ->assertDontSee('Application review')
            ->assertDontSee('>organizer<', false);
    }

    /** @return array{Account, FestivalEdition, FestivalCategory} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'timezone' => 'Europe/Kyiv',
        ]);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);

        return [$account, $edition, $category];
    }

    /** @return array{Account, FestivalEdition, FestivalCategory, FestivalPortalUser, FestivalEntry} */
    private function festivalWithEntry(): array
    {
        [$account, $edition, $category] = $this->festival();
        $portalUser = FestivalPortalUser::factory()->for($account)->create(['email' => 'applicant-secret@example.test']);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Performer Sentinel',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);

        return [$account, $edition, $category, $portalUser, $entry];
    }

    /** @param list<StudioPermission> $permissions */
    private function staff(Account $account, array $permissions): User
    {
        $staff = User::factory()->create();
        $account->users()->attach($staff->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => array_map(fn (StudioPermission $permission): string => $permission->value, $permissions),
        ]);

        return $staff;
    }
}
