<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class HomeRedirectTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_is_redirected_from_home_to_platform_panel(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->get(route('home'))
            ->assertRedirect(route('platform.index'))
            ->assertSessionHas('locale', 'uk');
    }

    public function test_studio_owner_is_redirected_from_home_to_their_studio_panel(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('home'))
            ->assertRedirect(route('dashboard.accounts.show', $account))
            ->assertSessionHas('locale', 'uk');
    }

    public function test_trainer_is_redirected_from_home_to_their_studio_panel(): void
    {
        $trainerUser = User::factory()->create();
        $account = Account::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($trainerUser, 'user')
            ->create(['role' => AccountRole::Trainer->value, 'permissions' => null]);

        $this->actingAs($trainerUser)
            ->get(route('home.en'))
            ->assertRedirect(route('dashboard.accounts.show', $account))
            ->assertSessionHas('locale', 'en');
    }
}
