<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\SubscriptionPlan;
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

    public function test_platform_admin_creates_studio_account_with_initial_owner(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $plan = SubscriptionPlan::factory()->create();

        $this->actingAs($platformAdmin)
            ->post(route('platform.accounts.store'), [
                'name' => 'Charmpole',
                'slug' => 'charmpole-test',
                'status' => 'active',
                'default_language' => 'uk',
                'default_currency' => 'UAH',
                'brand_color' => '#d80a7d',
                'timezone' => 'Europe/Kyiv',
                'subscription_plan_id' => $plan->id,
                'subscription_status' => 'active',
                'owner_name' => 'Настя',
                'owner_email' => 'nastya-owner@example.com',
                'owner_password' => 'password',
            ])
            ->assertRedirect();

        $account = Account::where('slug', 'charmpole-test')->firstOrFail();
        $owner = User::where('email', 'nastya-owner@example.com')->firstOrFail();

        $this->assertTrue($account->isAccessibleBy($owner));
        $this->assertTrue($account->memberships()
            ->whereBelongsTo($owner)
            ->where('role', 'owner')
            ->exists());
        $this->assertSame($plan->id, $account->subscription?->subscription_plan_id);
    }
}
