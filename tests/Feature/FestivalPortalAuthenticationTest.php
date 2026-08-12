<?php

namespace Tests\Feature;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FestivalPortalUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
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

    public function test_public_pages_expose_separate_participant_and_judge_entry_points(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);

        $this->get(route('festival.login', $account->slug))
            ->assertOk()
            ->assertSee(__('app.festival_participant_login'))
            ->assertSee(route('festival.judge.login', $account->slug), false)
            ->assertDontSee(route('api-docs.show'), false)
            ->assertDontSee(route('changelog.en'), false);

        $this->get(route('festival.judge.login', $account->slug))
            ->assertOk()
            ->assertSee(__('app.festival_judge_login'))
            ->assertSee(route('festival.login', $account->slug), false)
            ->assertDontSee(route('api-docs.show'), false)
            ->assertDontSee(route('changelog.en'), false);
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
