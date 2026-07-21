<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPriceStatus;
use App\Models\Account;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublishInitialLadnaPricingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_is_dry_run_by_default_and_publishes_exact_initial_price_without_enrolling_accounts(): void
    {
        config()->set('ladna.saas_billing_v2_enabled', true);
        $plan = SubscriptionPlan::factory()->create([
            'slug' => 'ladna-studio',
            'public_signup_enabled' => false,
            'requires_recurring_payment' => false,
            'is_active' => false,
        ]);
        Account::factory()->count(2)->create();
        $accountCount = Account::query()->count();

        $this->artisan('billing:publish-initial-pricing')
            ->expectsOutputToContain('Dry run only')
            ->assertSuccessful();
        $this->assertDatabaseCount('subscription_price_versions', 0);

        $this->artisan('billing:publish-initial-pricing', ['--execute' => true])
            ->expectsOutputToContain('No account was enrolled or modified')
            ->assertSuccessful();

        $priceVersion = $plan->priceVersions()->with('tiers')->firstOrFail();
        $this->assertSame(SubscriptionPriceStatus::Published, $priceVersion->status);
        $this->assertSame(30, $priceVersion->trial_days);
        $this->assertSame(10, $priceVersion->annual_discount_percent);
        $this->assertTrue($plan->refresh()->public_signup_enabled);
        $this->assertTrue($plan->requires_recurring_payment);
        $this->assertTrue($plan->is_active);
        $this->assertSame([
            [1, 1, 90_000],
            [2, null, 80_000],
        ], $priceVersion->tiers->map(fn ($tier): array => [
            $tier->starts_at_location,
            $tier->ends_at_location,
            $tier->unit_price_cents,
        ])->all());
        $this->assertSame($accountCount, Account::query()->count());
        $this->assertDatabaseCount('account_subscriptions', 0);
        $this->assertDatabaseCount('account_subscription_payments', 0);

        $this->artisan('billing:publish-initial-pricing', ['--execute' => true])
            ->expectsOutputToContain('existing published price was kept')
            ->assertSuccessful();
        $this->assertSame(1, $plan->priceVersions()->count());
    }

    public function test_execute_is_blocked_without_mutation_while_billing_v2_is_disabled(): void
    {
        config()->set('ladna.saas_billing_v2_enabled', false);
        $plan = SubscriptionPlan::factory()->create([
            'slug' => 'ladna-studio',
            'public_signup_enabled' => false,
            'requires_recurring_payment' => false,
            'is_active' => false,
        ]);

        $this->artisan('billing:publish-initial-pricing')
            ->expectsOutputToContain('Dry run only')
            ->assertSuccessful();
        $this->artisan('billing:publish-initial-pricing', ['--execute' => true])
            ->expectsOutputToContain('Enable Ladna billing v2')
            ->assertFailed();

        $this->assertDatabaseCount('subscription_price_versions', 0);
        $this->assertFalse($plan->refresh()->public_signup_enabled);
        $this->assertFalse($plan->requires_recurring_payment);
        $this->assertFalse($plan->is_active);
    }
}
