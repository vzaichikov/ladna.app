<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceTier;
use App\Models\SubscriptionPriceVersion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublicPricingLandingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_pricing_fails_closed_when_billing_v2_is_disabled(): void
    {
        $this->createPublishedPricing();
        config()->set('ladna.saas_billing_v2_enabled', false);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('id="pricing"', false)
            ->assertDontSee('Database-priced Ladna');
    }

    public function test_public_pricing_fails_closed_without_an_effective_published_price(): void
    {
        config()->set('ladna.saas_billing_v2_enabled', true);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('id="pricing"', false);
    }

    public function test_both_landing_locales_render_whitelisted_database_pricing(): void
    {
        config()->set('ladna.saas_billing_v2_enabled', true);
        $this->createPublishedPricing();
        $privatePlan = SubscriptionPlan::factory()->create([
            'name' => 'Hidden founders pricing',
            'public_signup_enabled' => false,
            'is_active' => true,
        ]);
        $privatePrice = SubscriptionPriceVersion::factory()->for($privatePlan, 'plan')->create(['version' => 1]);
        SubscriptionPriceTier::factory()->for($privatePrice, 'priceVersion')->create([
            'starts_at_location' => 1,
            'ends_at_location' => null,
            'unit_price_cents' => 42_000,
        ]);
        $privatePrice->publish(now());

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('id="pricing"', false)
            ->assertSee('public-pricing-number-input', false)
            ->assertSee('Database-priced Ladna')
            ->assertSee('1 234 ₴')
            ->assertSee('567 ₴')
            ->assertSee('17 днів')
            ->assertSee('Економія 13%')
            ->assertDontSee('Hidden founders pricing')
            ->assertDontSee('420 ₴')
            ->assertSee('href="'.route('demo.login', [], false).'"', false)
            ->assertDontSee('unit_price_cents')
            ->assertDontSee('subscription_price_version_id');

        $this->get(route('home.en'))
            ->assertOk()
            ->assertSee('id="pricing"', false)
            ->assertSee('Database-priced Ladna')
            ->assertSee('1 234 ₴')
            ->assertSee('567 ₴')
            ->assertSee('17-day free trial')
            ->assertSee('Save 13%')
            ->assertDontSee('Hidden founders pricing')
            ->assertDontSee('420 ₴')
            ->assertSee('No card required for the trial')
            ->assertSee('No setup fee')
            ->assertSee('href="'.route('demo.login', [], false).'"', false)
            ->assertDontSee('unit_price_cents')
            ->assertDontSee('subscription_price_version_id');
    }

    private function createPublishedPricing(): SubscriptionPriceVersion
    {
        $plan = SubscriptionPlan::factory()->create([
            'name' => 'Database-priced Ladna',
            'is_active' => true,
            'public_signup_enabled' => true,
        ]);
        $priceVersion = SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->create([
                'version' => 1,
                'currency' => 'UAH',
                'trial_days' => 17,
                'annual_discount_percent' => 13,
            ]);

        SubscriptionPriceTier::factory()
            ->for($priceVersion, 'priceVersion')
            ->create([
                'starts_at_location' => 1,
                'ends_at_location' => 1,
                'unit_price_cents' => 123_400,
            ]);
        SubscriptionPriceTier::factory()
            ->for($priceVersion, 'priceVersion')
            ->create([
                'starts_at_location' => 2,
                'ends_at_location' => null,
                'unit_price_cents' => 56_700,
            ]);

        return $priceVersion->publish(now()->subMinute());
    }
}
