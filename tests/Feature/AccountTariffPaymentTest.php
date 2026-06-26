<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Enums\SubscriptionStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountTariffPaymentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_view_current_tariff_and_payments_mock_page(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $plan = SubscriptionPlan::factory()->create([
            'name' => 'Studio Pro',
            'price_cents' => 250000,
            'currency' => 'UAH',
            'billing_interval' => 'monthly',
        ]);

        $account->subscription()->create([
            'subscription_plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active->value,
            'started_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertOk()
            ->assertSee('Тариф та платежі')
            ->assertSee('Studio Pro')
            ->assertSee('2,500.00 UAH')
            ->assertSee(__('app.active'));
    }

    public function test_non_owner_staff_cannot_view_tariff_and_payments(): void
    {
        $manager = User::factory()->create();
        $account = Account::factory()->create();
        $account->users()->attach($manager->id, [
            'role' => AccountRole::Manager->value,
            'permissions' => [StudioPermission::ManageStudioSettings->value],
        ]);

        $this->actingAs($manager)
            ->get(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertForbidden();
    }

    public function test_platform_admin_does_not_get_studio_owner_tariff_page(): void
    {
        $platformAdmin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);
        $account = Account::factory()->create();

        $this->actingAs($platformAdmin)
            ->get(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertForbidden();
    }
}
