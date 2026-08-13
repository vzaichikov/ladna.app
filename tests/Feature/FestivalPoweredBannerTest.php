<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalSeries;
use App\Models\FestivalTicketOrder;
use App\Models\User;
use App\Support\Festivals\FestivalPoweredBannerSettings;
use App\Support\SystemAppearance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FestivalPoweredBannerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_banner_is_disabled_by_default(): void
    {
        [$account, $edition] = $this->publishedEdition();

        $this->get(route('public.festivals.index', $account->slug))
            ->assertOk()
            ->assertDontSee('data-festival-powered-banner', false);

        $this->get(route('public.festivals.show', [$account->slug, $edition->slug]))
            ->assertOk()
            ->assertDontSee('data-festival-powered-banner', false);
    }

    public function test_enabled_banner_appears_on_public_festival_pages_only(): void
    {
        [$account, $edition] = $this->publishedEdition();
        $order = FestivalTicketOrder::factory()->for($edition, 'edition')->create([
            'account_id' => $account->id,
        ]);
        app(FestivalPoweredBannerSettings::class)->save(true);

        foreach ([
            route('public.festivals.index', $account->slug),
            route('public.festivals.show', [$account->slug, $edition->slug]),
            route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]),
        ] as $publicUrl) {
            $this->get($publicUrl)
                ->assertOk()
                ->assertSee('data-festival-powered-banner', false)
                ->assertSee(__('app.festival_powered_banner_message'))
                ->assertSee('href="'.route('home').'"', false)
                ->assertSee('action="'.route('festival-powered-banner.dismiss').'"', false)
                ->assertSee('aria-label="'.__('app.close').'"', false);
        }

        $account->update(['allowed_festival_landing_templates' => ['velvet_night']]);
        $edition->update(['landing_template' => 'velvet_night']);

        $this->get(route('public.festivals.show', [$account->slug, $edition->slug]))
            ->assertOk()
            ->assertSee('data-festival-template="velvet_night"', false)
            ->assertSee('data-festival-powered-banner', false);

        $this->withSession(['locale' => 'en'])
            ->get(route('public.festivals.index', $account->slug))
            ->assertOk()
            ->assertSee(__('app.festival_powered_banner_message'))
            ->assertSee('href="'.route('home.en').'"', false);

        $this->get(route('festival.login', $account->slug))
            ->assertOk()
            ->assertDontSee('data-festival-powered-banner', false);
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-festival-powered-banner', false);
    }

    public function test_dismissal_sets_a_persistent_cookie_and_suppresses_the_banner(): void
    {
        [$account, $edition] = $this->publishedEdition();
        $settings = app(FestivalPoweredBannerSettings::class);
        $settings->save(true);
        $publicUrl = route('public.festivals.show', [$account->slug, $edition->slug]);

        $this->get($publicUrl)
            ->assertOk()
            ->assertSee('data-festival-powered-banner', false);

        $this->from($publicUrl)
            ->post(route('festival-powered-banner.dismiss'))
            ->assertRedirect($publicUrl)
            ->assertCookie(FestivalPoweredBannerSettings::DismissedCookieName, '1');

        $this->withCookie(FestivalPoweredBannerSettings::DismissedCookieName, '1')
            ->get($publicUrl)
            ->assertOk()
            ->assertDontSee('data-festival-powered-banner', false);
    }

    public function test_platform_admin_can_enable_and_disable_the_banner_globally(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $settings = app(FestivalPoweredBannerSettings::class);

        $this->actingAs($platformAdmin)
            ->get(route('platform.settings.edit', ['tab' => 'support']))
            ->assertOk()
            ->assertSee('name="festival_powered_banner_enabled"', false);

        $this->actingAs($platformAdmin)
            ->put(route('platform.settings.update'), [
                'font_family' => SystemAppearance::currentFontKey(),
                'festival_powered_banner_enabled' => '1',
                'settings_tab' => 'support',
            ])
            ->assertRedirect(route('platform.settings.edit', ['tab' => 'support']));

        $this->assertTrue($settings->enabled());

        $this->actingAs($platformAdmin)
            ->put(route('platform.settings.update'), [
                'font_family' => SystemAppearance::currentFontKey(),
                'festival_powered_banner_enabled' => '0',
                'settings_tab' => 'support',
            ])
            ->assertRedirect(route('platform.settings.edit', ['tab' => 'support']));

        $this->assertFalse($settings->enabled());
    }

    /** @return array{Account, FestivalEdition} */
    private function publishedEdition(): array
    {
        $account = Account::factory()->create([
            'enable_festivals' => true,
            'default_language' => 'uk',
        ]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()
            ->published()
            ->for($series)
            ->create(['account_id' => $account->id]);

        return [$account, $edition];
    }
}
