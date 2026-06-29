<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use DatabaseTransactions;

    public function test_authenticated_users_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest('web');
    }

    public function test_platform_admin_sees_sidebar_logout_button(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->get(route('platform.index'))
            ->assertOk()
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('data-sidebar-logout', false)
            ->assertSee(__('app.logout'))
            ->assertSeeInOrder(['id="app-locale"', 'data-sidebar-logout'], false);
    }

    public function test_every_studio_role_sees_sidebar_logout_button(): void
    {
        foreach (AccountRole::cases() as $role) {
            $user = User::factory()->create();
            $account = Account::factory()->create();

            $account->users()->attach($user->id, [
                'role' => $role->value,
                'permissions' => null,
            ]);

            $this->actingAs($user)
                ->get(route('dashboard.accounts.show', $account))
                ->assertOk()
                ->assertSee('action="'.route('logout').'"', false)
                ->assertSee('data-sidebar-logout', false)
                ->assertSee(__('app.logout'))
                ->assertSeeInOrder(['id="app-locale"', 'data-sidebar-logout'], false);
        }
    }
}
