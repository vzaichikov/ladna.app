<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPlanType;
use App\Enums\SubscriptionStatus;
use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FestivalEdition;
use App\Models\FestivalSeries;
use App\Models\Location;
use App\Models\SubscriptionPlan;
use App\Models\TelegramBotInstallation;
use App\Support\Telegram\CustomerTelegramLinkResolver;
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
            'support_phone_url' => '+380501234567',
            'support_secondary_phone_url' => '+380671234567',
            'studio_rules_html' => '<p>Landing rules</p>',
            'public_offer_html' => '<p>Landing offer</p>',
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
            ->assertSee(route('customer.studio.login', $account->slug), false)
            ->assertSee(route('public.price', [$account->slug, $activeLocation->slug]), false)
            ->assertSee(route('public.schedule', [$account->slug, $activeLocation->slug]), false)
            ->assertDontSee('data-customer-page-topbar', false)
            ->assertSee('data-customer-locale-switcher', false)
            ->assertSee('data-customer-footer-locale-switcher', false)
            ->assertDontSee('data-customer-dashboard-link', false)
            ->assertSeeInOrder([
                route('customer.studio.login', $account->slug),
                route('public.studio-rules', $account->slug),
                route('public.price', [$account->slug, $activeLocation->slug]),
                route('public.schedule', [$account->slug, $activeLocation->slug]),
            ], false)
            ->assertSee(__('app.public_contact_title', ['studio' => $account->name]))
            ->assertSee('https://instagram.example/landing-studio', false)
            ->assertSee('tg://resolve?domain=landingstudio', false)
            ->assertSee('tel://+380501234567', false)
            ->assertSee('tel://+380671234567', false)
            ->assertSee('assets/social/instagram.svg', false)
            ->assertSee('assets/social/telegram.svg', false)
            ->assertSee('assets/social/phone.svg', false)
            ->assertSee($mapUrl)
            ->assertSee('<iframe', false)
            ->assertSee('brand/charmpole-icon.svg', false)
            ->assertSee('brand/ladna-mark.svg', false)
            ->assertSee(__('app.powered_by_ladna'))
            ->assertSee('data-public-studio-footer-identity', false)
            ->assertSee('data-customer-footer-legal-links', false)
            ->assertSee('data-public-rules-footer-link', false)
            ->assertSee('data-public-offer-footer-link', false)
            ->assertSeeInOrder([
                __('app.public_contact_title', ['studio' => $account->name]),
                'data-customer-footer-legal-links',
                'data-public-rules-footer-link',
                'data-public-offer-footer-link',
                __('app.powered_by_ladna'),
            ], false)
            ->assertDontSee($inactiveLocation->name)
            ->assertDontSee(route('public.price', [$account->slug, $inactiveLocation->slug]), false);
    }

    public function test_public_studio_landing_links_same_account_customer_identity_to_dashboard_only(): void
    {
        $account = Account::factory()->create([
            'slug' => 'customer-landing-studio',
            'default_language' => 'en',
        ]);
        Location::factory()->for($account)->create(['slug' => 'main']);
        $customer = Customer::factory()->for($account)->create(['name' => 'Landing Customer']);

        $this->actingAs($customer, 'customer')
            ->get(route('public.studio', $account->slug))
            ->assertOk()
            ->assertSee(__('app.public_schedule_logged_in_as', ['name' => $customer->name]))
            ->assertSee('data-customer-dashboard-link', false)
            ->assertSee('href="'.route('customer.dashboard', $account->slug).'"', false)
            ->assertDontSee('href="'.route('customer.studio.login', $account->slug).'"', false);

        $otherAccount = Account::factory()->create(['slug' => 'other-customer-landing-studio']);
        Location::factory()->for($otherAccount)->create(['slug' => 'main']);

        $this->get(route('public.studio', $otherAccount->slug))
            ->assertOk()
            ->assertDontSee($customer->name)
            ->assertDontSee('data-customer-dashboard-link', false)
            ->assertSee('href="'.route('customer.studio.login', $otherAccount->slug).'"', false);
    }

    public function test_public_studio_bot_placements_are_independent_and_tenant_scoped(): void
    {
        $account = Account::factory()->create([
            'slug' => 'public-studio-telegram-placements',
            'default_language' => 'uk',
        ]);
        $firstLocation = Location::factory()->for($account)->create([
            'name' => 'Alpha Studio',
            'slug' => 'alpha',
        ]);
        $secondLocation = Location::factory()->for($account)->create([
            'name' => 'Zulu Studio',
            'slug' => 'zulu',
        ]);
        $profile = $account->telegramBotProfiles()->create([
            'profile' => TelegramBotProfile::Customer->value,
            'mode' => TelegramBotMode::Simple->value,
            'is_enabled' => true,
            'settings' => [
                CustomerTelegramLinkResolver::PlacementSettingsKey => [
                    CustomerTelegramLinkResolver::PlacementPublicStudio => true,
                    CustomerTelegramLinkResolver::PlacementPublicContacts => false,
                ],
            ],
        ]);
        TelegramBotInstallation::factory()->for($account)->create([
            'profile' => TelegramBotProfile::Customer->value,
            'bot_username' => 'public_studio_bot',
        ]);
        $otherAccount = Account::factory()->create();
        TelegramBotInstallation::factory()->for($otherAccount)->create([
            'profile' => TelegramBotProfile::Customer->value,
            'bot_username' => 'other_public_studio_bot',
        ]);
        $botLink = 'https://t.me/public_studio_bot?start=ladna';

        $response = $this->get(route('public.studio', $account->slug))
            ->assertOk()
            ->assertSee('data-customer-telegram-bot-link="public-studio"', false)
            ->assertSeeInOrder([
                'href="'.route('public.schedule', [$account->slug, $firstLocation->slug]).'"',
                'data-customer-telegram-bot-link="public-studio"',
                'href="'.route('public.schedule', [$account->slug, $secondLocation->slug]).'"',
            ], false)
            ->assertSee($botLink, false)
            ->assertSee(__('app.customer_telegram_booking_bot', [], 'uk'))
            ->assertDontSee('data-public-support-link="customer_telegram_bot"', false)
            ->assertDontSee('other_public_studio_bot', false);
        $this->assertSame(1, substr_count($response->getContent(), $botLink));
        $this->assertSame(1, substr_count($response->getContent(), 'data-customer-telegram-bot-link="public-studio"'));

        $profile->forceFill(['settings' => [
            CustomerTelegramLinkResolver::PlacementSettingsKey => [
                CustomerTelegramLinkResolver::PlacementPublicStudio => false,
                CustomerTelegramLinkResolver::PlacementPublicContacts => true,
            ],
        ]])->save();

        $response = $this->get(route('public.studio', $account->slug))
            ->assertOk()
            ->assertDontSee('data-customer-telegram-bot-link="public-studio"', false)
            ->assertSee('data-public-support-link="customer_telegram_bot"', false)
            ->assertSee(__('app.customer_telegram_booking_bot', [], 'uk'))
            ->assertSee($botLink, false);
        $this->assertSame(1, substr_count($response->getContent(), $botLink));
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

    public function test_public_studio_landing_shows_only_current_account_upcoming_published_festivals_with_hero_images(): void
    {
        $account = Account::factory()->create([
            'default_language' => 'en',
            'enable_festivals' => true,
        ]);
        Location::factory()->for($account)->create();
        $series = FestivalSeries::factory()->for($account)->create();
        $ongoing = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Ongoing Studio Festival',
            'status' => 'in_progress',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        $upcoming = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Upcoming Studio Festival',
            'summary' => 'An upcoming Festival summary.',
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addDay(),
        ]);
        $cover = $upcoming->media()->create([
            'account_id' => $account->id,
            'kind' => 'image',
            'external_url' => 'https://cdn.example.test/upcoming-studio-festival.webp',
            'alt_text' => 'Festival hero image',
            'is_cover' => true,
            'is_active' => true,
        ]);
        foreach (range(1, 5) as $number) {
            FestivalEdition::factory()->published()->for($series)->create([
                'account_id' => $account->id,
                'title' => "Later Studio Festival {$number}",
                'starts_at' => now()->addMonths($number + 1),
                'ends_at' => now()->addMonths($number + 1)->addDay(),
            ]);
        }
        FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Past Studio Festival',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);
        FestivalEdition::factory()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Draft Studio Festival',
        ]);
        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        FestivalEdition::factory()->published()->for(FestivalSeries::factory()->for($otherAccount))->create([
            'account_id' => $otherAccount->id,
            'title' => 'Other Studio Festival',
        ]);

        $this->get(route('public.studio', $account->slug))
            ->assertOk()
            ->assertSee('data-public-festivals-rail', false)
            ->assertSee($ongoing->title)
            ->assertSee($upcoming->title)
            ->assertSee($upcoming->summary)
            ->assertSee($cover->url(), false)
            ->assertSee($cover->alt_text)
            ->assertSee(route('public.festivals.index', $account->slug), false)
            ->assertSee(route('public.festivals.show', [$account->slug, $upcoming->slug]), false)
            ->assertSeeInOrder([$ongoing->title, $upcoming->title, 'Later Studio Festival 1'])
            ->assertDontSee('Later Studio Festival 5')
            ->assertDontSee('Past Studio Festival')
            ->assertDontSee('Draft Studio Festival')
            ->assertDontSee('Other Studio Festival');

        $disabledAccount = Account::factory()->create(['enable_festivals' => false]);
        Location::factory()->for($disabledAccount)->create();
        FestivalEdition::factory()->published()->for(FestivalSeries::factory()->for($disabledAccount))->create([
            'account_id' => $disabledAccount->id,
            'title' => 'Disabled Studio Festival',
        ]);

        $this->get(route('public.studio', $disabledAccount->slug))
            ->assertOk()
            ->assertDontSee('data-public-festivals-rail', false)
            ->assertDontSee(route('public.festivals.index', $disabledAccount->slug), false)
            ->assertDontSee('Disabled Studio Festival');
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
