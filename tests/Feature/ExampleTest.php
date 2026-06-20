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
        $this->get('/changelog.en.html')
            ->assertStatus(200)
            ->assertSee('Current version 0.12.0')
            ->assertSee('Initial application baseline');

        $this->get('/changelog.ua.html')
            ->assertStatus(200)
            ->assertSee('Поточна версія 0.12.0')
            ->assertSee('Початкова база застосунку');
    }
}
