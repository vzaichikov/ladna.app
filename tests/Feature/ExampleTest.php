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
        $response->assertDontSee('id="pricing"', false);
        $response->assertSee('href="'.route('customer.login').'"', false);
        $response->assertSee(__('app.customer_login_cta'));
        $this->assertSame(1, substr_count($response->getContent(), 'href="'.route('login').'"'));
    }

    public function test_landing_language_urls_control_the_visible_locale(): void
    {
        $this->withSession(['locale' => 'uk'])
            ->get('/en')
            ->assertStatus(200)
            ->assertSee('A studio should move with classes, not spreadsheets')
            ->assertSee('No manual visit counting')
            ->assertSee('No lost website or Telegram leads')
            ->assertSee('No Google Sheets schedule chaos')
            ->assertSee('href="'.route('home').'"', false)
            ->assertSessionHas('locale', 'en');

        $this->withSession(['locale' => 'en'])
            ->get('/')
            ->assertStatus(200)
            ->assertSee('Студія працює в ритмі занять, а не таблиць')
            ->assertSee('Без ручного підрахунку відвідувань')
            ->assertSee('Заявки з сайту чи Telegram не губляться')
            ->assertSee('Без розкладу в Google Sheets')
            ->assertSee('href="'.route('home.en').'"', false)
            ->assertSessionHas('locale', 'uk');
    }

    public function test_login_language_urls_control_the_visible_locale(): void
    {
        $this->withSession(['locale' => 'en'])
            ->get(route('login', absolute: false))
            ->assertStatus(200)
            ->assertSee('Увійдіть, щоб керувати розкладом')
            ->assertSee('Вхід для тренерів та власників студії')
            ->assertSee('Вхід для клієнтів')
            ->assertSee('href="'.route('customer.login').'"', false)
            ->assertSee('name="remember" type="checkbox" value="1" checked', false)
            ->assertSee('href="'.route('login.en').'"', false)
            ->assertSessionHas('locale', 'uk');

        $this->withSession(['locale' => 'uk'])
            ->get(route('login.en', absolute: false))
            ->assertStatus(200)
            ->assertSee('Log in to manage schedules')
            ->assertSee('Login for trainers and studio owners')
            ->assertSee('Customer login')
            ->assertSee('href="'.route('customer.login').'"', false)
            ->assertSee('name="remember" type="checkbox" value="1" checked', false)
            ->assertSee('href="'.route('login').'"', false)
            ->assertSessionHas('locale', 'en');
    }

    public function test_changelog_pages_render_release_history(): void
    {
        $version = trim((string) file_get_contents(base_path('VERSION')));
        $englishLatestTitle = config('changelog.releases.en.0.title');
        $ukrainianLatestTitle = config('changelog.releases.uk.0.title');
        $englishLastPage = (int) ceil(count(config('changelog.releases.en')) / 20);
        $ukrainianLastPage = (int) ceil(count(config('changelog.releases.uk')) / 20);

        $this->get('/changelog.en.html')
            ->assertStatus(200)
            ->assertSee("Current version {$version}")
            ->assertSee($englishLatestTitle)
            ->assertSee('?page=2', false)
            ->assertDontSee('Initial application baseline');

        $this->get("/changelog.en.html?page={$englishLastPage}")
            ->assertStatus(200)
            ->assertSee('Initial application baseline');

        $this->get('/changelog.ua.html')
            ->assertStatus(200)
            ->assertSee("Поточна версія {$version}")
            ->assertSee($ukrainianLatestTitle)
            ->assertSee('?page=2', false)
            ->assertDontSee('Початкова база застосунку');

        $this->get("/changelog.ua.html?page={$ukrainianLastPage}")
            ->assertStatus(200)
            ->assertSee('Початкова база застосунку');
    }
}
