<?php

namespace Tests\Feature;

use App\Support\ApplicationVersion;
use Tests\TestCase;

class PwaTest extends TestCase
{
    public function test_version_endpoint_returns_current_revision_without_cache(): void
    {
        $response = $this->get('/app-version.json');

        $response->assertStatus(200);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertJson([
            'version' => ApplicationVersion::current(),
            'revision' => ApplicationVersion::revision(),
        ]);
    }

    public function test_manifest_contains_install_metadata_and_assets(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/manifest+json');

        $manifest = $response->json();

        $this->assertSame('Ladna - Studio Management', $manifest['name']);
        $this->assertSame('en', $manifest['lang']);
        $this->assertStringEndsWith('/login', $manifest['start_url']);
        $this->assertStringEndsWith('/', $manifest['scope']);
        $this->assertStringEndsWith('/', $manifest['id']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#3B223F', $manifest['theme_color']);
        $this->assertCount(4, $manifest['icons']);
        $this->assertCount(2, $manifest['shortcuts']);
        $this->assertCount(4, $manifest['screenshots']);
        $this->assertContains('wide', array_column($manifest['screenshots'], 'form_factor'));
        $this->assertContains('narrow', array_column($manifest['screenshots'], 'form_factor'));

        foreach ([...$manifest['icons'], ...$manifest['screenshots']] as $asset) {
            $this->assertFileExists(public_path(parse_url($asset['src'], PHP_URL_PATH)));
        }
    }

    public function test_manifest_urls_follow_the_current_request_host(): void
    {
        $manifest = $this
            ->get('https://app.example.test/manifest.webmanifest')
            ->assertStatus(200)
            ->json();

        $this->assertSame('https://app.example.test/', $manifest['id']);
        $this->assertSame('https://app.example.test/', $manifest['scope']);
        $this->assertSame('https://app.example.test/login', $manifest['start_url']);
        $this->assertStringStartsWith('https://app.example.test/pwa/', $manifest['icons'][0]['src']);
        $this->assertStringStartsWith('https://app.example.test/pwa/', $manifest['screenshots'][0]['src']);
    }

    public function test_offline_and_service_worker_routes_are_public(): void
    {
        $this->get('/offline.html')
            ->assertStatus(200)
            ->assertSee('Ladna is offline')
            ->assertSee("Немає з'єднання", false);
        $this->assertStringContainsString('no-store', (string) $this->get('/offline.html')->headers->get('Cache-Control'));

        $this->get('/service-worker')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->assertHeader('Service-Worker-Allowed', '/')
            ->assertSee('fonts.googleapis.com', false)
            ->assertSee('fonts.gstatic.com', false)
            ->assertSee('cacheFirst(request)', false)
            ->assertDontSee('ladna-app-cache', false);
    }

    public function test_shared_layouts_include_pwa_metadata_and_update_prompt(): void
    {
        $this->get('/')
            ->assertStatus(200)
            ->assertSee('manifest.webmanifest', false)
            ->assertSee('apple-touch-icon.png', false)
            ->assertSee('data-app-update', false)
            ->assertSee('app-version.json', false)
            ->assertSee('service-worker', false);

        $this->get('/login')
            ->assertStatus(200)
            ->assertSee('manifest.webmanifest', false)
            ->assertSee('data-app-update', false);
    }

    public function test_public_embed_layout_excludes_pwa_metadata_and_update_prompt(): void
    {
        $html = view('layouts.public', [
            'isEmbed' => true,
            'systemAppearance' => [
                'google_fonts_url' => 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap',
                'css_family' => 'Manrope',
            ],
            'applicationVersion' => ApplicationVersion::current(),
            'applicationRevision' => ApplicationVersion::revision(),
        ])->render();

        $this->assertStringNotContainsString('manifest.webmanifest', $html);
        $this->assertStringNotContainsString('data-app-update', $html);
    }
}
