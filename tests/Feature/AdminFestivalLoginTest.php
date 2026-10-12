<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\AccountStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationScope;
use App\Enums\SmsSendingMode;
use App\Enums\StudioPermission;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Customer;
use App\Models\CustomerAuthSetting;
use App\Models\Event;
use App\Models\FestivalEdition;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminFestivalLoginTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_enter_the_selected_participant_cabinet_without_creating_customer_or_event_records(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $customerCount = Customer::query()->count();
        $eventCount = Event::query()->count();
        $consumeUrl = $this->issueUrl($owner, $account, $edition, $participant);

        $this->get($consumeUrl)->assertRedirect(route('festival.portal.dashboard', $account->slug));
        $this->assertAuthenticatedAs($participant, 'festival');
        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertNotNull($participant->fresh()->last_login_at);
        $this->assertNull($participant->fresh()->remember_token);
        $this->assertSame($customerCount, Customer::query()->count());
        $this->assertSame($eventCount, Event::query()->count());

        $this->get(route('festival.portal.dashboard', $account->slug))
            ->assertOk()->assertSee($edition->title)
            ->assertViewHas('portalUser', fn (FestivalPortalUser $user): bool => $user->is($participant));
    }

    public function test_token_can_only_be_used_once(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $consumeUrl = $this->issueUrl($owner, $account, $edition, $participant);
        $this->get($consumeUrl)->assertRedirect();
        Auth::guard('festival')->logout();

        $this->get($consumeUrl)->assertNotFound();
        $this->assertGuest('festival');
    }

    public function test_signed_token_expires_after_five_minutes(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $consumeUrl = $this->issueUrl($owner, $account, $edition, $participant);
        $this->travel(6)->minutes();

        $this->get($consumeUrl)->assertForbidden();
        $this->assertGuest('festival');
    }

    public function test_tampering_is_rejected_without_consuming_the_valid_link(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $consumeUrl = $this->issueUrl($owner, $account, $edition, $participant);

        $this->get($consumeUrl.'&extra=changed')->assertForbidden();
        $this->assertGuest('festival');
        $this->get($consumeUrl)->assertRedirect();
    }

    public function test_signed_link_without_a_cached_token_is_rejected(): void
    {
        [, $account] = $this->festival();

        $this->get(URL::temporarySignedRoute('festival.admin-login.consume', now()->addMinutes(5), [
            'accountSlug' => $account->slug, 'token' => str_repeat('a', 64),
        ]))->assertNotFound();
        $this->assertGuest('festival');
    }

    public function test_registration_staff_cannot_issue_tokens_or_see_the_buttons(): void
    {
        [, $account, $edition, $participant] = $this->festival();
        $staff = User::factory()->create();
        $account->users()->attach($staff->id, [
            'role' => AccountRole::Manager->value,
            'permissions' => [StudioPermission::ManageFestivalRegistrations->value],
        ]);

        $this->actingAs($staff)->post($this->issuePath($account, $edition, $participant))->assertForbidden();
        $this->assertButtonsHidden($staff, $account, $edition, $participant);
    }

    public function test_platform_admin_cannot_issue_tokens_or_see_the_buttons(): void
    {
        [, $account, $edition, $participant] = $this->festival();
        $admin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);

        $this->actingAs($admin)->post($this->issuePath($account, $edition, $participant))->assertForbidden();
        $this->assertButtonsHidden($admin, $account, $edition, $participant);
    }

    public function test_unauthenticated_staff_cannot_issue_tokens(): void
    {
        [, $account, $edition, $participant] = $this->festival();

        $this->post($this->issuePath($account, $edition, $participant))->assertRedirect(route('login'));
        $this->assertGuest('festival');
    }

    public function test_participant_from_another_account_cannot_receive_a_token(): void
    {
        [$owner, $account, $edition] = $this->festival();
        $otherParticipant = FestivalPortalUser::factory()->create();

        $this->actingAs($owner)->post($this->issuePath($account, $edition, $otherParticipant))->assertNotFound();
        $this->assertGuest('festival');
    }

    public function test_edition_from_another_account_cannot_be_used_to_issue_a_token(): void
    {
        [$owner, $account, , $participant] = $this->festival();
        $otherSeries = FestivalSeries::factory()->create();
        $otherEdition = FestivalEdition::factory()->for($otherSeries->account)->for($otherSeries, 'series')->create();

        $this->actingAs($owner)->post($this->issuePath($account, $otherEdition, $participant))->assertNotFound();
        $this->assertGuest('festival');
    }

    public function test_cached_token_cannot_be_used_at_another_account(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $other = Account::factory()->create(['enable_festivals' => true]);
        $consumeUrl = $this->issueUrl($owner, $account, $edition, $participant);
        $token = basename((string) parse_url($consumeUrl, PHP_URL_PATH));

        $this->get(URL::temporarySignedRoute('festival.admin-login.consume', now()->addMinutes(5), [
            'accountSlug' => $other->slug, 'token' => $token,
        ]))->assertNotFound();
        $this->assertGuest('festival');
    }

    #[DataProvider('ineligibleProfiles')]
    public function test_only_active_registrants_can_receive_tokens(FestivalPortalRole $role, bool $active, string $tab): void
    {
        [$owner, $account, $edition] = $this->festival();
        $participant = FestivalPortalUser::factory()->for($account)->create(['role' => $role, 'is_active' => $active]);

        $this->actingAs($owner)->post($this->issuePath($account, $edition, $participant))->assertNotFound();
        $this->assertButtonsHidden($owner, $account, $edition, $participant, $tab);
    }

    public static function ineligibleProfiles(): array
    {
        return [
            'inactive registrant' => [FestivalPortalRole::Registrant, false, 'participants'],
            'judge' => [FestivalPortalRole::Judge, true, 'judges'],
            'guest' => [FestivalPortalRole::Guest, true, 'guests'],
        ];
    }

    public function test_profile_deactivated_after_issue_cannot_consume_the_token(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $consumeUrl = $this->issueUrl($owner, $account, $edition, $participant);
        $participant->update(['is_active' => false]);

        $this->get($consumeUrl)->assertNotFound();
        $this->assertGuest('festival');
    }

    public function test_deleted_profile_cannot_consume_the_token(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $consumeUrl = $this->issueUrl($owner, $account, $edition, $participant);
        $participant->delete();

        $this->get($consumeUrl)->assertNotFound();
        $this->assertGuest('festival');
    }

    public function test_disabled_festivals_block_issue_and_consumption(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $consumeUrl = $this->issueUrl($owner, $account, $edition, $participant);
        $account->update(['enable_festivals' => false]);

        $this->post($this->issuePath($account, $edition, $participant))->assertNotFound();
        $this->get($consumeUrl)->assertNotFound();
        $this->assertGuest('festival');
    }

    public function test_inactive_account_cannot_consume_the_token(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $consumeUrl = $this->issueUrl($owner, $account, $edition, $participant);
        $account->update(['status' => AccountStatus::Suspended]);

        $this->get($consumeUrl)->assertNotFound();
        $this->assertGuest('festival');
    }

    public function test_read_only_demo_cannot_issue_or_consume_tokens(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $consumeUrl = $this->issueUrl($owner, $account, $edition, $participant);
        $account->update(['mode' => 'demo_readonly']);

        $this->postJson($this->issuePath($account, $edition, $participant))->assertStatus(423);
        $this->get($consumeUrl)->assertNotFound();
        $this->assertGuest('festival');
    }

    public function test_expired_subscription_blocks_issue_and_consumption(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $consumeUrl = $this->issueUrl($owner, $account, $edition, $participant);
        AccountSubscription::factory()->for($account)->create(['status' => 'expired', 'ends_at' => now()->subDay()]);

        $this->postJson($this->issuePath($account, $edition, $participant))->assertStatus(423);
        $this->get($consumeUrl)->assertStatus(402);
        $this->assertGuest('festival');
    }

    public function test_signed_token_can_be_consumed_without_an_admin_session(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $consumeUrl = $this->issueUrl($owner, $account, $edition, $participant);
        Auth::guard('web')->logout();

        $this->get($consumeUrl)->assertRedirect(route('festival.portal.dashboard', $account->slug));
        $this->assertAuthenticatedAs($participant, 'festival');
        $this->assertGuest('web');
    }

    public function test_login_replaces_an_existing_festival_session_without_remembering_the_target(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $previous = FestivalPortalUser::factory()->judge()->create(['remember_token' => 'previous-remember-token']);
        $guard = Auth::guard('festival');
        $guard->login($previous);
        $this->withCookie($guard->getRecallerName(), $previous->id.'|previous-remember-token|'.$previous->password);
        $consumeUrl = $this->issueUrl($owner, $account, $edition, $participant);
        $oldSessionId = session()->getId();

        $this->get($consumeUrl)->assertRedirect(route('festival.portal.dashboard', $account->slug))
            ->assertCookieExpired($guard->getRecallerName());

        $this->assertAuthenticatedAs($participant, 'festival');
        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertNull($participant->fresh()->remember_token);
    }

    public function test_login_keeps_an_existing_customer_session_separate(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $customer = Customer::factory()->for($account)->create();
        Auth::guard('customer')->login($customer);

        $this->get($this->issueUrl($owner, $account, $edition, $participant))->assertRedirect();

        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertAuthenticatedAs($participant, 'festival');
        $this->assertAuthenticatedAs($owner, 'web');
    }

    public function test_incomplete_profile_still_requires_profile_completion(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $participant->update(['city' => null]);

        $this->get($this->issueUrl($owner, $account, $edition, $participant))->assertRedirect();
        $this->get(route('festival.portal.dashboard', $account->slug))
            ->assertRedirect(route('festival.portal.profile.edit', $account->slug));
        $this->get(route('festival.portal.profile.edit', $account->slug))->assertOk();
    }

    public function test_login_does_not_bypass_required_phone_verification(): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $participant->update(['phone_verified_at' => null]);
        CustomerAuthSetting::query()->create([
            'account_id' => $account->id, 'allow_otp' => true,
            'sms_sending_mode' => SmsSendingMode::OwnGateway, 'sms_provider' => 'turbosms',
        ]);
        IntegrationSetting::query()->create([
            'scope_type' => IntegrationScope::Platform, 'scope_id' => 0,
            'provider' => 'cloudflare_turnstile', 'category' => IntegrationCategory::Authentication,
            'is_enabled' => true, 'credentials' => ['site_key' => 'test-site', 'secret_key' => 'test-secret'],
        ]);
        IntegrationSetting::query()->create([
            'scope_type' => IntegrationScope::Account, 'scope_id' => $account->id, 'account_id' => $account->id,
            'provider' => 'turbosms', 'category' => IntegrationCategory::Messaging,
            'is_enabled' => true, 'credentials' => ['api_token' => 'test-token', 'sms_sender' => 'Ladna'],
        ]);

        $this->get($this->issueUrl($owner, $account, $edition, $participant))->assertRedirect();
        $this->get(route('festival.portal.dashboard', $account->slug))
            ->assertRedirect(route('festival.portal.profile.edit', $account->slug));
        $this->get(route('festival.portal.profile.edit', $account->slug))
            ->assertOk()->assertViewIs('festivals.portal.phone-verification');
        $this->assertNull($participant->fresh()->phone_verified_at);
    }

    #[DataProvider('loginLabels')]
    public function test_owner_sees_accessible_buttons_on_the_participant_list_and_edit_page(string $locale, string $label): void
    {
        [$owner, $account, $edition, $participant] = $this->festival();
        $actionUrl = url($this->issuePath($account, $edition, $participant));

        $this->actingAs($owner)->withSession(['locale' => $locale])->get(route('dashboard.accounts.festivals.users.index', [$account, $edition]))
            ->assertOk()->assertSee('action="'.$actionUrl.'"', false)
            ->assertSee('aria-label="'.$label.'"', false);
        $this->get(route('dashboard.accounts.festivals.users.edit', [$account, $edition, $participant]))
            ->assertOk()->assertSee('action="'.$actionUrl.'"', false)->assertSee($label);
    }

    public static function loginLabels(): array
    {
        return ['English' => ['en', 'Log in as participant'], 'Ukrainian' => ['uk', 'Увійти як учасник']];
    }

    /** @return array{User, Account, FestivalEdition, FestivalPortalUser} */
    private function festival(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $account->addOwner($owner);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->for($account)->for($series, 'series')->published()->create();
        $participant = FestivalPortalUser::factory()->for($account)->create();

        return [$owner, $account, $edition, $participant];
    }

    private function issuePath(Account $account, FestivalEdition $edition, FestivalPortalUser $participant): string
    {
        return '/app/dashboard/accounts/'.$account->id.'/festivals/'.$edition->id.'/users/'.$participant->id.'/admin-login';
    }

    private function issueUrl(User $owner, Account $account, FestivalEdition $edition, FestivalPortalUser $participant): string
    {
        $response = $this->actingAs($owner, 'web')->post($this->issuePath($account, $edition, $participant));
        $response->assertRedirect();
        $url = $response->headers->get('Location');
        $this->assertIsString($url);

        return $url;
    }

    private function assertButtonsHidden(User $user, Account $account, FestivalEdition $edition, FestivalPortalUser $participant, string $tab = 'participants'): void
    {
        $actionUrl = url($this->issuePath($account, $edition, $participant));
        $this->actingAs($user)->get(route('dashboard.accounts.festivals.users.index', [$account, $edition, 'tab' => $tab]))
            ->assertOk()->assertDontSee('action="'.$actionUrl.'"', false);
        $this->get(route('dashboard.accounts.festivals.users.edit', [$account, $edition, $participant]))
            ->assertOk()->assertDontSee('action="'.$actionUrl.'"', false);
    }
}
