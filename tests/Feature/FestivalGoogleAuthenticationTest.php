<?php

namespace Tests\Feature;

use App\Enums\FestivalPortalRole;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FestivalPortalUser;
use App\Models\IntegrationSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FestivalGoogleAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_verified_google_identity_self_registers_only_a_participant_profile(): void
    {
        $this->enableGoogle();
        $account = Account::factory()->create(['enable_festivals' => true]);
        $customerCount = Customer::query()->count();
        $state = $this->oauthState(route('festival.login.google', $account->slug));
        $this->fakeGoogle('google-participant-1', 'Google.Participant@Example.com', true, 'Google Participant');

        $this->get(route('festival.google.callback', ['state' => $state, 'code' => 'authorization-code']))
            ->assertRedirect(route('festival.portal.dashboard', $account->slug));

        $portalUser = FestivalPortalUser::query()->whereBelongsTo($account)->where('google_id', 'google-participant-1')->firstOrFail();
        $this->assertSame(FestivalPortalRole::Registrant, $portalUser->role);
        $this->assertSame('google.participant@example.com', $portalUser->email_normalized);
        $this->assertNotNull($portalUser->email_verified_at);
        $this->assertSame($customerCount, Customer::query()->count());
        $this->assertAuthenticatedAs($portalUser, 'festival');
    }

    public function test_judge_google_login_links_only_an_existing_active_judge_profile(): void
    {
        $this->enableGoogle();
        $account = Account::factory()->create(['enable_festivals' => true]);
        $judge = FestivalPortalUser::factory()->for($account)->judge()->create([
            'email' => 'judge.google@example.com',
            'email_normalized' => 'judge.google@example.com',
            'google_id' => null,
        ]);
        $state = $this->oauthState(route('festival.judge.login.google', $account->slug));
        $this->fakeGoogle('google-judge-1', 'judge.google@example.com', true, 'Judge Google');

        $this->get(route('festival.google.callback', ['state' => $state, 'code' => 'authorization-code']))
            ->assertRedirect(route('festival.portal.judge.dashboard', $account->slug));

        $this->assertSame('google-judge-1', $judge->refresh()->google_id);
        $this->assertAuthenticatedAs($judge, 'festival');
    }

    public function test_unknown_judge_unverified_email_and_wrong_role_are_rejected_without_profile_creation(): void
    {
        $this->enableGoogle();
        $account = Account::factory()->create(['enable_festivals' => true]);
        FestivalPortalUser::factory()->for($account)->create([
            'email' => 'registrant-only@example.com',
            'email_normalized' => 'registrant-only@example.com',
        ]);
        Http::fakeSequence('oauth2.googleapis.com/token')
            ->push(['access_token' => 'festival-google-access-token'])
            ->push(['access_token' => 'festival-google-access-token'])
            ->push(['access_token' => 'festival-google-access-token']);
        Http::fakeSequence('openidconnect.googleapis.com/v1/userinfo')
            ->push(['sub' => 'unknown-google-judge', 'email' => 'unknown-judge@example.com', 'email_verified' => true, 'name' => 'Unknown Judge'])
            ->push(['sub' => 'wrong-role-google', 'email' => 'registrant-only@example.com', 'email_verified' => true, 'name' => 'Wrong Role'])
            ->push(['sub' => 'unverified-google', 'email' => 'unverified@example.com', 'email_verified' => false, 'name' => 'Unverified User']);

        $unknownState = $this->oauthState(route('festival.judge.login.google', $account->slug));
        $unknownResponse = $this->get(route('festival.google.callback', ['state' => $unknownState, 'code' => 'authorization-code']));
        $this->assertSame(route('home'), $unknownResponse->headers->get('Location'), 'unknown judge callback');
        $unknownResponse->assertSessionHasErrors('google');

        $wrongRoleState = $this->oauthState(route('festival.judge.login.google', $account->slug));
        $wrongRoleResponse = $this->get(route('festival.google.callback', ['state' => $wrongRoleState, 'code' => 'authorization-code']));
        $this->assertSame(route('home'), $wrongRoleResponse->headers->get('Location'), 'wrong role callback');
        $wrongRoleResponse->assertSessionHasErrors('google');

        $unverifiedState = $this->oauthState(route('festival.login.google', $account->slug));
        $unverifiedResponse = $this->get(route('festival.google.callback', ['state' => $unverifiedState, 'code' => 'authorization-code']));
        $this->assertSame(route('home'), $unverifiedResponse->headers->get('Location'), 'unverified participant callback');
        $unverifiedResponse->assertSessionHasErrors('google');

        $this->assertDatabaseMissing('festival_portal_users', ['account_id' => $account->id, 'google_id' => 'unknown-google-judge']);
        $this->assertDatabaseMissing('festival_portal_users', ['account_id' => $account->id, 'google_id' => 'wrong-role-google']);
        $this->assertDatabaseMissing('festival_portal_users', ['account_id' => $account->id, 'google_id' => 'unverified-google']);
    }

    public function test_google_identity_collision_is_rejected_transactionally(): void
    {
        $this->enableGoogle();
        $account = Account::factory()->create(['enable_festivals' => true]);
        $googleOwner = FestivalPortalUser::factory()->for($account)->create(['google_id' => 'collision-subject']);
        $emailOwner = FestivalPortalUser::factory()->for($account)->create([
            'email' => 'collision-email@example.com',
            'email_normalized' => 'collision-email@example.com',
        ]);
        $state = $this->oauthState(route('festival.login.google', $account->slug));
        $this->fakeGoogle('collision-subject', 'collision-email@example.com', true, 'Collision User');

        $this->get(route('festival.google.callback', ['state' => $state, 'code' => 'authorization-code']))
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors('google');

        $this->assertSame('collision-subject', $googleOwner->refresh()->google_id);
        $this->assertNull($emailOwner->refresh()->google_id);
    }

    public function test_google_state_is_one_time_account_and_role_bound_and_expires_after_ten_minutes(): void
    {
        $this->enableGoogle();
        $account = Account::factory()->create(['enable_festivals' => true]);
        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $state = 'expired-festival-google-state';
        $this->withSession([
            'festival_google_oauth.'.$state => [
                'account_slug' => $account->slug,
                'role' => FestivalPortalRole::Registrant->value,
                'created_at' => now()->subMinutes(11)->timestamp,
            ],
        ])->get(route('festival.google.callback', ['state' => $state, 'code' => 'authorization-code']))
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors('google');

        $validState = $this->oauthState(route('festival.login.google', $otherAccount->slug));
        $this->fakeGoogle('one-time-subject', 'one-time@example.com', true, 'One Time');
        $callback = route('festival.google.callback', ['state' => $validState, 'code' => 'authorization-code']);
        $this->get($callback)->assertRedirect(route('festival.portal.dashboard', $otherAccount->slug));
        auth('festival')->logout();
        $this->get($callback)->assertRedirect(route('home'))->assertSessionHasErrors('google');

        $this->assertDatabaseMissing('festival_portal_users', [
            'account_id' => $account->id,
            'email_normalized' => 'one-time@example.com',
        ]);
        $this->assertDatabaseHas('festival_portal_users', [
            'account_id' => $otherAccount->id,
            'role' => FestivalPortalRole::Registrant->value,
            'email_normalized' => 'one-time@example.com',
        ]);
    }

    private function enableGoogle(): void
    {
        IntegrationSetting::create([
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
            'provider' => 'google_oauth',
            'category' => IntegrationCategory::Authentication->value,
            'is_enabled' => true,
            'credentials' => [
                'client_id' => 'festival-google-client',
                'client_secret' => 'festival-google-secret',
            ],
        ]);
    }

    private function oauthState(string $redirectRoute): string
    {
        $location = $this->get($redirectRoute)->assertRedirect()->headers->get('Location');
        parse_str(parse_url((string) $location, PHP_URL_QUERY) ?: '', $query);

        return (string) ($query['state'] ?? '');
    }

    private function fakeGoogle(string $subject, string $email, bool $verified, string $name): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'festival-google-access-token']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => $subject,
                'email' => $email,
                'email_verified' => $verified,
                'name' => $name,
            ]),
        ]);
    }
}
