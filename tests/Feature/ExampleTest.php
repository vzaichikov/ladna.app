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

    public function test_landing_language_urls_control_the_visible_locale(): void
    {
        $this->withSession(['locale' => 'uk'])
            ->get('/en')
            ->assertStatus(200)
            ->assertSee('A studio should move with classes, not spreadsheets')
            ->assertSee('href="'.route('home').'"', false)
            ->assertSessionHas('locale', 'en');

        $this->withSession(['locale' => 'en'])
            ->get('/')
            ->assertStatus(200)
            ->assertSee('Студія працює в ритмі занять, а не таблиць')
            ->assertSee('href="'.route('home.en').'"', false)
            ->assertSessionHas('locale', 'uk');
    }

    public function test_login_language_urls_control_the_visible_locale(): void
    {
        $this->withSession(['locale' => 'en'])
            ->get('/login')
            ->assertStatus(200)
            ->assertSee('Увійдіть, щоб керувати розкладом')
            ->assertSee('href="'.route('login.en').'"', false)
            ->assertSessionHas('locale', 'uk');

        $this->withSession(['locale' => 'uk'])
            ->get('/en/login')
            ->assertStatus(200)
            ->assertSee('Log in to manage schedules')
            ->assertSee('href="'.route('login').'"', false)
            ->assertSessionHas('locale', 'en');
    }

    public function test_changelog_pages_render_release_history(): void
    {
        $version = trim((string) file_get_contents(base_path('VERSION')));
        $englishLastPage = (int) ceil(count(config('changelog.releases.en')) / 20);
        $ukrainianLastPage = (int) ceil(count(config('changelog.releases.uk')) / 20);

        $this->get('/changelog.en.html')
            ->assertStatus(200)
            ->assertSee("Current version {$version}")
            ->assertSee('Trainer substitutions')
            ->assertSee('?page=2', false)
            ->assertDontSee('Initial application baseline');

        $this->get("/changelog.en.html?page={$englishLastPage}")
            ->assertStatus(200)
            ->assertSee('Initial application baseline');

        $this->get('/changelog.ua.html')
            ->assertStatus(200)
            ->assertSee("Поточна версія {$version}")
            ->assertSee('Заміни тренерів')
            ->assertSee('?page=2', false)
            ->assertDontSee('Початкова база застосунку');

        $this->get("/changelog.ua.html?page={$ukrainianLastPage}")
            ->assertStatus(200)
            ->assertSee('Початкова база застосунку');
    }
}
