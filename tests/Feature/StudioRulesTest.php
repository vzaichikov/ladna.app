<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StudioRulesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_studio_owner_can_save_sanitized_studio_rules(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'rules-studio',
        ]);
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.brand.edit', [$account, 'tab' => 'rules']))
            ->assertOk()
            ->assertSee(__('app.studio_rules'))
            ->assertSee('data-studio-rules-editor', false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.update', $account), [
                'brand_tab' => 'rules',
                'name' => $account->name,
                'slug' => $account->slug,
                'default_language' => 'uk',
                'country_code' => 'UA',
                'default_currency' => 'UAH',
                'brand_color' => '#3B223F',
                'timezone' => 'Europe/Kyiv',
                'studio_rules_html' => '<h2 onclick="alert(1)">Safety</h2><script>alert(1)</script><p style="text-align: center; color: red;">Arrive early.</p><a href="javascript:alert(1)" target="_blank">bad</a><a href="https://example.com/rules" target="_blank">safe</a>',
            ])
            ->assertRedirect(route('dashboard.accounts.brand.edit', [$account, 'tab' => 'rules']));

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
    }

    public function test_public_studio_rules_page_is_branded_and_account_scoped(): void
    {
        $account = Account::factory()->create([
            'name' => 'Rules Dance Studio',
            'slug' => 'rules-dance-studio',
            'default_language' => 'en',
            'studio_rules_html' => '<h2>Safety</h2><p>Arrive ten minutes early.</p>',
        ]);

        $this->get(route('public.studio-rules', $account->slug))
            ->assertOk()
            ->assertSee('Rules Dance Studio')
            ->assertSee('Studio rules')
            ->assertSee(__('app.powered_by_ladna'))
            ->assertSee('brand/ladna-mark.svg', false)
            ->assertDontSee(__('app.terms_of_service'))
            ->assertSee('<h2>Safety</h2>', false)
            ->assertSee('Arrive ten minutes early.');
    }

    public function test_inactive_account_rules_page_is_not_public(): void
    {
        $account = Account::factory()->create([
            'slug' => 'inactive-rules-studio',
            'status' => 'suspended',
        ]);

        $this->get(route('public.studio-rules', $account->slug))->assertNotFound();
    }

    public function test_public_customer_surfaces_link_to_studio_rules(): void
    {
        $account = Account::factory()->create([
            'slug' => 'rules-link-studio',
            'default_language' => 'en',
        ]);
        $location = $account->locations()->create([
            'name' => 'Main',
            'slug' => 'main',
            'timezone' => 'Europe/Kyiv',
            'is_active' => true,
        ]);

        $this->get(route('public.schedule', [$account->slug, $location->slug]))
            ->assertOk()
            ->assertSee(__('app.powered_by_ladna'))
            ->assertSee(route('public.studio-rules', $account->slug), false);

        $this->get(route('customer.studio.login', $account->slug))
            ->assertOk()
            ->assertSee(__('app.powered_by_ladna'))
            ->assertSee('brand/ladna-mark.svg', false)
            ->assertDontSee(__('app.terms_of_service'))
            ->assertSee(route('public.studio-rules', $account->slug), false);
    }
}
