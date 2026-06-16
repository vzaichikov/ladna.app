<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_can_access_platform_and_see_all_accounts(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        Account::factory()->create(['name' => 'Studio A']);
        Account::factory()->create(['name' => 'Studio B']);

        $this->actingAs($platformAdmin)
            ->get(route('platform.accounts.index'))
            ->assertOk()
            ->assertSee('Studio A')
            ->assertSee('Studio B');
    }

    public function test_normal_owner_cannot_access_platform(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('platform.index'))
            ->assertForbidden();
    }

    public function test_platform_admin_can_suspend_account_without_deleting_tenant_data(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create(['status' => 'active']);

        $this->actingAs($platformAdmin)
            ->put(route('platform.accounts.update', $account), [
                'name' => $account->name,
                'slug' => $account->slug,
                'status' => 'suspended',
                'default_language' => 'uk',
                'default_currency' => 'UAH',
                'timezone' => 'Europe/Kyiv',
                'subscription_plan_id' => null,
                'subscription_status' => 'suspended',
            ])
            ->assertRedirect(route('platform.accounts.show', $account));

        $account->refresh();
        $this->assertSame('suspended', $account->status->value);
        $this->assertModelExists($account);
    }
}
