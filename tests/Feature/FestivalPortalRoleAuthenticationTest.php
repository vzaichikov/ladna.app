<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FestivalPortalRoleAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_participant_and_judge_cabinets_are_role_isolated(): void
    {
        [$account] = $this->festival();
        $participant = FestivalPortalUser::factory()->for($account)->create();
        $judge = FestivalPortalUser::factory()->for($account)->judge()->create();

        $this->actingAs($participant, 'festival')
            ->get(route('festival.portal.judge.dashboard', $account->slug))
            ->assertForbidden();

        $this->actingAs($judge, 'festival')
            ->get(route('festival.portal.dashboard', $account->slug))
            ->assertForbidden();

        $this->actingAs($judge, 'festival')
            ->get(route('festival.portal.entries.index', $account->slug))
            ->assertForbidden();
    }

    public function test_participant_and_judge_cabinets_render_the_shared_studio_header_and_footer(): void
    {
        [$account] = $this->festival();
        $participant = FestivalPortalUser::factory()->for($account)->create();
        $judge = FestivalPortalUser::factory()->for($account)->judge()->create();

        $participantResponse = $this->actingAs($participant, 'festival')
            ->get(route('festival.portal.dashboard', $account->slug))
            ->assertOk()
            ->assertSee('data-public-studio-header', false)
            ->assertSee('data-festival-header-logout', false)
            ->assertDontSee('data-festival-nav-logout', false)
            ->assertSee(route('help.show', 'festival-participants'), false)
            ->assertSee('data-festival-participant-help-link', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener"', false)
            ->assertSee('data-lucide="circle-help"', false)
            ->assertSee('data-public-studio-footer-identity', false)
            ->assertSee('data-public-studio-footer-name', false);

        $participantResponse->assertSeeInOrder([
            'data-public-studio-header',
            'data-festival-header-logout',
            '</header>',
            'data-festival-participant-help-link',
        ], false);

        $this->actingAs($judge, 'festival')
            ->get(route('festival.portal.judge.dashboard', $account->slug))
            ->assertOk()
            ->assertSee('data-public-studio-header', false)
            ->assertDontSee('data-festival-header-logout', false)
            ->assertSee('data-festival-nav-logout', false)
            ->assertDontSee(route('help.show', 'festival-participants'), false)
            ->assertDontSee('data-festival-participant-help-link', false)
            ->assertSee('data-public-studio-footer-identity', false)
            ->assertSee('data-public-studio-footer-name', false);
    }

    public function test_judge_dashboard_lists_only_active_assignment_cards(): void
    {
        [$account, $edition, $category] = $this->festival();
        $judge = FestivalPortalUser::factory()->for($account)->judge()->create();
        $active = FestivalJudgeAssignment::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $judge->id,
            'display_name' => 'Active Judge',
            'is_active' => true,
        ]);
        $active->categories()->attach($category->id, ['account_id' => $account->id]);

        $otherSeries = FestivalSeries::factory()->for($account)->create();
        $inactiveEdition = FestivalEdition::factory()->published()->for($otherSeries)->create([
            'account_id' => $account->id,
            'title' => 'Hidden inactive assignment',
        ]);
        FestivalJudgeAssignment::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $inactiveEdition->id,
            'festival_portal_user_id' => $judge->id,
            'display_name' => 'Inactive Judge',
            'is_active' => false,
        ]);

        $this->actingAs($judge, 'festival')
            ->get(route('festival.portal.judge.dashboard', $account->slug))
            ->assertOk()
            ->assertSee($edition->title)
            ->assertDontSee('Hidden inactive assignment')
            ->assertSee(route('festival.portal.judging.index', [$account->slug, $edition->slug]), false)
            ->assertSee(route('festival.portal.battle-votes.index', [$account->slug, $edition->slug]), false);

        $this->get(route('festival.portal.judging.index', [$account->slug, $edition->slug]))
            ->assertOk()
            ->assertSee('max-w-6xl', false);
        $this->get(route('festival.portal.battle-votes.index', [$account->slug, $edition->slug]))
            ->assertOk()
            ->assertSee('max-w-6xl', false);
    }

    public function test_judging_routes_require_an_active_assignment_for_the_current_edition(): void
    {
        [$account, $edition] = $this->festival();
        $judge = FestivalPortalUser::factory()->for($account)->judge()->create();

        $this->actingAs($judge, 'festival')
            ->get(route('festival.portal.judging.index', [$account->slug, $edition->slug]))
            ->assertNotFound();

        FestivalJudgeAssignment::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $judge->id,
            'display_name' => 'Assigned Judge',
            'is_active' => false,
        ]);

        $this->actingAs($judge, 'festival')
            ->get(route('festival.portal.battle-votes.index', [$account->slug, $edition->slug]))
            ->assertNotFound();
    }

    public function test_role_and_account_bound_intended_destination_returns_a_judge_to_judging(): void
    {
        [$account, $edition, $category] = $this->festival();
        $judge = FestivalPortalUser::factory()->for($account)->judge()->create([
            'email' => 'intended-judge@example.com',
            'email_normalized' => 'intended-judge@example.com',
            'password' => 'judge-secret',
        ]);
        $assignment = FestivalJudgeAssignment::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $judge->id,
            'display_name' => 'Intended Judge',
            'is_active' => true,
        ]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        $destination = route('festival.portal.judging.index', [$account->slug, $edition->slug]);

        $this->get($destination)
            ->assertRedirect(route('festival.judge.login', $account->slug));

        $this->post(route('festival.judge.login.email', $account->slug), [
            'email' => 'intended-judge@example.com',
            'password' => 'judge-secret',
        ])->assertRedirect($destination);
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
}
