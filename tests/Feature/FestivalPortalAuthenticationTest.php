<?php

namespace Tests\Feature;

use App\Enums\FestivalPortalRole;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\TelegramBotInstallation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FestivalPortalAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_participant_email_login_self_registers_an_incomplete_profile_without_creating_a_customer(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $customerCount = Customer::query()->count();

        $this->post(route('festival.login.email', $account->slug), [
            'email' => ' New.Participant@Example.COM ',
            'password' => 'secret1',
        ])->assertRedirect(route('festival.portal.dashboard', $account->slug));

        $portalUser = FestivalPortalUser::query()
            ->whereBelongsTo($account)
            ->where('email_normalized', 'new.participant@example.com')
            ->firstOrFail();

        $this->assertSame(FestivalPortalRole::Registrant, $portalUser->role);
        $this->assertTrue($portalUser->is_active);
        $this->assertTrue(Hash::check('secret1', (string) $portalUser->password));
        $this->assertNotSame('secret1', $portalUser->password);
        $this->assertSame($customerCount, Customer::query()->count());
        $this->assertAuthenticatedAs($portalUser, 'festival');

        $this->get(route('festival.portal.dashboard', $account->slug))
            ->assertRedirect(route('festival.portal.profile.edit', $account->slug));
    }

    public function test_participant_and_judge_email_logins_use_existing_role_specific_profiles(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $participant = FestivalPortalUser::factory()->for($account)->create([
            'email' => 'participant@example.com',
            'email_normalized' => 'participant@example.com',
            'password' => 'participant-secret',
        ]);
        $judge = FestivalPortalUser::factory()->for($account)->judge()->create([
            'email' => 'judge@example.com',
            'email_normalized' => 'judge@example.com',
            'password' => 'judge-secret',
        ]);

        $this->post(route('festival.login.email', $account->slug), [
            'email' => 'PARTICIPANT@example.com',
            'password' => 'participant-secret',
        ])->assertRedirect(route('festival.portal.dashboard', $account->slug));
        $this->assertAuthenticatedAs($participant, 'festival');

        $this->post(route('festival.portal.logout', $account->slug))
            ->assertRedirect(route('festival.login', $account->slug));

        $this->post(route('festival.judge.login.email', $account->slug), [
            'email' => 'judge@example.com',
            'password' => 'judge-secret',
        ])->assertRedirect(route('festival.portal.judge.dashboard', $account->slug));
        $this->assertAuthenticatedAs($judge, 'festival');

        $this->post(route('festival.portal.logout', $account->slug))
            ->assertRedirect(route('festival.judge.login', $account->slug));
    }

    public function test_public_guest_cabinet_and_login_routes_are_removed_while_internal_guest_records_remain_supported(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();

        $this->assertFalse(Route::has('festival.guest.login'));
        $this->assertFalse(Route::has('festival.guest.login.email'));
        $this->assertFalse(Route::has('festival.portal.guest.dashboard'));
        $this->assertSame(FestivalPortalRole::Guest, $guest->role);
        $this->assertDatabaseHas('festival_portal_users', ['id' => $guest->id, 'role' => FestivalPortalRole::Guest->value]);

        $this->get('/'.$account->slug.'/festival/guest/login')->assertNotFound();
    }

    public function test_unknown_judge_and_passwordless_existing_profile_cannot_be_claimed(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        FestivalPortalUser::factory()->for($account)->create([
            'email' => 'passwordless@example.com',
            'email_normalized' => 'passwordless@example.com',
            'password' => null,
        ]);

        $this->from(route('festival.judge.login', $account->slug))
            ->post(route('festival.judge.login.email', $account->slug), [
                'email' => 'unknown-judge@example.com',
                'password' => 'secret1',
            ])
            ->assertRedirect(route('festival.judge.login', $account->slug))
            ->assertSessionHasErrors('email');

        $this->from(route('festival.login', $account->slug))
            ->post(route('festival.login.email', $account->slug), [
                'email' => 'passwordless@example.com',
                'password' => 'secret1',
            ])
            ->assertRedirect(route('festival.login', $account->slug))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('festival_portal_users', [
            'account_id' => $account->id,
            'email_normalized' => 'unknown-judge@example.com',
        ]);
        $this->assertGuest('festival');
    }

    public function test_participant_login_renders_field_errors_for_first_invalid_control_scrolling(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $loginUrl = route('festival.login', $account->slug);

        $this->from($loginUrl)
            ->followingRedirects()
            ->post(route('festival.login.email', $account->slug), [
                'email' => '',
                'password' => '',
            ])
            ->assertOk()
            ->assertSee('data-server-validation-scroll', false)
            ->assertSeeInOrder([
                'name="email"',
                'data-field-error="email"',
                'name="password"',
                'data-field-error="password"',
            ], false);

        $this->get(route('festival.judge.login', $account->slug))
            ->assertOk()
            ->assertDontSee('data-server-validation-scroll', false);
    }

    public function test_same_email_remains_account_scoped_and_cross_account_sessions_are_rejected(): void
    {
        $first = Account::factory()->create(['enable_festivals' => true]);
        $second = Account::factory()->create(['enable_festivals' => true]);

        foreach ([$first, $second] as $account) {
            $this->post(route('festival.login.email', $account->slug), [
                'email' => 'same@example.com',
                'password' => 'secret1',
            ])->assertRedirect(route('festival.portal.dashboard', $account->slug));
            auth('festival')->logout();
        }

        $this->assertSame(2, FestivalPortalUser::query()->where('email_normalized', 'same@example.com')->count());
        $firstUser = $first->festivalPortalUsers()->firstOrFail();

        $this->actingAs($firstUser, 'festival')
            ->get(route('festival.portal.dashboard', $second->slug))
            ->assertNotFound();
    }

    public function test_inactive_profiles_are_rejected_on_every_authenticated_request(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $portalUser = FestivalPortalUser::factory()->for($account)->inactive()->create();

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.dashboard', $account->slug))
            ->assertRedirect(route('festival.login', $account->slug))
            ->assertSessionHasErrors('email');

        $this->assertGuest('festival');
    }

    public function test_participant_profile_uses_inline_errors_required_markers_and_current_registration_types(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'uk']);
        $portalUser = FestivalPortalUser::factory()->for($account)->create(['locale' => 'uk']);
        $profileUrl = route('festival.portal.profile.edit', $account->slug);

        $form = $this->actingAs($portalUser, 'festival')->get($profileUrl);

        $form->assertOk()
            ->assertSee('novalidate', false)
            ->assertSee('data-server-validation-scroll', false)
            ->assertSee(__('app.festival_participant_profile'))
            ->assertSeeInOrder([
                'value="adult_athlete"',
                'value="coach"',
            ], false)
            ->assertDontSee('value="guardian"', false)
            ->assertDontSee(__('app.festival_registrant_guardian'));
        $this->assertSame(9, substr_count((string) $form->getContent(), 'data-required-marker'));

        $invalidForm = $this->from($profileUrl)
            ->followingRedirects()
            ->put(route('festival.portal.profile.update', $account->slug), [
                'registrant_type' => 'guardian',
                'first_name' => '',
                'last_name' => '',
                'email' => '',
                'phone' => '',
                'city' => '',
                'studio_name' => '',
                'locale' => '',
            ]);

        $invalidForm
            ->assertOk()
            ->assertDontSee('<ul class="list-disc pl-5">', false)
            ->assertSeeInOrder([
                'name="registrant_type"',
                'data-field-error="registrant_type"',
                'name="first_name"',
                'data-field-error="first_name"',
                'name="last_name"',
                'data-field-error="last_name"',
                'name="email"',
                'data-field-error="email"',
                'name="phone"',
                'data-field-error="phone"',
                'name="city"',
                'data-field-error="city"',
                'name="studio_name"',
                'data-field-error="studio_name"',
                'name="locale"',
                'data-field-error="locale"',
            ], false);
    }

    public function test_participant_profile_places_duplicate_identity_errors_under_visible_fields(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'email' => 'current@example.test',
            'email_normalized' => 'current@example.test',
            'phone' => '+380501112233',
            'phone_normalized' => '+380501112233',
        ]);
        FestivalPortalUser::factory()->for($account)->create([
            'email' => 'taken@example.test',
            'email_normalized' => 'taken@example.test',
            'phone' => '+380502223344',
            'phone_normalized' => '+380502223344',
        ]);

        $this->actingAs($portalUser, 'festival')
            ->from(route('festival.portal.profile.edit', $account->slug))
            ->followingRedirects()
            ->put(route('festival.portal.profile.update', $account->slug), [
                'registrant_type' => 'adult_athlete',
                'first_name' => $portalUser->first_name,
                'last_name' => $portalUser->last_name,
                'email' => 'TAKEN@example.test',
                'phone' => '+380502223344',
                'city' => $portalUser->city,
                'studio_name' => $portalUser->studio_name,
                'locale' => 'en',
            ])
            ->assertOk()
            ->assertSee('data-field-error="email"', false)
            ->assertSee('data-field-error="phone"', false)
            ->assertDontSee('data-field-error="email_normalized"', false)
            ->assertDontSee('data-field-error="phone_normalized"', false);
    }

    public function test_participant_profile_completion_remains_available_when_studio_otp_is_unavailable(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'phone' => null,
            'phone_normalized' => null,
            'phone_verified_at' => null,
            'telegram_contact' => null,
        ]);

        $this->get(route('festival.login', $account->slug))
            ->assertOk()
            ->assertSee(__('app.festival_profile_step_label', ['current' => 1, 'total' => 2]))
            ->assertDontSee('role="tablist"', false);

        $profileForm = $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.profile.edit', $account->slug));

        $profileForm
            ->assertOk()
            ->assertDontSee('data-festival-profile-phone-step', false)
            ->assertSee('data-festival-profile-phone', false)
            ->assertSee(__('app.festival_instagram_contact_help'))
            ->assertSee(__('app.festival_instagram_contact_placeholder'))
            ->assertSee('name="telegram_contact"', false)
            ->assertSee(__('app.festival_telegram_contact_help'))
            ->assertSee(__('app.festival_telegram_contact_placeholder'))
            ->assertSee(__('app.festival_profile_step_label', ['current' => 2, 'total' => 2]))
            ->assertDontSee('data-profile-phone-verification', false);

        $this->post(route('festival.portal.profile.phone.send', $account->slug), [
            'phone' => '0501112233',
        ])->assertNotFound();

        $this->put(route('festival.portal.profile.update', $account->slug), [
            'registrant_type' => 'coach',
            'first_name' => $portalUser->first_name,
            'last_name' => $portalUser->last_name,
            'email' => $portalUser->email,
            'phone' => '0501112233',
            'city' => $portalUser->city,
            'studio_name' => $portalUser->studio_name,
            'locale' => 'en',
        ])->assertRedirect(route('festival.portal.dashboard', $account->slug));

        $this->assertSame('+380501112233', $portalUser->refresh()->phone_normalized);
        $this->assertNull($portalUser->phone_verified_at);
        $this->get(route('festival.portal.dashboard', $account->slug))->assertOk();
    }

    public function test_participant_social_contacts_accept_handles_and_profile_urls(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'phone' => '+380501112244',
            'phone_normalized' => '+380501112244',
        ]);
        $profileUrl = route('festival.portal.profile.update', $account->slug);
        $payload = [
            'registrant_type' => 'coach',
            'first_name' => $portalUser->first_name,
            'last_name' => $portalUser->last_name,
            'email' => $portalUser->email,
            'phone' => $portalUser->phone,
            'city' => $portalUser->city,
            'studio_name' => $portalUser->studio_name,
            'locale' => 'en',
        ];

        foreach (['@festival.dancer', 'https://www.instagram.com/festival.dancer/'] as $instagramContact) {
            $this->actingAs($portalUser, 'festival')
                ->put($profileUrl, $payload + ['instagram_url' => $instagramContact])
                ->assertSessionHasNoErrors();

            $this->assertSame($instagramContact, $portalUser->refresh()->instagram_url);
        }

        foreach (['123456789', '@festival_user', 'festival_user', 't.me/festival_user', 'https://t.me/festival_user'] as $telegramContact) {
            $this->actingAs($portalUser, 'festival')
                ->put($profileUrl, $payload + ['telegram_contact' => $telegramContact])
                ->assertSessionHasNoErrors();

            $this->assertSame($telegramContact, $portalUser->refresh()->telegram_contact);
        }

        $this->put($profileUrl, $payload + ['instagram_url' => 'https://example.com/festival.dancer'])
            ->assertSessionHasErrors('instagram_url');
        $this->put($profileUrl, $payload + ['telegram_contact' => 'https://example.com/festival_user'])
            ->assertSessionHasErrors('telegram_contact');
    }

    public function test_legacy_guardian_profile_can_be_edited_without_changing_its_stored_type(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'registrant_type' => 'guardian',
            'email' => 'legacy.guardian@example.test',
            'email_normalized' => 'legacy.guardian@example.test',
            'phone' => '+380501112233',
            'phone_normalized' => '+380501112233',
        ]);

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.profile.edit', $account->slug))
            ->assertOk()
            ->assertSee('value="guardian"', false);

        $this->put(route('festival.portal.profile.update', $account->slug), [
            'registrant_type' => 'guardian',
            'first_name' => $portalUser->first_name,
            'last_name' => $portalUser->last_name,
            'email' => $portalUser->email,
            'phone' => $portalUser->phone,
            'city' => 'Updated city',
            'studio_name' => $portalUser->studio_name,
            'locale' => 'en',
        ])->assertRedirect(route('festival.portal.dashboard', $account->slug))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('app.festival_profile_saved'));

        $this->assertSame('guardian', $portalUser->refresh()->registrant_type->value);
        $this->assertSame('Updated city', $portalUser->city);
    }

    public function test_role_specific_login_pages_do_not_cross_link_and_show_distinct_mascots(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);

        $this->get(route('festival.login', $account->slug))
            ->assertOk()
            ->assertSee(__('app.festival_participant_login'))
            ->assertSee(__('app.help'))
            ->assertSee(route('help.show', 'festival-participants'), false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener"', false)
            ->assertSee('data-festival-participant-help-link', false)
            ->assertSee('data-lucide="circle-help"', false)
            ->assertSee('data-public-studio-header', false)
            ->assertSee('data-public-studio-footer-identity', false)
            ->assertSee('data-public-studio-footer-name', false)
            ->assertSee(asset('assets/brand/mascot/ladna-mascot-festival-champion-cutout.png'), false)
            ->assertDontSee(asset('assets/brand/mascot/ladna-mascot-festival-judge-cutout.png'), false)
            ->assertDontSee(route('festival.judge.login', $account->slug), false)
            ->assertDontSee(route('api-docs.show'), false)
            ->assertDontSee(route('changelog.en'), false);

        $this->get(route('festival.judge.login', $account->slug))
            ->assertOk()
            ->assertSee(__('app.festival_judge_login'))
            ->assertDontSee(route('help.show', 'festival-participants'), false)
            ->assertDontSee('data-festival-participant-help-link', false)
            ->assertSee('data-public-studio-header', false)
            ->assertSee('data-public-studio-footer-identity', false)
            ->assertSee('data-public-studio-footer-name', false)
            ->assertSee(asset('assets/brand/mascot/ladna-mascot-festival-judge-cutout.png'), false)
            ->assertDontSee(asset('assets/brand/mascot/ladna-mascot-festival-champion-cutout.png'), false)
            ->assertDontSee(route('festival.login', $account->slug), false)
            ->assertDontSee(route('api-docs.show'), false)
            ->assertDontSee(route('changelog.en'), false);
    }

    public function test_participant_profile_creates_and_reuses_its_own_roster_member(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'uk']);
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'registrant_type' => 'coach',
            'patronymic' => null,
            'phone' => '+380501234567',
            'phone_normalized' => '+380501234567',
        ]);
        $payload = [
            'registrant_type' => 'adult_athlete',
            'first_name' => 'Марія',
            'last_name' => 'Танцівниця',
            'patronymic' => '',
            'stage_name' => 'Mara Air',
            'date_of_birth' => '2000-05-10',
            'email' => $portalUser->email,
            'phone' => $portalUser->phone,
            'city' => $portalUser->city,
            'studio_name' => $portalUser->studio_name,
            'locale' => 'uk',
        ];

        $this->actingAs($portalUser, 'festival')
            ->put(route('festival.portal.profile.update', $account->slug), $payload)
            ->assertRedirect(route('festival.portal.dashboard', $account->slug))
            ->assertSessionHasNoErrors();

        $portalUser->refresh();
        $this->assertSame('Mara Air', $portalUser->stage_name);
        $this->assertCount(1, $portalUser->participants);
        $this->assertTrue($portalUser->profileParticipant->is_profile_owner);
        $this->assertSame('2000-05-10', $portalUser->profileParticipant->date_of_birth->toDateString());

        $this->put(route('festival.portal.participants.update', [$account->slug, $portalUser->profileParticipant]), [
            'first_name' => 'Forged',
            'last_name' => 'Roster edit',
            'date_of_birth' => '1999-01-01',
        ])->assertStatus(409);
        $this->delete(route('festival.portal.participants.destroy', [$account->slug, $portalUser->profileParticipant]))->assertStatus(409);

        $payload['first_name'] = 'Марічка';
        $this->put(route('festival.portal.profile.update', $account->slug), $payload)->assertRedirect();

        $this->assertSame(1, $portalUser->participants()->count());
        $this->assertSame('Марічка', $portalUser->profileParticipant()->firstOrFail()->first_name);
    }

    public function test_adult_participant_profile_cannot_change_to_coach(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'registrant_type' => 'adult_athlete',
        ]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'is_profile_owner' => true,
        ]);
        $profileUrl = route('festival.portal.profile.edit', $account->slug);

        $this->actingAs($portalUser, 'festival')
            ->get($profileUrl)
            ->assertOk()
            ->assertSee('value="adult_athlete"', false)
            ->assertDontSee('value="coach"', false);

        $this->from($profileUrl)
            ->put(route('festival.portal.profile.update', $account->slug), [
                'registrant_type' => 'coach',
                'first_name' => $portalUser->first_name,
                'last_name' => $portalUser->last_name,
                'email' => $portalUser->email,
                'phone' => $portalUser->phone,
                'city' => $portalUser->city,
                'studio_name' => $portalUser->studio_name,
                'locale' => 'en',
            ])
            ->assertRedirect($profileUrl)
            ->assertSessionHasErrors('registrant_type');

        $this->assertSame('adult_athlete', $portalUser->refresh()->registrant_type->value);
        $this->assertTrue($portalUser->profileParticipant->is($participant));
    }

    public function test_judge_profile_rejects_registrant_identity_fields(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $judge = FestivalPortalUser::factory()->for($account)->judge()->create();
        $profileUrl = route('festival.portal.judge.profile.edit', $account->slug);

        $this->actingAs($judge, 'festival')
            ->get($profileUrl)
            ->assertOk()
            ->assertSee(__('app.festival_profile'))
            ->assertDontSee(__('app.festival_participant_profile'));

        $this->from($profileUrl)
            ->put(route('festival.portal.judge.profile.update', $account->slug), [
                'registrant_type' => 'adult_athlete',
                'date_of_birth' => '2000-01-01',
                'city' => 'Kyiv',
                'studio_name' => 'Registrant Studio',
                'instagram_url' => 'https://example.test/registrant',
                'first_name' => $judge->first_name,
                'last_name' => $judge->last_name,
                'email' => $judge->email,
                'phone' => $judge->phone,
                'locale' => 'en',
            ])
            ->assertRedirect($profileUrl)
            ->assertSessionHasErrors(['registrant_type', 'date_of_birth', 'city', 'studio_name', 'instagram_url']);

        $this->assertNull($judge->refresh()->registrant_type);
        $this->assertSame(0, $judge->participants()->count());
    }

    public function test_all_primary_participant_portal_pages_use_the_dashboard_width(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();

        foreach (['festival.portal.dashboard', 'festival.portal.entries.index', 'festival.portal.participants.index', 'festival.portal.profile.edit'] as $route) {
            $this->actingAs($portalUser, 'festival')->get(route($route, $account->slug))
                ->assertOk()
                ->assertSee('max-w-6xl', false);
        }
    }

    public function test_participant_cabinet_uses_button_navigation_and_links_active_series_telegram_bots_near_help(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $activeSeries = FestivalSeries::factory()->for($account)->create(['name' => 'Active Festival Series']);
        $disabledSeries = FestivalSeries::factory()->for($account)->create(['name' => 'Disabled Festival Series']);
        TelegramBotInstallation::factory()->for($account)->create([
            'scope_type' => 'festival_series',
            'scope_id' => $activeSeries->id,
            'profile' => TelegramBotProfile::Festival->value,
            'bot_username' => 'active_festival_bot',
            'is_enabled' => true,
        ]);
        TelegramBotInstallation::factory()->for($account)->create([
            'scope_type' => 'festival_series',
            'scope_id' => $disabledSeries->id,
            'profile' => TelegramBotProfile::Festival->value,
            'bot_username' => 'disabled_festival_bot',
            'is_enabled' => false,
        ]);

        $response = $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.dashboard', $account->slug))
            ->assertOk()
            ->assertSee('festival-portal-nav-link', false)
            ->assertSee('data-festival-portal-nav-link', false)
            ->assertSee('data-festival-telegram-bot-link', false)
            ->assertSee('href="https://t.me/active_festival_bot"', false)
            ->assertDontSee('https://t.me/disabled_festival_bot', false)
            ->assertSeeInOrder([
                'href="https://t.me/active_festival_bot"',
                'data-festival-participant-help-link',
            ], false);

        $this->assertSame(1, substr_count($response->getContent(), 'data-festival-telegram-bot-link'));
    }

    public function test_participant_roster_is_presented_as_the_portal_users_private_team(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.participants.index', $account->slug))
            ->assertOk()
            ->assertSee(__('app.festival_portal_team'))
            ->assertSee(__('app.festival_portal_my_team'))
            ->assertSee(__('app.festival_portal_team_copy'))
            ->assertSee(__('app.festival_portal_add_to_team'))
            ->assertDontSee(__('app.festival_participants_copy'));
    }

    public function test_magic_link_runtime_and_routes_are_removed(): void
    {
        $this->assertFalse(Schema::hasTable('festival_login_tokens'));
        $this->assertTrue(Schema::hasTable('festival_otp_challenges'));
        $this->assertFileDoesNotExist(app_path('Actions/Festivals/FestivalMagicLink.php'));
        $this->assertFileDoesNotExist(app_path('Models/FestivalLoginToken.php'));
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('festival.login.request'));
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('festival.login.consume'));
        $this->assertFileExists(app_path('Mail/FestivalPortalMail.php'));
    }

    public function test_email_login_is_rate_limited_per_account_and_identity(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('festival.judge.login.email', $account->slug), [
                'email' => 'rate-limited-judge@example.com',
                'password' => 'secret1',
            ])->assertSessionHasErrors('email');
        }

        $this->post(route('festival.judge.login.email', $account->slug), [
            'email' => 'rate-limited-judge@example.com',
            'password' => 'secret1',
        ])->assertTooManyRequests();
    }
}
