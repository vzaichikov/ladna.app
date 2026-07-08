<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Location;
use App\Models\User;
use App\Support\ApplicationVersion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_version_endpoint_returns_current_revision_without_cache(): void
    {
        $response = $this->get('/app/app-version.json');

        $response->assertStatus(200);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertJson([
            'version' => ApplicationVersion::current(),
            'revision' => ApplicationVersion::revision(),
        ]);
    }

    public function test_central_manifest_uses_app_scope_and_keeps_migration_endpoint(): void
    {
        $response = $this->get('/app/manifest.webmanifest');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/manifest+json');

        $manifest = $response->json();

        $this->assertSame('Ladna - Studio Management', $manifest['name']);
        $this->assertSame('en', $manifest['lang']);
        $this->assertStringEndsWith('/app/', $manifest['start_url']);
        $this->assertStringEndsWith('/app/', $manifest['scope']);
        $this->assertStringEndsWith('/', $manifest['id']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#3B223F', $manifest['theme_color']);
        $this->assertCount(4, $manifest['icons']);
        $this->assertSame([
            'manifest-icon-192.png',
            'manifest-icon-512.png',
            'manifest-maskable-icon-192.png',
            'manifest-maskable-icon-512.png',
        ], array_map(
            fn (array $icon): string => basename((string) parse_url($icon['src'], PHP_URL_PATH)),
            $manifest['icons']
        ));
        $this->assertCount(2, $manifest['shortcuts']);
        $this->assertSame(
            ['manifest-icon-192.png', 'manifest-icon-192.png'],
            array_map(
                fn (array $shortcut): string => basename((string) parse_url($shortcut['icons'][0]['src'], PHP_URL_PATH)),
                $manifest['shortcuts']
            )
        );
        $this->assertCount(4, $manifest['screenshots']);
        $this->assertContains('wide', array_column($manifest['screenshots'], 'form_factor'));
        $this->assertContains('narrow', array_column($manifest['screenshots'], 'form_factor'));

        foreach ([...$manifest['icons'], ...$manifest['screenshots']] as $asset) {
            $this->assertFileExists(public_path(ltrim((string) parse_url($asset['src'], PHP_URL_PATH), '/')));
        }

        $legacyManifest = $this->get('/manifest.webmanifest')->assertStatus(200)->json();
        $this->assertSame($manifest['id'], $legacyManifest['id']);
        $this->assertSame($manifest['scope'], $legacyManifest['scope']);
        $this->assertSame($manifest['start_url'], $legacyManifest['start_url']);
    }

    public function test_central_manifest_urls_follow_the_current_request_host(): void
    {
        $manifest = $this
            ->get('https://app.example.test/app/manifest.webmanifest')
            ->assertStatus(200)
            ->json();

        $this->assertSame('https://app.example.test/', $manifest['id']);
        $this->assertSame('https://app.example.test/app/', $manifest['scope']);
        $this->assertSame('https://app.example.test/app/', $manifest['start_url']);
        $this->assertStringStartsWith('https://app.example.test/pwa/', $manifest['icons'][0]['src']);
        $this->assertStringStartsWith('https://app.example.test/pwa/', $manifest['screenshots'][0]['src']);
    }

    public function test_studio_manifest_uses_studio_scope_branding_language_and_icons(): void
    {
        [$account] = $this->studioWithLocation();

        $manifest = $this
            ->get('https://studio.example.test/'.$account->slug.'/manifest.webmanifest')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->json();

        $this->assertSame($account->name, $manifest['name']);
        $this->assertSame('https://studio.example.test/'.$account->slug.'/', $manifest['id']);
        $this->assertSame('https://studio.example.test/'.$account->slug.'/', $manifest['scope']);
        $this->assertSame('https://studio.example.test/'.$account->slug.'/', $manifest['start_url']);
        $this->assertSame($account->brand_color, $manifest['theme_color']);
        $this->assertSame($account->default_language, $manifest['lang']);
        $this->assertCount(4, $manifest['icons']);

        foreach ($manifest['icons'] as $icon) {
            $this->assertSame('image/png', $icon['type']);
            $this->assertStringStartsWith('https://studio.example.test/'.$account->slug.'/pwa/icon-', $icon['src']);
            $this->assertStringContainsString('v=', $icon['src']);
        }
    }

    public function test_offline_and_service_worker_routes_are_public_and_scoped(): void
    {
        [$account] = $this->studioWithLocation();

        $this->get('/app/offline.html')
            ->assertStatus(200)
            ->assertSee('Ladna is offline')
            ->assertSee("Немає з'єднання", false);
        $this->assertStringContainsString('no-store', (string) $this->get('/app/offline.html')->headers->get('Cache-Control'));

        $this->get('/app/service-worker')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->assertHeader('Service-Worker-Allowed', '/app/')
            ->assertSee('fonts.googleapis.com', false)
            ->assertSee('fonts.gstatic.com', false)
            ->assertSee('cacheFirst(request)', false)
            ->assertDontSee('ladna-app-cache', false);

        $this->get('/'.$account->slug.'/service-worker')
            ->assertStatus(200)
            ->assertHeader('Service-Worker-Allowed', '/'.$account->slug.'/')
            ->assertSee('cacheFirst(request)', false);

        $this->get('/service-worker')
            ->assertStatus(200)
            ->assertHeader('Service-Worker-Allowed', '/')
            ->assertSee('self.registration.unregister()', false)
            ->assertDontSee('cacheFirst(request)', false);
    }

    public function test_root_marketing_layouts_do_not_include_pwa_metadata_or_update_prompt(): void
    {
        $this->get('/')
            ->assertStatus(200)
            ->assertDontSee('manifest.webmanifest', false)
            ->assertDontSee('apple-touch-icon.png', false)
            ->assertDontSee('data-app-update', false)
            ->assertDontSee('app-version.json', false)
            ->assertDontSee('service-worker', false);

        $this->get('/en')
            ->assertStatus(200)
            ->assertDontSee('manifest.webmanifest', false)
            ->assertDontSee('data-app-update', false);
    }

    public function test_app_login_layout_includes_central_pwa_metadata_update_prompt_and_install_button(): void
    {
        $this->get(route('login', absolute: false))
            ->assertStatus(200)
            ->assertSee(route('pwa.manifest'), false)
            ->assertSee(asset('pwa/apple-touch-icon.png'), false)
            ->assertSee('data-app-update', false)
            ->assertSee(route('pwa.version'), false)
            ->assertSee(route('pwa.service-worker'), false)
            ->assertSee('data-pwa-install', false);
    }

    public function test_studio_public_and_customer_pages_include_studio_pwa_metadata(): void
    {
        [$account] = $this->studioWithLocation();

        $this->get(route('public.studio', $account->slug, false))
            ->assertOk()
            ->assertSee(route('pwa.studio.manifest', $account->slug), false)
            ->assertSee(route('pwa.studio.service-worker', $account->slug), false)
            ->assertSee(route('pwa.studio.icon', [$account->slug, 180]), false)
            ->assertDontSee(route('pwa.manifest'), false);

        $this->get(route('customer.studio.login', $account->slug, false))
            ->assertOk()
            ->assertSee(route('pwa.studio.manifest', $account->slug), false)
            ->assertSee(route('pwa.studio.service-worker', $account->slug), false)
            ->assertDontSee(route('pwa.manifest'), false);
    }

    public function test_public_embed_pages_exclude_pwa_metadata_and_update_prompt(): void
    {
        [$account, $location] = $this->studioWithLocation();

        $html = $this->get(route('public.schedule.embed', [$account->slug, $location->slug], false))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('manifest.webmanifest', $html);
        $this->assertStringNotContainsString('data-app-update', $html);
        $this->assertStringNotContainsString('data-pwa-install', $html);
    }

    public function test_app_entry_redirects_guests_to_app_login(): void
    {
        $this->get('/app/')
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_legacy_app_urls_redirect_to_app_scope_with_queries_and_methods_preserved(): void
    {
        $this->get('/dashboard/classes?tab=week')
            ->assertStatus(308)
            ->assertRedirect('/app/dashboard/classes?tab=week');

        $this->get('/platform/accounts?page=2')
            ->assertStatus(308)
            ->assertRedirect('/app/platform/accounts?page=2');

        $this->get('/login?next=dashboard')
            ->assertStatus(308)
            ->assertRedirect('/app/login?next=dashboard');

        $this->get('/en/login')
            ->assertStatus(308)
            ->assertRedirect('/app/en/login');

        $this->post('/login?next=dashboard')
            ->assertStatus(308)
            ->assertRedirect('/app/login?next=dashboard');

        $this->get('/help/passes-prices?from=old')
            ->assertStatus(308)
            ->assertRedirect('/app/help/passes-prices?from=old');

        $this->actingAs(User::factory()->create())
            ->post('/logout?source=old')
            ->assertStatus(308)
            ->assertRedirect('/app/logout?source=old');
    }

    /**
     * @return array{0: Account, 1: Location}
     */
    private function studioWithLocation(): array
    {
        $account = Account::factory()->create([
            'name' => 'PWA Studio',
            'slug' => 'pwa-studio-'.fake()->unique()->numberBetween(1000, 9999),
            'default_language' => 'en',
            'brand_color' => '#d80a7d',
            'studio_slogan' => 'Install our studio app.',
        ]);
        $location = Location::factory()->for($account)->create([
            'slug' => 'main',
        ]);

        return [$account, $location];
    }
}
