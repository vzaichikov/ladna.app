<?php

namespace Tests\Feature;

use App\Enums\SubscriptionBillingInterval;
use App\Models\Account;
use App\Models\SubscriptionPlan;
use App\Support\SaasBilling\SubscriptionPricingCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublishFoundersLadnaPricingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_dry_runs_then_idempotently_publishes_the_private_founders_tariff(): void
    {
        config()->set('ladna.saas_billing_v2_enabled', false);
        Account::factory()->count(2)->create();
        $accountCount = Account::query()->count();

        $this->artisan('billing:publish-founders-pricing')
            ->expectsOutputToContain('Dry run only')
            ->assertSuccessful();
        $this->assertDatabaseMissing('subscription_plans', ['slug' => 'ladna-founders']);

        $this->artisan('billing:publish-founders-pricing', ['--execute' => true])
            ->expectsOutputToContain('No account was enrolled or modified')
            ->assertSuccessful();

        $plan = SubscriptionPlan::query()
            ->where('slug', 'ladna-founders')
            ->with('priceVersions.tiers')
            ->firstOrFail();
        $priceVersion = $plan->priceVersions->firstOrFail();
        $pricing = app(SubscriptionPricingCalculator::class);

        $this->assertSame('Ladna Founders', $plan->name);
        $this->assertFalse($plan->public_signup_enabled);
        $this->assertTrue($plan->requires_recurring_payment);
        $this->assertTrue($plan->is_active);
        $this->assertSame(30, $priceVersion->trial_days);
        $this->assertSame(15, $priceVersion->annual_discount_percent);
        $this->assertSame(65_000, $pricing->calculate($priceVersion, 1, SubscriptionBillingInterval::Monthly)->finalAmountCents);
        $this->assertSame(120_000, $pricing->calculate($priceVersion, 2, SubscriptionBillingInterval::Monthly)->finalAmountCents);
        $this->assertSame(175_000, $pricing->calculate($priceVersion, 3, SubscriptionBillingInterval::Monthly)->finalAmountCents);
        $this->assertSame(663_000, $pricing->calculate($priceVersion, 1, SubscriptionBillingInterval::Annual)->finalAmountCents);
        $this->assertSame(1_224_000, $pricing->calculate($priceVersion, 2, SubscriptionBillingInterval::Annual)->finalAmountCents);
        $this->assertSame(1_785_000, $pricing->calculate($priceVersion, 3, SubscriptionBillingInterval::Annual)->finalAmountCents);
        $this->assertSame($accountCount, Account::query()->count());
        $this->assertDatabaseCount('account_subscriptions', 0);
        $this->assertDatabaseCount('account_subscription_payments', 0);

        $this->artisan('billing:publish-founders-pricing', ['--execute' => true])
            ->expectsOutputToContain('already exists and was kept')
            ->assertSuccessful();
        $this->assertSame(1, $plan->priceVersions()->count());
    }

    public function test_command_refuses_to_overwrite_a_conflicting_founders_slug(): void
    {
        SubscriptionPlan::factory()->create([
            'name' => 'Different product',
            'slug' => 'ladna-founders',
            'public_signup_enabled' => true,
        ]);

        $this->artisan('billing:publish-founders-pricing', ['--execute' => true])
            ->expectsOutputToContain('already used by a different or public product')
            ->assertFailed();

        $this->assertDatabaseCount('subscription_price_versions', 0);
    }
}
