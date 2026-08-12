<?php

namespace Tests\Feature;

use App\Actions\Festivals\SyncFestivalProfileParticipant;
use App\Enums\AccountRole;
use App\Enums\FestivalPortalRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FestivalPortalUserDirectoryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_directory_has_role_tabs_filters_counts_and_twenty_item_pagination(): void
    {
        [$account, $edition, $category] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        FestivalPortalUser::factory()->count(21)->for($account)->create();
        $needle = FestivalPortalUser::factory()->for($account)->create([
            'first_name' => 'Needle',
            'last_name' => 'Registrant',
            'email' => 'needle@example.test',
            'email_normalized' => 'needle@example.test',
        ]);
        FestivalParticipant::factory()->count(2)->for($needle)->create(['account_id' => $account->id]);
        FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $needle->id,
        ]);

        $page = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.users.index', [$account, $edition]));
        $page->assertOk()
            ->assertSee(__('app.festival_user_tab_participants'))
            ->assertSee(__('app.festival_user_tab_judges'))
            ->assertSee(__('app.festival_users'));
        $this->assertSame(20, $page->viewData('portalUsers')->count());
        $this->assertSame(22, $page->viewData('portalUsers')->total());

        $filtered = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.users.index', [
            $account,
            $edition,
            'tab' => 'participants',
            'q' => 'Needle',
            'status' => 'active',
        ]));
        $filtered->assertOk()->assertSee('needle@example.test');
        $this->assertSame(1, $filtered->viewData('portalUsers')->total());
        $listed = $filtered->viewData('portalUsers')->first();
        $this->assertSame(2, $listed->participants_count);
        $this->assertSame(1, $listed->current_edition_entries_count);
    }

    public function test_registration_managers_can_manage_participant_profiles_but_not_judges(): void
    {
        [$account, $edition] = $this->festival();
        $registrationManager = $this->staff($account, [StudioPermission::ManageFestivalRegistrations]);

        $this->actingAs($registrationManager)
            ->get(route('dashboard.accounts.festivals.users.index', [$account, $edition]))
            ->assertOk()
            ->assertSee(__('app.festival_user_tab_participants'))
            ->assertDontSee(__('app.festival_user_tab_judges'));

        $this->actingAs($registrationManager)
            ->get(route('dashboard.accounts.festivals.users.index', [$account, $edition, 'tab' => 'judges']))
            ->assertForbidden();
        $this->actingAs($registrationManager)
            ->get(route('dashboard.accounts.festivals.users.create', [$account, $edition, 'judge']))
            ->assertForbidden();
    }

    public function test_staff_created_profile_requires_password_hashes_it_and_keeps_role_immutable(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $payload = $this->registrantPayload();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.users.store', [$account, $edition, 'registrant']), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $portalUser = FestivalPortalUser::query()->whereBelongsTo($account)->where('email_normalized', $payload['email'])->firstOrFail();
        $this->assertSame(FestivalPortalRole::Registrant, $portalUser->role);
        $this->assertTrue(Hash::check('secret1', (string) $portalUser->password));

        $editUrl = route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser]);
        $update = $this->registrantPayload([
            'role' => FestivalPortalRole::Judge->value,
            'password' => '',
            'password_confirmation' => '',
        ]);
        $this->actingAs($owner)
            ->from($editUrl)
            ->put(route('dashboard.accounts.festivals.users.update', [$account, $edition, $portalUser]), $update)
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors('role');

        $this->assertSame(FestivalPortalRole::Registrant, $portalUser->refresh()->role);
        $this->assertTrue(Hash::check('secret1', (string) $portalUser->password));
    }

    public function test_staff_participant_form_only_offers_supported_registration_types(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $createUrl = route('dashboard.accounts.festivals.users.create', [$account, $edition, 'registrant']);

        $this->actingAs($owner)
            ->get($createUrl)
            ->assertOk()
            ->assertSeeInOrder([
                'value="adult_athlete"',
                'value="coach"',
            ], false)
            ->assertDontSee('value="guardian"', false);

        $this->from($createUrl)
            ->post(route('dashboard.accounts.festivals.users.store', [$account, $edition, 'registrant']), $this->registrantPayload([
                'registrant_type' => 'guardian',
            ]))
            ->assertRedirect($createUrl)
            ->assertSessionHasErrors('registrant_type');
    }

    public function test_staff_cannot_change_an_adult_profile_to_coach(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $portalUser = FestivalPortalUser::factory()->for($account)->create(['registrant_type' => 'adult_athlete']);
        $participant = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'is_profile_owner' => true,
        ]);
        $editUrl = route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser]);

        $this->actingAs($owner)
            ->from($editUrl)
            ->put(route('dashboard.accounts.festivals.users.update', [$account, $edition, $portalUser]), $this->registrantPayload([
                'email' => $portalUser->email,
                'phone' => $portalUser->phone,
                'registrant_type' => 'coach',
                'password' => '',
                'password_confirmation' => '',
            ]))
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors('registrant_type');

        $this->assertSame('adult_athlete', $portalUser->refresh()->registrant_type->value);
        $this->assertTrue($portalUser->profileParticipant->is($participant));
    }

    public function test_judge_staff_requests_reject_registrant_fields_and_sync_action_is_role_safe(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $createUrl = route('dashboard.accounts.festivals.users.create', [$account, $edition, 'judge']);
        $hiddenRegistrantFields = [
            'registrant_type' => 'adult_athlete',
            'date_of_birth' => '2000-01-01',
            'city' => 'Kyiv',
            'studio_name' => 'Registrant Studio',
            'instagram_url' => 'https://example.test/registrant',
        ];

        $this->actingAs($owner)
            ->from($createUrl)
            ->post(route('dashboard.accounts.festivals.users.store', [$account, $edition, 'judge']), [
                'first_name' => 'Forged',
                'last_name' => 'Judge',
                'email' => 'forged.judge@example.test',
                'phone' => null,
                'locale' => 'en',
                'password' => 'secret1',
                'password_confirmation' => 'secret1',
                'is_active' => 1,
                ...$hiddenRegistrantFields,
            ])
            ->assertRedirect($createUrl)
            ->assertSessionHasErrors(array_keys($hiddenRegistrantFields));
        $this->assertDatabaseMissing('festival_portal_users', ['email_normalized' => 'forged.judge@example.test']);

        $judge = FestivalPortalUser::factory()->for($account)->judge()->create();
        $editUrl = route('dashboard.accounts.festivals.users.edit', [$account, $edition, $judge]);
        $this->actingAs($owner)
            ->from($editUrl)
            ->put(route('dashboard.accounts.festivals.users.update', [$account, $edition, $judge]), [
                ...$this->judgePayload($judge, true),
                ...$hiddenRegistrantFields,
            ])
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors(array_keys($hiddenRegistrantFields));

        $judge->forceFill(['registrant_type' => 'adult_athlete'])->save();
        $this->assertNull(app(SyncFestivalProfileParticipant::class)->execute($judge, '2000-01-01'));
        $this->assertSame(0, $judge->participants()->count());
    }

    public function test_directory_and_forms_are_account_isolated(): void
    {
        [$account, $edition] = $this->festival();
        [$otherAccount] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $otherProfile = FestivalPortalUser::factory()->for($otherAccount)->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.users.edit', [$account, $edition, $otherProfile]))
            ->assertNotFound();
    }

    public function test_active_assignments_block_judge_deactivation_and_judge_preselection_filters_profiles(): void
    {
        [$account, $edition, $category] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $judge = FestivalPortalUser::factory()->for($account)->judge()->create();
        $registrant = FestivalPortalUser::factory()->for($account)->create();
        $inactiveJudge = FestivalPortalUser::factory()->for($account)->judge()->inactive()->create();
        $assignment = FestivalJudgeAssignment::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $judge->id,
            'display_name' => 'Directory Judge',
            'is_active' => true,
        ]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);

        $judgeForm = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.judges.create', [
            $account,
            $edition,
            'festival_portal_user_id' => $judge->id,
        ]));
        $judgeForm->assertOk()
            ->assertSee('value="'.$judge->id.'"', false)
            ->assertDontSee('value="'.$registrant->id.'"', false)
            ->assertDontSee('value="'.$inactiveJudge->id.'"', false);

        $editUrl = route('dashboard.accounts.festivals.users.edit', [$account, $edition, $judge]);
        $this->actingAs($owner)
            ->from($editUrl)
            ->put(route('dashboard.accounts.festivals.users.update', [$account, $edition, $judge]), $this->judgePayload($judge, false))
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors('is_active');
        $this->assertTrue($judge->refresh()->is_active);

        $assignment->update(['is_active' => false]);
        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.users.update', [$account, $edition, $judge]), $this->judgePayload($judge, false))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertFalse($judge->refresh()->is_active);
    }

    public function test_staff_roster_uses_dedicated_create_edit_archive_pages_and_preserves_referenced_history(): void
    {
        [$account, $edition, $category] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.users.participants.create', [$account, $edition, $portalUser]))
            ->assertOk()
            ->assertSee('name="date_of_birth"', false);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.users.participants.store', [$account, $edition, $portalUser]), [
                'first_name' => 'Roster',
                'last_name' => 'Member',
                'date_of_birth' => '2010-05-01',
                'notes' => 'Manual profile',
            ])
            ->assertRedirect(route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser]));
        $participant = $portalUser->participants()->where('first_name', 'Roster')->firstOrFail();

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.users.participants.update', [$account, $edition, $portalUser, $participant]), [
                'first_name' => 'Updated',
                'last_name' => 'Member',
                'date_of_birth' => '2010-05-01',
            ])
            ->assertRedirect();
        $this->assertSame('Updated', $participant->refresh()->first_name);

        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => 'accepted',
        ]);
        $entry->participants()->attach($participant->id, ['account_id' => $account->id, 'sort_order' => 0]);

        $archiveUrl = route('dashboard.accounts.festivals.users.participants.archive', [$account, $edition, $portalUser, $participant]);
        $this->actingAs($owner)->get($archiveUrl)->assertOk()->assertSee(__('app.festival_archive_participant'));
        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.users.participants.destroy', [$account, $edition, $portalUser, $participant]))
            ->assertStatus(409);

        $this->assertNull($participant->refresh()->archived_at);
        $this->assertModelExists($entry);
        $this->assertTrue($entry->participants()->whereKey($participant->id)->exists());
    }

    public function test_profile_owner_participant_is_managed_only_through_the_profile(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $portalUser = FestivalPortalUser::factory()->for($account)->create(['registrant_type' => 'adult_athlete']);
        $participant = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'is_profile_owner' => true,
        ]);
        $editRoute = route('dashboard.accounts.festivals.users.participants.edit', [$account, $edition, $portalUser, $participant]);
        $archiveRoute = route('dashboard.accounts.festivals.users.participants.archive', [$account, $edition, $portalUser, $participant]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser]))
            ->assertOk()
            ->assertDontSee($editRoute, false)
            ->assertDontSee($archiveRoute, false);
        $this->get($editRoute)->assertStatus(409);
        $this->get($archiveRoute)->assertStatus(409);
        $this->put(route('dashboard.accounts.festivals.users.participants.update', [$account, $edition, $portalUser, $participant]), [
            'first_name' => 'Forged',
            'last_name' => 'Change',
            'date_of_birth' => '2000-01-01',
        ])->assertStatus(409);
        $this->patch(route('dashboard.accounts.festivals.users.participants.destroy', [$account, $edition, $portalUser, $participant]))->assertStatus(409);
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

    /** @param array<string, mixed> $overrides */
    private function registrantPayload(array $overrides = []): array
    {
        return [
            'first_name' => 'Manual',
            'last_name' => 'Registrant',
            'email' => 'manual.registrant@example.test',
            'phone' => '+380501112233',
            'registrant_type' => 'coach',
            'city' => 'Kyiv',
            'studio_name' => 'Festival Studio',
            'locale' => 'en',
            'password' => 'secret1',
            'password_confirmation' => 'secret1',
            'is_active' => 1,
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function judgePayload(FestivalPortalUser $judge, bool $active): array
    {
        return [
            'first_name' => $judge->first_name,
            'last_name' => $judge->last_name,
            'patronymic' => $judge->patronymic,
            'email' => $judge->email,
            'phone' => null,
            'locale' => $judge->locale,
            'password' => '',
            'password_confirmation' => '',
            'is_active' => $active ? 1 : 0,
        ];
    }
}
