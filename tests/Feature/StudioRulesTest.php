<?php

namespace Tests\Feature;

use App\Enums\PublicScheduleView;
use App\Models\Account;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StudioRulesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_studio_owner_can_save_sanitized_studio_rules_and_public_offer(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'rules-studio',
        ]);
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'rules']))
            ->assertOk()
            ->assertSee(__('app.studio_rules_and_offer'))
            ->assertSee('name="studio_rules_html"', false)
            ->assertSee('name="public_offer_html"', false)
            ->assertSee('data-studio-rules-editor', false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.update', $account), $this->accountUpdatePayload($account, [
                'studio_rules_html' => '<h2 onclick="alert(1)">Safety</h2><script>alert(1)</script><p style="text-align: center; color: red;">Arrive early.</p><a href="javascript:alert(1)" target="_blank">bad</a><a href="https://example.com/rules" target="_blank">safe</a>',
                'public_offer_html' => '<h2 onclick="evil()">Offer terms</h2><script>evil()</script><p style="text-align: right; color: blue;">Payment before class.</p><a href="https://example.com/offer" target="_blank">details</a>',
            ]))
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'rules']));

        $account->refresh();

        $this->assertStringContainsString('<h2>Safety</h2>', (string) $account->studio_rules_html);
        $this->assertStringContainsString('style="text-align: center;"', (string) $account->studio_rules_html);
        $this->assertStringContainsString('href="https://example.com/rules"', (string) $account->studio_rules_html);
        $this->assertStringContainsString('rel="noopener noreferrer"', (string) $account->studio_rules_html);
        $this->assertStringNotContainsString('script', (string) $account->studio_rules_html);
        $this->assertStringNotContainsString('alert(1)', (string) $account->studio_rules_html);
        $this->assertStringNotContainsString('onclick', (string) $account->studio_rules_html);
        $this->assertStringNotContainsString('javascript:', (string) $account->studio_rules_html);
        $this->assertStringNotContainsString('color:', (string) $account->studio_rules_html);

        $this->assertStringContainsString('<h2>Offer terms</h2>', (string) $account->public_offer_html);
        $this->assertStringContainsString('style="text-align: right;"', (string) $account->public_offer_html);
        $this->assertStringContainsString('href="https://example.com/offer"', (string) $account->public_offer_html);
        $this->assertStringContainsString('rel="noopener noreferrer"', (string) $account->public_offer_html);
        $this->assertStringNotContainsString('script', (string) $account->public_offer_html);
        $this->assertStringNotContainsString('evil()', (string) $account->public_offer_html);
        $this->assertStringNotContainsString('onclick', (string) $account->public_offer_html);
        $this->assertStringNotContainsString('color:', (string) $account->public_offer_html);
    }

    public function test_empty_summernote_markup_is_saved_as_null_and_hides_public_links(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'slug' => 'empty-legal-studio',
            'studio_rules_html' => '<p>Rules</p>',
            'public_offer_html' => '<p>Offer</p>',
        ]);
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.update', $account), $this->accountUpdatePayload($account, [
                'studio_rules_html' => '<p><br></p>',
                'public_offer_html' => '<p>&nbsp;</p>',
            ]))
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'rules']));

        $account->refresh();

        $this->assertNull($account->studio_rules_html);
        $this->assertNull($account->public_offer_html);

        $this->get(route('public.studio', $account->slug))
            ->assertOk()
            ->assertDontSee(route('public.studio-rules', $account->slug), false)
            ->assertDontSee(route('public.studio-offer', $account->slug), false);
    }

    public function test_legal_documents_are_sanitized_when_submitted_from_another_account_tab(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['slug' => 'crafted-legal-studio']);
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.update', $account), $this->accountUpdatePayload($account, [
                'brand_tab' => 'business',
                'studio_rules_html' => '<p onclick="bad()">Rules</p><script>bad()</script>',
                'public_offer_html' => '<p onclick="bad()">Offer</p><script>bad()</script>',
            ]))
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', $account));

        $account->refresh();

        $this->assertSame('<p>Rules</p>', $account->studio_rules_html);
        $this->assertSame('<p>Offer</p>', $account->public_offer_html);
    }

    public function test_public_legal_document_pages_are_branded_account_scoped_and_identically_styled(): void
    {
        $account = Account::factory()->create([
            'name' => 'Legal Dance Studio',
            'slug' => 'legal-dance-studio',
            'default_language' => 'en',
            'studio_rules_html' => '<h2>Safety</h2><p>Arrive ten minutes early.</p>',
            'public_offer_html' => '<h2>Offer terms</h2><p>Payment is due before class.</p>',
        ]);
        $fallbackPath = route('public.studio', $account->slug, absolute: false);

        $this->get(route('public.studio-rules', $account->slug))
            ->assertOk()
            ->assertSee('Legal Dance Studio')
            ->assertSee('Studio rules')
            ->assertSee(__('app.powered_by_ladna'))
            ->assertSee('brand/ladna-mark.svg', false)
            ->assertDontSee(__('app.terms_of_service'))
            ->assertSee('href="'.$fallbackPath.'"', false)
            ->assertSee('class="studio-rules-content"', false)
            ->assertSee('<h2>Safety</h2>', false)
            ->assertSee('Arrive ten minutes early.');

        $this->get(route('public.studio-offer', $account->slug))
            ->assertOk()
            ->assertSee('Legal Dance Studio')
            ->assertSee('Public offer agreement')
            ->assertSee(__('app.powered_by_ladna'))
            ->assertSee('brand/ladna-mark.svg', false)
            ->assertDontSee(__('app.terms_of_service'))
            ->assertSee('href="'.$fallbackPath.'"', false)
            ->assertSee('class="studio-rules-content"', false)
            ->assertSee('<h2>Offer terms</h2>', false)
            ->assertSee('Payment is due before class.');
    }

    public function test_inactive_account_legal_document_pages_are_not_public(): void
    {
        $account = Account::factory()->create([
            'slug' => 'inactive-legal-studio',
            'status' => 'suspended',
        ]);

        $this->get(route('public.studio-rules', $account->slug))->assertNotFound();
        $this->get(route('public.studio-offer', $account->slug))->assertNotFound();
    }

    public function test_legal_document_back_link_preserves_an_allowed_same_account_source(): void
    {
        $account = Account::factory()->create(['slug' => 'return-legal-studio']);
        $location = Location::factory()->for($account)->create(['slug' => 'main']);
        $sourceUrl = route('public.schedule', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'period' => 'month',
            'room' => 'first-room',
        ]);
        $sourcePath = parse_url($sourceUrl, PHP_URL_PATH).'?'.parse_url($sourceUrl, PHP_URL_QUERY);

        $this->get(route('public.studio-offer', [
            'accountSlug' => $account->slug,
            'return_to' => $sourceUrl,
        ]))
            ->assertOk()
            ->assertSee('href="'.e($sourcePath).'"', false);
    }

    public function test_legal_document_back_link_rejects_external_cross_account_and_array_sources(): void
    {
        $account = Account::factory()->create(['slug' => 'safe-return-studio']);
        $location = Location::factory()->for($account)->create(['slug' => 'main']);
        $otherAccount = Account::factory()->create(['slug' => 'other-return-studio']);
        $otherLocation = Location::factory()->for($otherAccount)->create(['slug' => 'other-main']);
        $allowedPath = route('public.schedule', [$account->slug, $location->slug], absolute: false);
        $fallbackPath = route('public.studio', $account->slug, absolute: false);
        $invalidSources = [
            'https://evil.example'.$allowedPath.'?period=month',
            '//evil.example'.$allowedPath.'?period=month',
            route('public.schedule', [$otherAccount->slug, $otherLocation->slug]),
            'not-a-url',
        ];

        foreach ($invalidSources as $invalidSource) {
            $this->get(route('public.studio-rules', [
                'accountSlug' => $account->slug,
                'return_to' => $invalidSource,
            ]))
                ->assertOk()
                ->assertSee('href="'.$fallbackPath.'"', false)
                ->assertDontSee('evil.example');
        }

        $this->get(route('public.studio-rules', [
            'accountSlug' => $account->slug,
            'return_to' => [route('public.schedule', [$account->slug, $location->slug])],
        ]))
            ->assertOk()
            ->assertSee('href="'.$fallbackPath.'"', false);
    }

    public function test_landing_price_and_both_schedule_layouts_show_configured_legal_links(): void
    {
        $account = Account::factory()->create([
            'slug' => 'legal-links-studio',
            'default_language' => 'en',
            'studio_rules_html' => '<p>Rules</p>',
            'public_offer_html' => '<p>Offer</p>',
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'main']);
        $sourceUrls = [
            route('public.studio', $account->slug),
            route('public.price', [$account->slug, $location->slug]),
            route('public.price.embed', [$account->slug, $location->slug]),
            route('public.schedule', [$account->slug, $location->slug]),
            route('public.schedule.embed', [$account->slug, $location->slug]),
        ];

        foreach ($sourceUrls as $sourceUrl) {
            $this->get($sourceUrl)
                ->assertOk()
                ->assertSee(route('public.studio-rules', [
                    'accountSlug' => $account->slug,
                    'return_to' => $sourceUrl,
                ]), false)
                ->assertSee(route('public.studio-offer', [
                    'accountSlug' => $account->slug,
                    'return_to' => $sourceUrl,
                ]), false);
        }

        $account->update(['public_schedule_view' => PublicScheduleView::Classic->value()]);
        $classicScheduleUrl = route('public.schedule', [$account->slug, $location->slug]);

        $this->get($classicScheduleUrl)
            ->assertOk()
            ->assertSee(route('public.studio-rules', [
                'accountSlug' => $account->slug,
                'return_to' => $classicScheduleUrl,
            ]), false)
            ->assertSee(route('public.studio-offer', [
                'accountSlug' => $account->slug,
                'return_to' => $classicScheduleUrl,
            ]), false);
    }

    public function test_public_legal_links_are_hidden_independently(): void
    {
        $account = Account::factory()->create([
            'slug' => 'conditional-legal-links-studio',
            'studio_rules_html' => '<p>Rules only</p>',
            'public_offer_html' => null,
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'main']);

        $this->get(route('public.price', [$account->slug, $location->slug]))
            ->assertOk()
            ->assertSee(route('public.studio-rules', $account->slug), false)
            ->assertDontSee(route('public.studio-offer', $account->slug), false);

        $account->update([
            'studio_rules_html' => null,
            'public_offer_html' => '<p>Offer only</p>',
        ]);

        $this->get(route('public.schedule', [$account->slug, $location->slug]))
            ->assertOk()
            ->assertDontSee(route('public.studio-rules', $account->slug), false)
            ->assertSee(route('public.studio-offer', $account->slug), false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function accountUpdatePayload(Account $account, array $overrides = []): array
    {
        return [
            'brand_tab' => 'rules',
            'name' => $account->name,
            'slug' => $account->slug,
            'default_language' => $account->default_language,
            'country_code' => $account->country_code,
            'default_currency' => $account->default_currency,
            'brand_color' => $account->brand_color,
            'timezone' => $account->timezone,
            ...$overrides,
        ];
    }
}
