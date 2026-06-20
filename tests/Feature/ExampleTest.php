<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $version = trim((string) file_get_contents(base_path('VERSION')));

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee($version);
        $response->assertSee('changelog.', false);
    }

    public function test_changelog_pages_render_release_history(): void
    {
        $version = trim((string) file_get_contents(base_path('VERSION')));

        $this->get('/changelog.en.html')
            ->assertStatus(200)
            ->assertSee("Current version {$version}")
            ->assertSee('Initial application baseline');

        $this->get('/changelog.ua.html')
            ->assertStatus(200)
            ->assertSee("Поточна версія {$version}")
            ->assertSee('Початкова база застосунку');
    }
}
