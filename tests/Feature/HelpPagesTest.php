<?php

namespace Tests\Feature;

use Tests\TestCase;

class HelpPagesTest extends TestCase
{
    public function test_help_index_is_public_and_links_to_all_owner_pages(): void
    {
        $response = $this->get('/help');

        $response->assertStatus(200);
        $response->assertSee('Допомога для власниці студії', false);
        $response->assertSee('Як все повʼязано', false);

        foreach (array_keys(config('help.pages')) as $slug) {
            $response->assertSee(route('help.show', $slug, false), false);
        }
    }

    public function test_help_pages_are_public_and_render_plain_owner_instructions(): void
    {
        foreach (config('help.pages') as $slug => $page) {
            $response = $this->get(route('help.show', $slug, false));

            $response->assertStatus(200);
            $response->assertSee($page['title'], false);
            $response->assertSee('Що побачите в розділі', false);
            $response->assertSee('assets/help/screenshots/', false);
            $response->assertDontSee('tenant', false);
            $response->assertDontSee('Bearer', false);
            $response->assertDontSee('CRM', false);
        }
    }

    public function test_unknown_help_page_returns_404(): void
    {
        $this->get('/help/not-a-page')->assertNotFound();
    }

    public function test_passes_prices_help_explains_both_validity_terms(): void
    {
        $this->get(route('help.show', 'passes-prices', false))
            ->assertStatus(200)
            ->assertSee('Загальний строк дії', false)
            ->assertSee('Строк дії з першого заняття', false)
            ->assertSee('навіть тоді, коли заняття ще залишилися або клієнт ще не прийшов на перше заняття', false);
    }

    public function test_passes_prices_help_explains_class_pass_segments(): void
    {
        $this->get(route('help.show', 'passes-prices', false))
            ->assertStatus(200)
            ->assertSee('Сегменти абонементів', false)
            ->assertSee('візуальне групування в адмінці та публічному прайсі', false)
            ->assertSee('Створіть або відредагуйте абонемент і виберіть сегмент у формі', false)
            ->assertSee('активні абонементи сегмента будуть показані окремою групою', false)
            ->assertDontSee('class_pass_segment', false)
            ->assertDontSee('sync', false);
    }

    public function test_help_documents_studio_class_cancellation_flow(): void
    {
        $this->get(route('help.show', 'schedule', false))
            ->assertStatus(200)
            ->assertSee('Як скасувати заняття з вини студії', false)
            ->assertSee('Скасувати заняття', false)
            ->assertSee('Відновити заняття', false)
            ->assertSee('Як створити заняття на заміну', false)
            ->assertSee('assets/help/screenshots/class-cancellation-confirm.png', false)
            ->assertSee('assets/help/screenshots/manual-class-modal.png', false);

        $this->get(route('help.show', 'passes-prices', false))
            ->assertStatus(200)
            ->assertSee('Що стається з абонементом, коли заняття скасовує студія', false)
            ->assertSee('Повернути скасоване заняття', false)
            ->assertSee('бонусних занять', false)
            ->assertSee('Продовжити абонемент', false)
            ->assertDontSee('Повернути X занять', false)
            ->assertDontSee('Додати X днів', false);

        $this->get(route('help.show', 'start', false))
            ->assertStatus(200)
            ->assertSee('assets/help/screenshots/class-pass-rules.png', false)
            ->assertSee('Правила абонементів', false);
    }

    public function test_public_footer_links_to_help(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('href="'.route('help.index').'"', false);
    }
}
