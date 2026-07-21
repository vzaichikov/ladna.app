<?php

namespace Tests\Feature;

use App\Enums\SubscriptionBillingMode;
use App\Enums\SubscriptionStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BillingV2EnrollmentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_enrolls_only_the_selected_account_without_creating_a_payment(): void
    {
        config()->set('ladna.saas_billing_v2_enabled', true);
        $admin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);
        $legacyPlan = SubscriptionPlan::factory()->create(['name' => 'Legacy free']);
        $target = Account::factory()->create();
        $untouched = Account::factory()->create();
        AccountSubscription::factory()->for($target)->for($legacyPlan, 'plan')->create([
            'billing_mode' => SubscriptionBillingMode::Legacy,
            'status' => SubscriptionStatus::Active,
            'ends_at' => null,
        ]);
        $untouchedSubscription = AccountSubscription::factory()->for($untouched)->for($legacyPlan, 'plan')->create([
            'billing_mode' => SubscriptionBillingMode::Legacy,
            'status' => SubscriptionStatus::Active,
            'ends_at' => null,
        ]);
        $billingPlan = SubscriptionPlan::factory()->create(['name' => 'Ladna']);
        $priceVersion = SubscriptionPriceVersion::factory()
            ->for($billingPlan, 'plan')
            ->published()
            ->create(['version' => 1]);

        $this->actingAs($admin)
            ->post(route('platform.accounts.billing.enroll', $target), [
                'subscription_price_version_id' => $priceVersion->id,
            ])
            ->assertRedirect(route('platform.accounts.show', $target));

        $this->assertSame(SubscriptionBillingMode::LocationV2, $target->subscription->refresh()->billing_mode);
        $this->assertSame(SubscriptionStatus::Trialing, $target->subscription->status);
        $trialEndsAt = $target->subscription->trial_ends_at;

        $this->actingAs($admin)
            ->put(route('platform.accounts.update', $target), [
                'name' => $target->name.' updated',
                'slug' => $target->slug,
                'status' => $target->status->value,
                'default_language' => $target->default_language,
                'country_code' => $target->country_code,
                'default_currency' => $target->default_currency,
                'timezone' => $target->timezone,
                'subscription_plan_id' => $legacyPlan->id,
                'subscription_status' => SubscriptionStatus::Cancelled->value,
                'subscription_ends_at' => now()->toDateString(),
            ])
            ->assertRedirect(route('platform.accounts.show', $target));

        $this->assertSame($billingPlan->id, $target->subscription->refresh()->subscription_plan_id);
        $this->assertSame(SubscriptionStatus::Trialing, $target->subscription->status);
        $this->assertTrue($target->subscription->trial_ends_at->equalTo($trialEndsAt));
        $this->assertSame(SubscriptionBillingMode::Legacy, $untouchedSubscription->refresh()->billing_mode);
        $this->assertSame(SubscriptionStatus::Active, $untouchedSubscription->status);
        $this->assertDatabaseCount('account_subscription_payments', 0);
    }

    public function test_protected_demo_and_disabled_flag_cannot_enroll(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Ladna']);
        $priceVersion = SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->published()
            ->create(['version' => 1]);
        $demo = Account::factory()->demoReadonly()->create();

        config()->set('ladna.saas_billing_v2_enabled', true);
        $this->actingAs($admin)
            ->post(route('platform.accounts.billing.enroll', $demo), [
                'subscription_price_version_id' => $priceVersion->id,
            ])
            ->assertSessionHasErrors();

        $this->assertFalse($demo->subscription()->where('billing_mode', SubscriptionBillingMode::LocationV2->value)->exists());

        $account = Account::factory()->create();
        config()->set('ladna.saas_billing_v2_enabled', false);
        $this->actingAs($admin)
            ->post(route('platform.accounts.billing.enroll', $account), [
                'subscription_price_version_id' => $priceVersion->id,
            ])
            ->assertSessionHasErrors('billing');

        $this->assertFalse($account->subscription()->exists());
    }
}
