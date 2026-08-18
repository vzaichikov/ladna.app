<?php

namespace Tests\Feature;

use App\Enums\FestivalRegistrantType;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FestivalApplicationQuickProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_create_and_edit_application_pages_render_the_quick_profile_modal_outside_the_entry_form(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($category)->for($portalUser)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
        ]);

        foreach ([
            route('festival.portal.entries.create', [$account->slug, $edition->slug]),
            route('festival.portal.entries.edit', [$account->slug, $entry]),
        ] as $url) {
            $response = $this->actingAs($portalUser, 'festival')->get($url);

            $response->assertOk()
                ->assertSee('data-festival-quick-profile-summary', false)
                ->assertSee('data-festival-quick-profile-open', false)
                ->assertSee('data-festival-quick-profile-modal', false)
                ->assertSee('role="dialog"', false)
                ->assertSee('aria-modal="true"', false)
                ->assertSee('data-async-form', false)
                ->assertSee(route('festival.portal.profile.application.update', $account->slug), false)
                ->assertSee('name="first_name"', false)
                ->assertSee('name="last_name"', false)
                ->assertSee('name="city"', false)
                ->assertSee('name="studio_name"', false)
                ->assertDontSee('name="email"', false)
                ->assertDontSee('name="phone"', false);

            $content = $response->getContent();
            $entryFormStart = strpos($content, '<form method="POST"');
            $entryFormEnd = strpos($content, '</form>', $entryFormStart);
            $modalStart = strpos($content, 'data-festival-quick-profile-modal');

            $this->assertIsInt($entryFormStart);
            $this->assertIsInt($entryFormEnd);
            $this->assertIsInt($modalStart);
            $this->assertGreaterThan($entryFormEnd, $modalStart);
        }
    }

    public function test_registrant_can_update_only_application_profile_fields_asynchronously(): void
    {
        [$account, , $portalUser] = $this->festival([
            'registrant_type' => FestivalRegistrantType::AdultAthlete,
            'email' => 'original@example.test',
            'email_normalized' => 'original@example.test',
        ]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'is_profile_owner' => true,
            'first_name' => $portalUser->first_name,
            'last_name' => $portalUser->last_name,
            'date_of_birth' => '2000-05-12',
        ]);

        $response = $this->actingAs($portalUser, 'festival')->putJson(
            route('festival.portal.profile.application.update', $account->slug),
            [
                'first_name' => '  Olena  ',
                'last_name' => '  Sky  ',
                'city' => '  Kyiv  ',
                'studio_name' => '  Flight Studio  ',
                'email' => 'forged@example.test',
                'phone' => '+380501111111',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('message', __('app.festival_profile_saved'))
            ->assertJsonPath('resource_id', $portalUser->id)
            ->assertJsonStructure(['fragment_html']);
        $this->assertStringContainsString('data-festival-application-fragment-key="entry-profile"', $response->json('fragment_html'));
        $this->assertStringContainsString('Olena Sky', $response->json('fragment_html'));

        $portalUser->refresh();
        $this->assertSame('Olena', $portalUser->first_name);
        $this->assertSame('Sky', $portalUser->last_name);
        $this->assertSame('Kyiv', $portalUser->city);
        $this->assertSame('Flight Studio', $portalUser->studio_name);
        $this->assertSame('original@example.test', $portalUser->email);
        $this->assertNotSame('+380501111111', $portalUser->phone);

        $participant->refresh();
        $this->assertSame('Olena', $participant->first_name);
        $this->assertSame('Sky', $participant->last_name);
        $this->assertSame('2000-05-12', $participant->date_of_birth->toDateString());
    }

    public function test_quick_profile_validation_returns_json_errors_and_reopens_the_modal_for_html_fallback(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $url = route('festival.portal.profile.application.update', $account->slug);
        $entryUrl = route('festival.portal.entries.create', [$account->slug, $edition->slug]);

        $this->actingAs($portalUser, 'festival')->putJson($url, [
            'first_name' => ['not-a-string'],
            'last_name' => str_repeat('x', 256),
            'city' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'first_name',
            'last_name',
            'city',
            'studio_name',
        ]);

        $this->followingRedirects()
            ->from($entryUrl)
            ->put($url, [
                'first_name' => '',
                'last_name' => '',
                'city' => '',
                'studio_name' => '',
            ])
            ->assertOk()
            ->assertSee('data-open="true"', false)
            ->assertSee('data-field-error="first_name"', false)
            ->assertSee('data-field-error="studio_name"', false);
    }

    public function test_quick_profile_html_submission_redirects_back_and_is_limited_to_the_authenticated_account(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $entryUrl = route('festival.portal.entries.create', [$account->slug, $edition->slug]);

        $this->actingAs($portalUser, 'festival')
            ->from($entryUrl)
            ->put(route('festival.portal.profile.application.update', $account->slug), [
                'first_name' => 'Iryna',
                'last_name' => 'Air',
                'city' => 'Lviv',
                'studio_name' => 'Air Lab',
            ])
            ->assertRedirect($entryUrl)
            ->assertSessionHas('status', __('app.festival_profile_saved'));

        $this->assertDatabaseHas('festival_portal_users', [
            'id' => $portalUser->id,
            'first_name' => 'Iryna',
            'last_name' => 'Air',
            'city' => 'Lviv',
            'studio_name' => 'Air Lab',
        ]);

        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $this->actingAs($portalUser, 'festival')
            ->put(route('festival.portal.profile.application.update', $otherAccount->slug), [
                'first_name' => 'Forged',
                'last_name' => 'Account',
                'city' => 'Dnipro',
                'studio_name' => 'Wrong Studio',
            ])
            ->assertNotFound();
    }

    /** @return array{Account, FestivalEdition, FestivalPortalUser} */
    private function festival(array $portalUserAttributes = []): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create($portalUserAttributes);

        return [$account, $edition, $portalUser];
    }
}
