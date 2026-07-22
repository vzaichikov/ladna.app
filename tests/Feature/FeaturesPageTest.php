<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceTier;
use App\Models\SubscriptionPriceVersion;
use App\Models\User;
use App\Support\Onboarding\PublicOwnerOnboardingAvailability;
use App\Support\ReservedPublicSlugs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\TestCase;

class FeaturesPageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ukrainian_features_page_renders_navigation_sections_and_screenshots(): void
    {
        $response = $this->withSession(['locale' => 'en'])->get(route('features'));

        $response
            ->assertOk()
            ->assertSee('Усе, що потрібно, щоб студія працювала спокійно')
            ->assertSee('Зранку видно, що потребує уваги')
            ->assertSee('Можна підключити за потреби')
            ->assertSee('studio-dashboard.png', false)
            ->assertSee('weekly-schedule.png', false)
            ->assertSee('public-schedule.png', false)
            ->assertSee('active-passes.png', false)
            ->assertSee('trainer-permissions.png', false)
            ->assertSee('payments-period.png', false)
            ->assertSee('href="'.route('features.en').'"', false)
            ->assertSee('href="'.route('home').'#flow"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSessionHas('locale', 'uk');
    }

    public function test_english_features_page_controls_the_locale_and_language_switch(): void
    {
        $response = $this->withSession(['locale' => 'uk'])->get(route('features.en'));

        $response
            ->assertOk()
            ->assertSee('Everything your studio needs to run calmly')
            ->assertSee('Start the day knowing what needs attention')
            ->assertSee('Connect more when you need it')
            ->assertSee('href="'.route('features').'"', false)
            ->assertSee('href="'.route('home.en').'#flow"', false)
            ->assertSessionHas('locale', 'en');
    }

    public function test_landing_keeps_hero_copy_and_links_the_existing_cta_to_features(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Студія працює в ритмі занять, а не таблиць')
            ->assertSee('Подивитися можливості')
            ->assertSee('href="'.route('features').'"', false)
            ->assertSee('Що саме Ladna бере на себе')
            ->assertSee('Заявки з сайту не губляться')
            ->assertDontSee('Заявки з сайту чи Telegram не губляться');

        $this->get(route('home.en'))
            ->assertOk()
            ->assertSee('See what it covers')
            ->assertSee('href="'.route('features.en').'"', false)
            ->assertSee('Website leads do not get lost')
            ->assertDontSee('No lost website or Telegram leads');
    }

    public function test_features_page_repeats_registration_cta_when_public_onboarding_is_available(): void
    {
        $this->mock(PublicOwnerOnboardingAvailability::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isAvailable')->once()->andReturnTrue();
        });

        $content = $this->get(route('features'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($content, 'href="'.route('register').'"'));
    }

    public function test_authenticated_owner_can_review_features_and_use_cabinet_ctas(): void
    {
        $user = User::factory()->create();

        $content = $this->actingAs($user)
            ->get(route('features'))
            ->assertOk()
            ->assertSee(__('app.dashboard'))
            ->getContent();

        $this->assertSame(3, substr_count($content, 'href="'.route('dashboard.index').'"'));
    }

    public function test_published_pricing_adds_header_and_page_pricing_ctas(): void
    {
        config()->set('ladna.saas_billing_v2_enabled', true);
        $this->createPublishedPricing();

        $content = $this->get(route('features'))->assertOk()->getContent();

        $this->assertSame(3, substr_count($content, 'href="'.route('home').'#pricing"'));
    }

    public function test_features_is_reserved_from_public_studio_slugs(): void
    {
        $this->assertTrue(ReservedPublicSlugs::isReserved('features'));
    }

    private function createPublishedPricing(): SubscriptionPriceVersion
    {
        $plan = SubscriptionPlan::factory()->create([
            'is_active' => true,
            'public_signup_enabled' => true,
        ]);
        $priceVersion = SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->create();

        SubscriptionPriceTier::factory()
            ->for($priceVersion, 'priceVersion')
            ->create([
                'starts_at_location' => 1,
                'ends_at_location' => null,
            ]);

        return $priceVersion->publish(now()->subMinute());
    }
}
