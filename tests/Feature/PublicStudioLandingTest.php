<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPlanType;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\Location;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublicStudioLandingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_studio_landing_shows_active_locations_links_branding_and_maps(): void
    {
        $mapUrl = 'https://www.google.com/maps?output=embed&q=Kyiv';
        $account = Account::factory()->create([
            'name' => 'Landing Studio',
            'slug' => 'landing-studio',
            'default_language' => 'en',
            'logo_path' => 'brand/charmpole-icon.svg',
            'brand_color' => '#d80a7d',
            'studio_slogan' => 'Move with confidence every day.',
            'support_instagram_url' => 'https://instagram.example/landing-studio',
            'support_telegram_url' => 'tg://resolve?domain=landingstudio',
            'support_phone_url' => 'tel:+380501234567',
            'support_secondary_phone_url' => 'tel:+380671234567',
        ]);
        $activeLocation = Location::factory()->for($account)->create([
            'name' => 'Main location',
            'slug' => 'main',
            'address' => 'Kyiv, Main street 1',
            'google_maps_embed_url' => $mapUrl,
        ]);
        $inactiveLocation = Location::factory()->for($account)->create([
            'name' => 'Closed location',
            'slug' => 'closed',
            'is_active' => false,
        ]);

        $this->get(route('public.studio', $account->slug))
            ->assertOk()
            ->assertSee($account->name)
            ->assertSee('Move with confidence every day.')
            ->assertDontSee('Choose a location, check class passes and prices, or open the schedule to book.')
            ->assertSee($activeLocation->name)
            ->assertSee($activeLocation->address)
            ->assertSee(route('public.price', [$account->slug, $activeLocation->slug]), false)
            ->assertSee(route('public.schedule', [$account->slug, $activeLocation->slug]), false)
            ->assertSee(__('app.public_contact_title', ['studio' => $account->name]))
            ->assertSee('https://instagram.example/landing-studio', false)
            ->assertSee('tg://resolve?domain=landingstudio', false)
            ->assertSee('tel:+380501234567', false)
            ->assertSee('tel:+380671234567', false)
            ->assertSee('assets/social/instagram.svg', false)
            ->assertSee('assets/social/telegram.svg', false)
            ->assertSee('assets/social/phone.svg', false)
            ->assertSee($mapUrl)
            ->assertSee('<iframe', false)
            ->assertSee('brand/charmpole-icon.svg', false)
            ->assertSee('brand/ladna-mark.svg', false)
            ->assertSee(__('app.powered_by_ladna'))
            ->assertDontSee($inactiveLocation->name)
            ->assertDontSee(route('public.price', [$account->slug, $inactiveLocation->slug]), false);
    }

    public function test_public_studio_landing_shows_selector_for_multiple_active_locations(): void
    {
        $account = Account::factory()->create([
            'slug' => 'multi-location-studio',
            'default_language' => 'en',
        ]);
        Location::factory()->for($account)->create([
            'name' => 'North location',
            'slug' => 'north',
        ]);
        Location::factory()->for($account)->create([
            'name' => 'South location',
            'slug' => 'south',
        ]);

        $this->get(route('public.studio', $account->slug))
            ->assertOk()
            ->assertSee(__('app.studio_landing_locations_title'))
            ->assertSee('href="#location-north"', false)
            ->assertSee('href="#location-south"', false);
    }

    public function test_suspended_account_studio_landing_is_not_public(): void
    {
        $account = Account::factory()->create([
            'slug' => 'suspended-landing-studio',
            'status' => 'suspended',
        ]);
        Location::factory()->for($account)->create(['slug' => 'main']);

        $this->get(route('public.studio', $account->slug))->assertNotFound();
    }

    public function test_expired_subscription_blocks_public_studio_landing(): void
    {
        $account = Account::factory()->create(['slug' => 'expired-landing-studio']);
        $plan = SubscriptionPlan::factory()->create(['plan_type' => SubscriptionPlanType::Standard]);
        $account->subscription()->create([
            'subscription_plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'started_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);
        Location::factory()->for($account)->create(['slug' => 'main']);

        $this->get(route('public.studio', $account->slug))
            ->assertStatus(402)
            ->assertSee(__('app.subscription_expired_public_title'));
    }
}
