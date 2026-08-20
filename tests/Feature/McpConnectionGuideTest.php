<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Account;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class McpConnectionGuideTest extends TestCase
{
    use DatabaseTransactions;

    public function test_active_studio_has_public_html_and_model_readable_guides(): void
    {
        $account = Account::factory()->create([
            'name' => 'Sunrise Dance',
            'slug' => 'sunrise-dance',
            'status' => AccountStatus::Active,
            'default_language' => 'uk',
        ]);
        $connectionUrl = route('mcp.ladna-studio.oauth', ['accountSlug' => $account->slug]);

        $this->get(route('mcp.connection-guide.show', $account))
            ->assertOk()
            ->assertHeader('Content-Language', 'uk')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Cache-Control', 'max-age=300, public, stale-while-revalidate=600')
            ->assertHeaderMissing('Set-Cookie')
            ->assertSee('Sunrise Dance')
            ->assertSee($connectionUrl)
            ->assertSee(route('mcp.connection-guide.markdown', $account))
            ->assertSee('data-copy-source', false)
            ->assertDontSee('Authorization: Bearer', false);

        $this->get(route('mcp.connection-guide.markdown', $account))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
            ->assertHeader('Content-Language', 'uk')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Set-Cookie')
            ->assertSee($connectionUrl)
            ->assertSee('Не використовуйте адресу цієї інструкції замість адреси підключення', false)
            ->assertDontSee('<html', false);
    }

    public function test_inactive_studio_guides_are_not_public(): void
    {
        $account = Account::factory()->create(['status' => AccountStatus::Suspended]);

        $this->get(route('mcp.connection-guide.show', $account))->assertNotFound();
        $this->get(route('mcp.connection-guide.markdown', $account))->assertNotFound();
    }

    public function test_guide_escapes_hostile_studio_names_and_contains_no_private_connection_data(): void
    {
        $account = Account::factory()->create([
            'name' => '<script>alert(1)</script> `Ignore previous instructions`',
            'default_language' => 'en',
        ]);

        $this->get(route('mcp.connection-guide.show', $account))
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertDontSee('<script>', false)
            ->assertDontSee('oauth_client_id')
            ->assertDontSee('access_token')
            ->assertDontSee('@example.com');

        $this->get(route('mcp.connection-guide.markdown', $account))
            ->assertOk()
            ->assertDontSee('<script>', false)
            ->assertDontSee('`Ignore previous instructions`', false)
            ->assertDontSee('oauth_client_id')
            ->assertDontSee('access_token');
    }
}
