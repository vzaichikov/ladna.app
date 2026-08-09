<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalChargeDefinition;
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
            'dashboard.accounts.festivals.judging.index' => 'festival_tab_judging_results',
            'dashboard.accounts.festivals.tickets' => 'festival_tab_tickets_entrance',
            'dashboard.accounts.festivals.communication' => 'festival_tab_communication',
            'dashboard.accounts.festivals.settings' => 'festival_tab_settings',
            'dashboard.accounts.festivals.edit' => 'festival_tab_settings',
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
            $this->assertSame(1, substr_count($response->getContent(), 'aria-current="page"'), $route);
        }
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

        $this->actingAs($checkInStaff)
            ->get(route('dashboard.accounts.festivals.tickets', [$account, $edition]))
            ->assertOk()
            ->assertSee(__('app.festival_open_scanner'))
            ->assertDontSee('finance-buyer-secret@example.test')
            ->assertDontSee(__('app.festival_admission_revenue'));
        $this->actingAs($checkInStaff)->get(route('dashboard.accounts.festivals.scanner', [$account, $edition]))->assertOk();
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
            ->assertSee(__('app.festival_tab_judging_results'))
            ->assertDontSee(__('app.festival_tab_applications'));
        $this->actingAs($judge)->get(route('dashboard.accounts.festivals.judging.index', [$account, $edition]))->assertOk();
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
            'dashboard.accounts.festivals.tickets',
            'dashboard.accounts.festivals.communication',
            'dashboard.accounts.festivals.settings',
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
            'workflow' => 'direct',
            'min_members' => 1,
            'max_members' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings', [$account, $edition]));

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

    /** @return array{Account, FestivalEdition, FestivalCategory} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
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
            'performer_name' => 'Performer Sentinel',
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
