<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPriceStatus;
use App\Enums\SystemRole;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PlatformSubscriptionPricingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_enters_normal_uah_and_publishes_a_previewed_draft(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);
        $plan = SubscriptionPlan::factory()->create([
            'name' => 'Ladna',
            'price_cents' => 490_000,
        ]);

        $this->actingAs($admin)
            ->get(route('platform.subscription-plans.price-versions.create', $plan))
            ->assertOk()
            ->assertSee(__('app.remove'))
            ->assertDontSee('app.remove');

        $response = $this->actingAs($admin)
            ->post(route('platform.subscription-plans.price-versions.store', $plan), [
                'currency' => 'UAH',
                'trial_days' => 30,
                'annual_discount_percent' => 10,
                'tiers' => [
                    ['starts_at_location' => 1, 'ends_at_location' => 1, 'unit_price_uah' => '900.00'],
                    ['starts_at_location' => 2, 'ends_at_location' => '', 'unit_price_uah' => '800.00'],
                ],
            ]);

        $priceVersion = SubscriptionPriceVersion::query()->whereBelongsTo($plan, 'plan')->firstOrFail();
        $response->assertRedirect(route('platform.subscription-plans.price-versions.preview', [$plan, $priceVersion]));
        $this->assertSame(SubscriptionPriceStatus::Draft, $priceVersion->status);
        $this->assertSame([90_000, 80_000], $priceVersion->tiers()->pluck('unit_price_cents')->all());

        $this->actingAs($admin)
            ->get(route('platform.subscription-plans.price-versions.preview', [$plan, $priceVersion]))
            ->assertOk()
            ->assertSee('900 ₴')
            ->assertSee('1 700 ₴')
            ->assertSee('2 500 ₴');

        $this->actingAs($admin)
            ->post(route('platform.subscription-plans.price-versions.publish', [$plan, $priceVersion]))
            ->assertRedirect(route('platform.subscription-plans.price-versions.preview', [$plan, $priceVersion]));

        $this->assertSame(SubscriptionPriceStatus::Published, $priceVersion->refresh()->status);
        $this->actingAs($admin)
            ->get(route('platform.subscription-plans.index'))
            ->assertOk()
            ->assertSee(__('app.from_price_per_location', ['price' => '900 ₴']))
            ->assertDontSee('4 900 ₴');

        $this->actingAs($admin)
            ->delete(route('platform.subscription-plans.price-versions.destroy', [$plan, $priceVersion]))
            ->assertSessionHasErrors('price_version');
    }

    public function test_invalid_non_contiguous_tiers_are_rejected(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);
        $plan = SubscriptionPlan::factory()->create();

        $this->actingAs($admin)
            ->from(route('platform.subscription-plans.price-versions.create', $plan))
            ->post(route('platform.subscription-plans.price-versions.store', $plan), [
                'currency' => 'UAH',
                'trial_days' => 30,
                'annual_discount_percent' => 10,
                'tiers' => [
                    ['starts_at_location' => 1, 'ends_at_location' => 1, 'unit_price_uah' => '900'],
                    ['starts_at_location' => 3, 'ends_at_location' => '', 'unit_price_uah' => '700'],
                ],
            ])
            ->assertRedirect(route('platform.subscription-plans.price-versions.create', $plan))
            ->assertSessionHasErrors('tiers');

        $this->assertDatabaseCount('subscription_price_versions', 0);
    }

    public function test_legacy_product_form_converts_uah_to_cents_and_used_products_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);

        $this->actingAs($admin)
            ->post(route('platform.subscription-plans.store'), [
                'name' => 'Ladna Product',
                'slug' => 'ladna-product',
                'description' => null,
                'price_uah' => '900.50',
                'currency' => 'UAH',
                'billing_interval' => 'monthly',
                'plan_type' => 'standard',
                'access_days' => 30,
                'public_signup_enabled' => 0,
                'requires_recurring_payment' => 0,
                'renewal_lead_days' => 2,
                'is_active' => 1,
                'sort_order' => 0,
            ])
            ->assertRedirect(route('platform.subscription-plans.index'));

        $plan = SubscriptionPlan::query()->where('slug', 'ladna-product')->firstOrFail();
        $this->assertSame(90_050, $plan->price_cents);
        SubscriptionPriceVersion::factory()->for($plan, 'plan')->create(['version' => 1]);

        $this->actingAs($admin)
            ->delete(route('platform.subscription-plans.destroy', $plan))
            ->assertSessionHasErrors('plan');

        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id]);
    }
}
