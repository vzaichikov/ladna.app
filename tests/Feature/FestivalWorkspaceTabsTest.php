<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalDirection;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\FestivalTicketOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FestivalWorkspaceTabsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_deep_link_to_each_workflow_with_one_active_sidebar_item(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $routes = [
            'dashboard.accounts.festivals.show' => 'festival_tab_overview',
            'dashboard.accounts.festivals.applications' => 'festival_tab_applications',
            'dashboard.accounts.festivals.program' => 'festival_tab_program',
            'dashboard.accounts.festivals.judging.judges.index' => 'festival_judges',
            'dashboard.accounts.festivals.judging.judges.create' => 'festival_judges',
            'dashboard.accounts.festivals.judging.criteria.index' => 'festival_criteria',
            'dashboard.accounts.festivals.judging.criteria.create' => 'festival_criteria',
            'dashboard.accounts.festivals.judging.score-sheets.index' => 'festival_score_sheets',
            'dashboard.accounts.festivals.judging.results.index' => 'festival_results',
            'dashboard.accounts.festivals.tickets' => 'festival_tab_tickets_entrance',
            'dashboard.accounts.festivals.communication' => 'festival_tab_communication',
            'dashboard.accounts.festivals.settings' => 'festival_settings_overview',
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
            $response = $this->actingAs($owner)->get(route($route, [$account, $edition]));
            $response->assertOk()
                ->assertSee(__('app.'.$activeLabel))
                ->assertSee('data-workspace="festival"', false)
                ->assertSee(__('app.festival_workspace_back'))
                ->assertSee(__('app.festival_workspace_back_to_studio'))
                ->assertDontSee(__('app.my_studio'));
            $this->assertSame(2, substr_count($response->getContent(), 'aria-current="page"'), $route);
        }

        $category = $edition->categories()->firstOrFail();
        $editResponse = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.categories.edit', [$account, $edition, $category]));
        $editResponse->assertOk()->assertSee(__('app.festival_categories'));
        $this->assertSame(2, substr_count($editResponse->getContent(), 'aria-current="page"'));
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
        FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id]);
        FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'status' => 'paid',
            'buyer_name' => 'Finance Buyer Sentinel',
            'buyer_email' => 'finance-buyer-secret@example.test',
            'amount_cents' => 30000,
            'paid_at' => now(),
        ]);

        $this->actingAs($registrationStaff)
            ->get(route('dashboard.accounts.festivals.applications', [$account, $edition]))
            ->assertOk()
            ->assertSee($portalUser->email)
            ->assertSee(__('app.festival_application_review'))
            ->assertDontSee(__('app.festival_admission_revenue'));
        $this->actingAs($registrationStaff)->get(route('dashboard.accounts.festivals.tickets', [$account, $edition]))->assertForbidden();

        $this->actingAs($scheduleStaff)->get(route('dashboard.accounts.festivals.program', [$account, $edition]))->assertOk();
        $this->actingAs($scheduleStaff)->get(route('dashboard.accounts.festivals.applications', [$account, $edition]))->assertForbidden();

        $this->actingAs($financeStaff)
            ->get(route('dashboard.accounts.festivals.applications', [$account, $edition]))
            ->assertOk()
            ->assertSee('Private participation charge')
            ->assertDontSee($portalUser->email)
            ->assertDontSee(__('app.festival_application_review'));
        $this->actingAs($financeStaff)
            ->get(route('dashboard.accounts.festivals.tickets', [$account, $edition]))
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

    public function test_applications_reports_current_directions_by_stable_identity_and_shows_live_category_rules(): void
    {
        [$account, $edition, $firstCategory] = $this->festivalWithEntry();
        $registrationStaff = $this->staff($account, [StudioPermission::ManageFestivalRegistrations]);
        $firstDirection = $firstCategory->direction()->firstOrFail();
        $firstDirection->update(['name' => 'Shared direction', 'code' => 'shared-one']);
        $secondDirection = FestivalDirection::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Shared direction',
            'code' => 'shared-two',
        ]);
        $secondCategory = FestivalCategory::factory()->for($edition)->for($secondDirection)->create([
            'account_id' => $account->id,
            'name' => 'Second category',
        ]);
        $secondPortalUser = FestivalPortalUser::factory()->for($account)->create();
        FestivalEntry::factory()->for($secondCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $secondPortalUser->id,
            'entry_name' => 'Second act',
        ]);

        $duplicateLabels = $this->actingAs($registrationStaff)
            ->get(route('dashboard.accounts.festivals.applications', [$account, $edition]));

        $duplicateLabels->assertOk();
        $this->assertSame([
            ['label' => 'Shared direction', 'count' => 1],
            ['label' => 'Shared direction', 'count' => 1],
        ], $duplicateLabels->viewData('directionStatistics')->values()->all());

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

        $currentRules = $this->actingAs($registrationStaff)
            ->get(route('dashboard.accounts.festivals.applications', [$account, $edition]));

        $currentRules->assertOk()
            ->assertSee('Moved direction')
            ->assertSee('Live staff condition.', false)
            ->assertSee(__('app.festival_participants_range', ['min' => 2, 'max' => 4]))
            ->assertSee(__('app.festival_category_deadline_value', [
                'date' => $categoryDeadline->timezone($edition->timezone)->format('d.m.Y H:i'),
                'timezone' => $edition->timezone,
            ]));
        $this->assertSame([
            ['label' => 'Moved direction', 'count' => 1],
            ['label' => 'Shared direction', 'count' => 1],
        ], $currentRules->viewData('directionStatistics')->values()->all());
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
            'dashboard.accounts.festivals.program',
            'dashboard.accounts.festivals.judging.index',
            'dashboard.accounts.festivals.judging.judges.index',
            'dashboard.accounts.festivals.judging.criteria.index',
            'dashboard.accounts.festivals.judging.score-sheets.index',
            'dashboard.accounts.festivals.judging.results.index',
            'dashboard.accounts.festivals.tickets',
            'dashboard.accounts.festivals.communication',
            'dashboard.accounts.festivals.settings',
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
        [$account, $edition, $category, , $entry] = $this->festivalWithEntry();
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
        ])->assertRedirect(route('dashboard.accounts.festivals.program', [$account, $edition]));

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.admission-types.store', [$account, $edition]), [
            'name' => 'Balcony',
            'inventory' => 20,
            'price_cents' => 50000,
            'max_per_order' => 4,
        ])->assertRedirect(route('dashboard.accounts.festivals.tickets', [$account, $edition]));

        $this->actingAs($owner)->patch(route('dashboard.accounts.festivals.entries.review', [$account, $edition, $entry]), [
            'status' => 'accepted',
            'qualification_status' => 'not_required',
        ])->assertRedirect(route('dashboard.accounts.festivals.applications', [$account, $edition]));

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.announcements.store', [$account, $edition]), [
            'subject' => 'Workflow update',
            'body' => 'The schedule is ready.',
        ])->assertRedirect(route('dashboard.accounts.festivals.communication', [$account, $edition]));

        $this->assertSame($edition->id, $category->festival_edition_id);
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
