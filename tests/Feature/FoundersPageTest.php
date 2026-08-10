<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\TelegramBroadcastTarget;
use App\Models\User;
use App\Support\FoundersProgramSettings;
use App\Support\ReservedPublicSlugs;
use App\Support\SystemAppearance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FoundersPageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_founders_pages_and_banner_are_disabled_by_default(): void
    {
        $this->configureFounders(pageEnabled: false, bannerEnabled: false, remainingStudios: 0, supportUrl: null);

        $this->get(route('founders'))->assertNotFound();
        $this->get(route('founders.en'))->assertNotFound();
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee(__('founders.banner.aria'));
        $this->get(route('features'))
            ->assertOk()
            ->assertDontSee(__('founders.banner.aria'));
    }

    public function test_banner_visibility_depends_on_both_page_and_banner_settings(): void
    {
        $supportUrl = 'https://t.me/ladna_support';

        $this->configureFounders(false, true, 3, $supportUrl);
        $this->get(route('home'))->assertOk()->assertDontSee(__('founders.banner.aria'));
        $this->get(route('founders'))->assertNotFound();

        $this->configureFounders(true, false, 3, $supportUrl);
        $this->get(route('home'))->assertOk()->assertDontSee(__('founders.banner.aria'));
        $this->get(route('founders'))->assertOk()->assertDontSee(__('founders.banner.aria'));

        $this->configureFounders(true, true, 3, $supportUrl);
        $this->get(route('home'))->assertOk()->assertSee(__('founders.banner.aria'));
        $this->get(route('features'))->assertOk()->assertSee(__('founders.banner.aria'));
        $this->get(route('founders'))->assertOk()->assertSee(__('founders.banner.aria'));
    }

    public function test_founders_page_requires_a_remaining_studio_and_support_url_even_if_enabled(): void
    {
        $this->configureFounders(true, true, 0, 'https://t.me/ladna_support');
        $this->get(route('founders'))->assertNotFound();
        $this->get(route('home'))->assertOk()->assertDontSee(__('founders.banner.aria'));

        $this->configureFounders(true, true, 3, null);
        $this->get(route('founders'))->assertNotFound();
        $this->get(route('home'))->assertOk()->assertDontSee(__('founders.banner.aria'));
    }

    public function test_ukrainian_founders_page_uses_support_telegram_and_internal_banner_link(): void
    {
        $supportUrl = 'https://t.me/ladna_support';
        $privateChatId = '-1009988776655';

        $this->configureFounders(true, true, 2, $supportUrl);
        TelegramBroadcastTarget::factory()->create(['telegram_chat_id' => $privateChatId]);

        $response = $this->withSession(['locale' => 'en'])->get(route('founders'));

        $response
            ->assertOk()
            ->assertSee('Ladna Founders — розвиваємо Ladna разом')
            ->assertSee('Залишилося 2 місця')
            ->assertSee('href="'.$supportUrl.'"', false)
            ->assertSee('href="#join-founders"', false)
            ->assertSee('Відповість команда Ladna, не бот.')
            ->assertDontSee($privateChatId)
            ->assertSessionHas('locale', 'uk');
    }

    public function test_english_founders_page_controls_locale_and_language_switch(): void
    {
        $supportUrl = 'https://t.me/ladna_support';
        $this->configureFounders(true, false, 1, $supportUrl);

        $this->withSession(['locale' => 'uk'])
            ->get(route('founders.en'))
            ->assertOk()
            ->assertSee('Ladna Founders — let’s build Ladna together')
            ->assertSee('1 place left')
            ->assertSee('href="'.$supportUrl.'"', false)
            ->assertSee('href="'.route('founders').'"', false)
            ->assertSessionHas('locale', 'en');
    }

    public function test_ukrainian_remaining_studio_copy_handles_one_two_and_five(): void
    {
        $supportUrl = 'https://t.me/ladna_support';

        foreach ([
            1 => 'Залишилося 1 місце',
            2 => 'Залишилося 2 місця',
            5 => 'Залишилося 5 місць',
        ] as $remainingStudios => $expectedCopy) {
            $this->configureFounders(true, false, $remainingStudios, $supportUrl);

            $this->get(route('founders'))
                ->assertOk()
                ->assertSee($expectedCopy);
        }
    }

    public function test_banner_is_limited_to_marketing_pages(): void
    {
        $this->configureFounders(true, true, 3, 'https://t.me/ladna_support');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('founders').'"', false);
        $this->get(route('features'))
            ->assertOk()
            ->assertSee('href="'.route('founders').'"', false);
        $this->get(route('privacy.ua'))
            ->assertOk()
            ->assertDontSee(__('founders.banner.aria'));
    }

    public function test_platform_admin_can_manage_founders_recruitment_settings(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $supportUrl = 'https://t.me/ladna_support';

        $this->actingAs($platformAdmin)
            ->get(route('platform.settings.edit', ['tab' => 'support']))
            ->assertOk()
            ->assertSee('name="founders_page_enabled"', false)
            ->assertSee('name="founders_banner_enabled"', false)
            ->assertSee('name="founders_remaining_studios"', false);

        $this->actingAs($platformAdmin)
            ->put(route('platform.settings.update'), [
                'font_family' => SystemAppearance::currentFontKey(),
                'support_url' => $supportUrl,
                'founders_page_enabled' => '1',
                'founders_banner_enabled' => '1',
                'founders_remaining_studios' => '7',
                'settings_tab' => 'support',
            ])
            ->assertRedirect(route('platform.settings.edit', ['tab' => 'support']));

        $program = app(FoundersProgramSettings::class)->current();

        $this->assertTrue($program['page_enabled']);
        $this->assertTrue($program['banner_enabled']);
        $this->assertSame(7, $program['remaining_studios']);
        $this->assertSame($supportUrl, $program['support_url']);
        $this->assertTrue($program['page_available']);
    }

    public function test_platform_settings_reject_enabled_page_without_required_recruitment_data(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->from(route('platform.settings.edit', ['tab' => 'support']))
            ->put(route('platform.settings.update'), [
                'font_family' => SystemAppearance::currentFontKey(),
                'support_url' => null,
                'founders_page_enabled' => '1',
                'founders_banner_enabled' => '1',
                'founders_remaining_studios' => '0',
                'settings_tab' => 'support',
            ])
            ->assertRedirect(route('platform.settings.edit', ['tab' => 'support']))
            ->assertSessionHasErrors(['support_url', 'founders_remaining_studios']);
    }

    public function test_normal_owner_cannot_update_founders_recruitment_settings(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->put(route('platform.settings.update'), [
                'font_family' => SystemAppearance::currentFontKey(),
                'support_url' => 'https://t.me/ladna_support',
                'founders_page_enabled' => '1',
                'founders_banner_enabled' => '1',
                'founders_remaining_studios' => '3',
            ])
            ->assertForbidden();
    }

    public function test_founders_is_reserved_from_public_studio_slugs(): void
    {
        $this->assertTrue(ReservedPublicSlugs::isReserved('founders'));
    }

    private function configureFounders(
        bool $pageEnabled,
        bool $bannerEnabled,
        int $remainingStudios,
        ?string $supportUrl,
    ): void {
        SystemSetting::setValue(SystemSetting::SupportUrlKey, $supportUrl);
        app(FoundersProgramSettings::class)->save($pageEnabled, $bannerEnabled, $remainingStudios);
    }
}
