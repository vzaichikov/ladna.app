<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\MobileSession;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EventFestivalStaffAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_single_studio_staff_lands_on_events_from_home_dashboard_and_account(): void
    {
        $account = Account::factory()->create();
        $staff = $this->staffUser($account);
        $eventsUrl = route('dashboard.accounts.events.index', $account);

        $this->actingAs($staff)
            ->get(route('home'))
            ->assertRedirect($eventsUrl);

        $this->actingAs($staff)
            ->get(route('dashboard.index'))
            ->assertRedirect($eventsUrl);

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.show', $account))
            ->assertRedirect($eventsUrl);
    }

    public function test_multi_studio_chooser_uses_role_aware_destinations(): void
    {
        $staffAccount = Account::factory()->create(['name' => 'Entrance Studio']);
        $managerAccount = Account::factory()->create(['name' => 'Manager Studio']);
        $user = $this->staffUser($staffAccount);
        $managerAccount->memberships()->create([
            'user_id' => $user->id,
            'role' => AccountRole::Manager->value,
            'permissions' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee(route('dashboard.accounts.events.index', $staffAccount), false)
            ->assertSee(route('dashboard.accounts.show', $managerAccount), false);
    }

    public function test_staff_studio_navigation_contains_only_events_and_festivals(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $staff = $this->staffUser($account);

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.events.index', $account))
            ->assertOk()
            ->assertSee(route('dashboard.accounts.events.index', $account), false)
            ->assertSee(route('dashboard.accounts.festivals.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.customers.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.trainers.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.reports.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.studio-settings.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.event-festival-staff.index', $account), false);
    }

    public function test_central_login_authenticates_staff_and_dashboard_selects_the_staff_landing(): void
    {
        $account = Account::factory()->create();
        $staff = $this->staffUser($account, ['password' => 'correct-password']);

        $this->post(route('login', absolute: false), [
            'email' => $staff->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard.index', absolute: false));

        $this->assertAuthenticatedAs($staff, 'web');

        $this->get(route('dashboard.index'))
            ->assertRedirect(route('dashboard.accounts.events.index', $account));
    }

    public function test_mobile_staff_login_rejects_event_festival_staff(): void
    {
        $account = Account::factory()->create();
        $staff = $this->staffUser($account);

        $this->postJson('/api/v1/mobile/auth/staff/login', [
            'email' => $staff->email,
            'password' => 'password',
            'platform' => 'android',
        ])->assertForbidden();

        $this->assertFalse(MobileSession::query()->whereBelongsTo($staff)->exists());
    }

    public function test_mobile_staff_login_omits_staff_memberships_when_another_role_is_available(): void
    {
        $staffAccount = Account::factory()->create(['slug' => 'event-festival-mobile']);
        $ownerAccount = Account::factory()->create(['slug' => 'owner-mobile']);
        $user = $this->staffUser($staffAccount);
        $ownerAccount->addOwner($user);

        $this->postJson('/api/v1/mobile/auth/staff/login', [
            'email' => $user->email,
            'password' => 'password',
            'platform' => 'android',
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.accounts')
            ->assertJsonPath('data.accounts.0.account.slug', 'owner-mobile')
            ->assertJsonMissing(['slug' => 'event-festival-mobile']);

        $this->assertFalse(MobileSession::query()->where('account_id', $staffAccount->id)->exists());
        $this->assertTrue(MobileSession::query()->where('account_id', $ownerAccount->id)->exists());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function staffUser(Account $account, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $account->memberships()->create([
            'user_id' => $user->id,
            'role' => AccountRole::EventFestivalStaff->value,
            'permissions' => [],
        ]);

        return $user;
    }
}
