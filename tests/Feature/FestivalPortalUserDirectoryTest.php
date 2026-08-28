<?php

namespace Tests\Feature;

use App\Actions\Festivals\SyncFestivalProfileParticipant;
use App\Enums\AccountRole;
use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalNotificationStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalTeamMemberType;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalNotification;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    public function test_registrant_profile_and_notifications_are_independent_pages_with_a_legacy_redirect(): void
    {
        [$account, $edition, $category] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Current edition entry',
        ]);
        $otherEdition = FestivalEdition::factory()->published()->for(FestivalSeries::factory()->for($account))->create([
            'account_id' => $account->id,
            'title' => 'Previous Festival Edition',
        ]);
        $otherCategory = FestivalCategory::factory()->for($otherEdition)->create(['account_id' => $account->id]);
        $otherEntry = FestivalEntry::factory()->for($otherCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $otherEdition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Previous edition entry',
        ]);

        foreach (range(1, 21) as $index) {
            FestivalNotification::query()->create([
                'account_id' => $account->id,
                'festival_portal_user_id' => $portalUser->id,
                'festival_edition_id' => $edition->id,
                'festival_entry_id' => $entry->id,
                'type' => FestivalNotificationType::EntrySubmitted,
                'channel' => FestivalNotificationChannel::Email,
                'status' => FestivalNotificationStatus::Sent,
                'recipient_email' => $portalUser->email,
                'recipient_name' => $portalUser->displayName(),
                'subject' => 'Participant message '.$index,
                'text' => 'Participant notification '.$index,
                'dedupe_key' => 'participant-history-'.$portalUser->id.'-'.$index,
                'payload' => [],
                'sent_at' => now(),
            ]);
        }
        FestivalNotification::query()->create([
            'account_id' => $account->id,
            'festival_portal_user_id' => $portalUser->id,
            'festival_edition_id' => $otherEdition->id,
            'festival_entry_id' => $otherEntry->id,
            'type' => FestivalNotificationType::EntryStepReviewed,
            'channel' => FestivalNotificationChannel::Email,
            'status' => FestivalNotificationStatus::Failed,
            'recipient_email' => $portalUser->email,
            'recipient_name' => $portalUser->displayName(),
            'subject' => 'Cross-edition notification',
            'text' => '<script>escaped notification</script>',
            'dedupe_key' => 'participant-history-cross-edition-'.$portalUser->id,
            'payload' => [],
            'failed_at' => now(),
            'failure_reason' => 'Mailbox unavailable',
        ]);

        $otherPortalUser = FestivalPortalUser::factory()->for($account)->create();
        FestivalNotification::query()->create([
            'account_id' => $account->id,
            'festival_portal_user_id' => $otherPortalUser->id,
            'festival_edition_id' => $edition->id,
            'type' => FestivalNotificationType::Announcement,
            'channel' => FestivalNotificationChannel::Email,
            'status' => FestivalNotificationStatus::Sent,
            'recipient_email' => $otherPortalUser->email,
            'subject' => 'Other participant secret',
            'text' => 'Other participant secret',
            'dedupe_key' => 'other-participant-history-'.$otherPortalUser->id,
            'payload' => [],
        ]);

        $profilePage = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser]));
        $profilePage->assertOk()
            ->assertSee(__('app.festival_participant_edit_tab_profile'))
            ->assertSee(__('app.festival_participant_edit_tab_team'))
            ->assertSee(__('app.festival_participant_edit_tab_notifications'))
            ->assertDontSee('Cross-edition notification')
            ->assertViewMissing('festivalNotifications');

        $legacyNotificationPage = route('dashboard.accounts.festivals.users.edit', [
            $account,
            $edition,
            $portalUser,
            'tab' => 'notifications',
        ]);
        $notificationPageUrl = route('dashboard.accounts.festivals.users.notifications', [$account, $edition, $portalUser]);
        $this->get($legacyNotificationPage)->assertRedirect($notificationPageUrl);

        $notificationPage = $this->get($notificationPageUrl);
        $notificationPage->assertOk()
            ->assertSee('Previous Festival Edition')
            ->assertSee('Previous edition entry')
            ->assertSee('Cross-edition notification')
            ->assertSee('Mailbox unavailable')
            ->assertSee('&lt;script&gt;escaped notification&lt;/script&gt;', false)
            ->assertDontSee('<script>escaped notification</script>', false)
            ->assertDontSee('Other participant secret');
        $this->assertSame(20, $notificationPage->viewData('festivalNotifications')->count());
        $this->assertSame(22, $notificationPage->viewData('festivalNotifications')->total());

        $legacySecondPage = route('dashboard.accounts.festivals.users.edit', [
            $account,
            $edition,
            $portalUser,
            'tab' => 'notifications',
            'notifications_page' => 2,
        ]);
        $secondPageUrl = route('dashboard.accounts.festivals.users.notifications', [
            $account,
            $edition,
            $portalUser,
            'page' => 2,
        ]);
        $this->get($legacySecondPage)->assertRedirect($secondPageUrl);

        $secondPage = $this->get($secondPageUrl);
        $secondPage->assertOk();
        $this->assertSame(2, $secondPage->viewData('festivalNotifications')->count());

        $judge = FestivalPortalUser::factory()->for($account)->judge()->create();
        $this->get(route('dashboard.accounts.festivals.users.edit', [$account, $edition, $judge, 'tab' => 'notifications']))
            ->assertOk()
            ->assertDontSee(__('app.festival_participant_edit_tab_notifications'));
        $this->get(route('dashboard.accounts.festivals.users.notifications', [$account, $edition, $judge]))
            ->assertNotFound();
    }

    public function test_registrant_team_page_groups_roles_shows_avatars_and_is_not_embedded_in_profile(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'avatar_path' => 'festival/profile-owner.jpg',
        ]);
        $profileOwner = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'is_profile_owner' => true,
            'member_type' => FestivalTeamMemberType::Performer,
            'first_name' => 'ProfileOwnerUnique',
        ]);
        $performer = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'member_type' => FestivalTeamMemberType::Performer,
            'first_name' => 'PerformerUnique',
            'photo_path' => 'festival/performer.jpg',
        ]);
        $helper = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'member_type' => FestivalTeamMemberType::Helper,
            'first_name' => 'HelperUnique',
        ]);

        $profilePage = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser]));
        $profilePage->assertOk()
            ->assertDontSee('PerformerUnique')
            ->assertDontSee('HelperUnique');

        $teamPage = $this->get(route('dashboard.accounts.festivals.users.team', [$account, $edition, $portalUser]));
        $teamPage->assertOk()
            ->assertSee('data-festival-team-group="performers"', false)
            ->assertSee('data-festival-team-group="helpers"', false)
            ->assertSee('ProfileOwnerUnique')
            ->assertSee('PerformerUnique')
            ->assertSee('HelperUnique')
            ->assertSee(route('dashboard.accounts.festivals.users.photo', [$account, $edition, $portalUser]), false)
            ->assertSee(route('dashboard.accounts.festivals.users.participants.photo', [$account, $edition, $portalUser, $performer]), false)
            ->assertDontSee(route('dashboard.accounts.festivals.users.participants.edit', [$account, $edition, $portalUser, $profileOwner]), false);
        $this->assertCount(2, $teamPage->viewData('performers'));
        $this->assertCount(1, $teamPage->viewData('helpers'));

        $registrationManager = $this->staff($account, [StudioPermission::ManageFestivalRegistrations]);
        $this->actingAs($registrationManager)
            ->get(route('dashboard.accounts.festivals.users.team', [$account, $edition, $portalUser]))
            ->assertOk();
    }

    public function test_legacy_team_and_profile_lock_backfills_preserve_rows_archives_and_application_links(): void
    {
        [$account, $edition, $category] = $this->festival();
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'registrant_type_locked_at' => null,
        ]);
        $referenced = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'member_type' => FestivalTeamMemberType::Helper,
            'archived_at' => now()->subDay(),
            'photo_path' => null,
        ]);
        $unreferenced = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'member_type' => FestivalTeamMemberType::Helper,
            'photo_path' => null,
        ]);
        $firstCreatedAt = now()->subMonths(2)->startOfSecond();
        $firstEntry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Legacy first application',
            'created_at' => $firstCreatedAt,
            'updated_at' => $firstCreatedAt,
        ]);
        FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'created_at' => $firstCreatedAt->copy()->addMonth(),
        ]);
        $firstEntry->participants()->attach($referenced->id, [
            'account_id' => $account->id,
            'sort_order' => 0,
        ]);
        $participantCount = FestivalParticipant::query()->count();
        $pivotCount = DB::table('festival_entry_participant')->count();
        $archivedAt = $referenced->archived_at;

        $memberTypeMigration = require database_path('migrations/2026_08_28_175423_backfill_festival_participant_member_types.php');
        $memberTypeMigration->up();
        $lockMigration = require database_path('migrations/2026_08_28_175423_backfill_festival_registrant_type_locks.php');
        $lockMigration->up();

        $this->assertSame($participantCount, FestivalParticipant::query()->count());
        $this->assertSame($pivotCount, DB::table('festival_entry_participant')->count());
        $this->assertSame(FestivalTeamMemberType::Performer, $referenced->refresh()->member_type);
        $this->assertSame(FestivalTeamMemberType::Performer, $unreferenced->refresh()->member_type);
        $this->assertTrue($referenced->archived_at->equalTo($archivedAt));
        $this->assertNull($referenced->photo_path);
        $this->assertNull($unreferenced->photo_path);
        $this->assertTrue($firstEntry->participants()->whereKey($referenced->id)->exists());
        $this->assertSame('Legacy first application', $firstEntry->refresh()->entry_name);
        $this->assertTrue($portalUser->refresh()->registrant_type_locked_at->equalTo($firstCreatedAt));
    }

    public function test_registrant_detail_pages_reject_mismatched_accounts_editions_and_non_registrants(): void
    {
        [$account, $edition] = $this->festival();
        [$otherAccount, $otherEdition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $otherAccount->addOwner($owner);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $otherPortalUser = FestivalPortalUser::factory()->for($otherAccount)->create();
        $judge = FestivalPortalUser::factory()->for($account)->judge()->create();

        $this->actingAs($owner);

        foreach (['team', 'notifications'] as $page) {
            $this->get(route('dashboard.accounts.festivals.users.'.$page, [$account, $otherEdition, $portalUser]))
                ->assertNotFound();
            $this->get(route('dashboard.accounts.festivals.users.'.$page, [$account, $edition, $otherPortalUser]))
                ->assertNotFound();
            $this->get(route('dashboard.accounts.festivals.users.'.$page, [$account, $edition, $judge]))
                ->assertNotFound();
        }
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
            ->assertDontSee('value="guardian"', false)
            ->assertSee(__('app.festival_registrant_type_warning'))
            ->assertDontSee('За замовчуванням обрано «Учасник».');

        $createPage = $this->get($createUrl);
        $this->assertMatchesRegularExpression(
            '/<option value="adult_athlete" selected>/',
            $createPage->getContent(),
        );

        $this->from($createUrl)
            ->post(route('dashboard.accounts.festivals.users.store', [$account, $edition, 'registrant']), $this->registrantPayload([
                'registrant_type' => 'guardian',
            ]))
            ->assertRedirect($createUrl)
            ->assertSessionHasErrors('registrant_type');
    }

    public function test_staff_profile_forms_are_grouped_and_judges_can_save_instagram_links(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $registrant = FestivalPortalUser::factory()->for($account)->create();
        $existingJudge = FestivalPortalUser::factory()->for($account)->judge()->create([
            'instagram_url' => 'https://instagram.com/existing.judge',
        ]);

        $formUrls = [
            route('dashboard.accounts.festivals.users.create', [$account, $edition, FestivalPortalRole::Registrant->value]),
            route('dashboard.accounts.festivals.users.create', [$account, $edition, FestivalPortalRole::Judge->value]),
            route('dashboard.accounts.festivals.users.edit', [$account, $edition, $registrant]),
            route('dashboard.accounts.festivals.users.edit', [$account, $edition, $existingJudge]),
        ];

        foreach ($formUrls as $formUrl) {
            $this->actingAs($owner)
                ->get($formUrl)
                ->assertOk()
                ->assertSeeInOrder([
                    __('app.festival_profile_personal_details'),
                    __('app.festival_profile_contact_details'),
                    __('app.festival_profile_preferences_security'),
                ])
                ->assertSee('name="instagram_url"', false);
        }

        $judgeCreateUrl = route('dashboard.accounts.festivals.users.create', [$account, $edition, 'judge']);
        $this->actingAs($owner)
            ->from($judgeCreateUrl)
            ->post(route('dashboard.accounts.festivals.users.store', [$account, $edition, 'judge']), [
                'first_name' => 'Invalid',
                'last_name' => 'Instagram',
                'email' => 'invalid.instagram.judge@example.test',
                'phone' => null,
                'instagram_url' => 'ftp://instagram.com/invalid.judge',
                'locale' => 'en',
                'password' => 'secret1',
                'password_confirmation' => 'secret1',
                'is_active' => 1,
            ])
            ->assertRedirect($judgeCreateUrl)
            ->assertSessionHasErrors('instagram_url');

        $instagramUrl = 'https://www.instagram.com/directory.judge';
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.users.store', [$account, $edition, 'judge']), [
                'first_name' => 'Instagram',
                'last_name' => 'Judge',
                'email' => 'instagram.judge@example.test',
                'phone' => null,
                'instagram_url' => $instagramUrl,
                'locale' => 'en',
                'password' => 'secret1',
                'password_confirmation' => 'secret1',
                'is_active' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $judge = FestivalPortalUser::query()
            ->whereBelongsTo($account)
            ->forRole(FestivalPortalRole::Judge)
            ->where('email_normalized', 'instagram.judge@example.test')
            ->firstOrFail();
        $this->assertSame($instagramUrl, $judge->instagram_url);

        $editUrl = route('dashboard.accounts.festivals.users.edit', [$account, $edition, $judge]);
        $this->actingAs($owner)
            ->get($editUrl)
            ->assertOk()
            ->assertSee('value="'.$instagramUrl.'"', false);

        $updatedInstagramUrl = '@updated.judge';
        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.users.update', [$account, $edition, $judge]), [
                ...$this->judgePayload($judge, true),
                'instagram_url' => $updatedInstagramUrl,
            ])
            ->assertRedirect($editUrl)
            ->assertSessionHasNoErrors();

        $this->assertSame($updatedInstagramUrl, $judge->refresh()->instagram_url);
    }

    public function test_staff_cannot_change_a_locked_participant_profile_to_coach(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'registrant_type' => 'adult_athlete',
            'registrant_type_locked_at' => now(),
        ]);
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
        Storage::fake('local');
        [$account, $edition, $category] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.users.participants.create', [$account, $edition, $portalUser]))
            ->assertOk()
            ->assertSee('name="date_of_birth"', false)
            ->assertSee(route('dashboard.accounts.festivals.users.team', [$account, $edition, $portalUser]), false);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.users.participants.store', [$account, $edition, $portalUser]), [
                'first_name' => 'Roster',
                'last_name' => 'Member',
                'date_of_birth' => '2010-05-01',
                'notes' => 'Manual profile',
                'member_type' => FestivalTeamMemberType::Performer->value,
                'photo' => UploadedFile::fake()->image('performer.jpg', 300, 300),
            ])
            ->assertRedirect(route('dashboard.accounts.festivals.users.team', [$account, $edition, $portalUser]));
        $participant = $portalUser->participants()->where('first_name', 'Roster')->firstOrFail();
        $this->assertNotNull($participant->photo_path);
        Storage::disk('local')->assertExists($participant->photo_path);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.users.participants.photo', [$account, $edition, $portalUser, $participant]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.users.participants.update', [$account, $edition, $portalUser, $participant]), [
                'first_name' => 'Updated',
                'last_name' => 'Member',
                'date_of_birth' => '2010-05-01',
                'member_type' => FestivalTeamMemberType::Performer->value,
            ])
            ->assertRedirect(route('dashboard.accounts.festivals.users.team', [$account, $edition, $portalUser]));
        $this->assertSame('Updated', $participant->refresh()->first_name);

        $unusedParticipant = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'member_type' => FestivalTeamMemberType::Helper,
        ]);
        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.users.participants.destroy', [$account, $edition, $portalUser, $unusedParticipant]))
            ->assertRedirect(route('dashboard.accounts.festivals.users.team', [$account, $edition, $portalUser]));
        $this->assertNotNull($unusedParticipant->refresh()->archived_at);

        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => 'accepted',
        ]);
        $entry->participants()->attach($participant->id, ['account_id' => $account->id, 'sort_order' => 0]);

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.festivals.users.participants.edit', [$account, $edition, $portalUser, $participant]))
            ->put(route('dashboard.accounts.festivals.users.participants.update', [$account, $edition, $portalUser, $participant]), [
                'first_name' => $participant->first_name,
                'last_name' => $participant->last_name,
                'date_of_birth' => $participant->date_of_birth->toDateString(),
                'member_type' => FestivalTeamMemberType::Helper->value,
            ])
            ->assertSessionHasErrors('member_type');
        $this->assertSame(FestivalTeamMemberType::Performer, $participant->refresh()->member_type);

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
            'member_type' => FestivalTeamMemberType::Performer->value,
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
