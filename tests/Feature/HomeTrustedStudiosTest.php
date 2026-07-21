<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPlanType;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\Location;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class HomeTrustedStudiosTest extends TestCase
{
    use DatabaseTransactions;

    public function test_landing_shows_public_studio_trust_links(): void
    {
        $trustedStudio = Account::factory()->create([
            'name' => 'Charmpole Test Studio',
            'slug' => 'charmpole-trusted-test',
            'logo_path' => 'brand/charmpole-icon.svg',
            'studio_slogan' => 'Dance with confidence',
        ]);
        Location::factory()->for($trustedStudio)->create(['is_active' => true]);

        $hiddenStudio = Account::factory()->create([
            'name' => 'Hidden Expired Studio',
            'slug' => 'hidden-expired-studio',
        ]);
        Location::factory()->for($hiddenStudio)->create(['is_active' => true]);
        $plan = SubscriptionPlan::factory()->create(['plan_type' => SubscriptionPlanType::Standard]);
        $hiddenStudio->subscription()->create([
            'subscription_plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'started_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);

        $internalStudio = Account::factory()->internal()->create([
            'name' => 'Internal Billing Verification Studio',
            'slug' => 'internal-billing-verification-studio',
        ]);
        Location::factory()->for($internalStudio)->create(['is_active' => true]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Нам довіряють')
            ->assertSee('Charmpole Test Studio')
            ->assertSee('Dance with confidence')
            ->assertSee('brand/charmpole-icon.svg', false)
            ->assertSee(route('public.studio', $trustedStudio->slug), false)
            ->assertDontSee('Hidden Expired Studio')
            ->assertDontSee(route('public.studio', $hiddenStudio->slug), false)
            ->assertDontSee('Internal Billing Verification Studio')
            ->assertDontSee(route('public.studio', $internalStudio->slug), false);
    }
}
