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

    public function test_passes_prices_help_explains_customer_pass_normalization(): void
    {
        $this->get(route('help.show', 'passes-prices', false))
            ->assertStatus(200)
            ->assertSee('Як вручну нормалізувати записи клієнта', false)
            ->assertSee('Нормалізувати записи', false)
            ->assertSee('попередній перегляд', false)
            ->assertSee('Застосувати нормалізацію', false)
            ->assertSee('Статус самого запису не змінюється', false)
            ->assertSee('assets/help/screenshots/customer-pass-normalization.png', false);
    }

    public function test_passes_prices_help_explains_manual_pass_payment_tracking(): void
    {
        $this->get(route('help.show', 'passes-prices', false))
            ->assertStatus(200)
            ->assertSee('Як позначати оплату абонемента', false)
            ->assertSee('Оплачено готівкою', false)
            ->assertSee('Каса готівки в студії', false)
            ->assertSee('Не оплачено', false)
            ->assertSee('відфільтруйте історію за потрібною локацією', false)
            ->assertDontSee('customer_purchases', false)
            ->assertDontSee('endpoint', false);
    }

    public function test_passes_prices_help_explains_freeze_and_day_adjustments(): void
    {
        $this->get(route('help.show', 'passes-prices', false))
            ->assertStatus(200)
            ->assertSee('Як заморозити абонемент клієнта', false)
            ->assertSee('Заморозити', false)
            ->assertSee('Розморозити', false)
            ->assertSee('стільки календарних днів, скільки абонемент був заморожений', false)
            ->assertSee('Як додати або зняти дні дії абонемента', false)
            ->assertSee('Як додати або зняти заняття в абонементі', false)
            ->assertSee('не змінює загальний строк від покупки', false)
            ->assertSee('assets/help/screenshots/class-pass-freeze.png', false)
            ->assertDontSee('freezed', false)
            ->assertDontSee('reservation', false);
    }

    public function test_help_explains_studio_problem_moments_and_trainer_badges(): void
    {
        $this->get(route('help.show', 'passes-prices', false))
            ->assertStatus(200)
            ->assertSee('Проблемні моменти на головному екрані', false)
            ->assertSee('неоплачені активні абонементи', false)
            ->assertSee('записи без резерву в абонементі', false)
            ->assertSee('заморожені абонементи', false)
            ->assertSee('assets/help/screenshots/studio-problems.png', false);

        $this->get(route('help.show', 'trainers', false))
            ->assertStatus(200)
            ->assertSee('Як перевірити записи тренера без резерву', false)
            ->assertSee('бейдж із кількістю записів', false)
            ->assertSee('assets/help/screenshots/trainer-unreserved-bookings.png', false)
            ->assertDontSee('CustomerClassPassReservation', false)
            ->assertDontSee('customer_class_pass', false);
    }

    public function test_customers_help_explains_customer_import_and_export(): void
    {
        $this->get(route('help.show', 'customers-bookings', false))
            ->assertStatus(200)
            ->assertSee('Як імпортувати або експортувати клієнтів', false)
            ->assertSee('Подивитися приклад', false)
            ->assertSee('name, phone, email', false)
            ->assertSee('Телефон порівнюється за цифрами', false)
            ->assertSee('Вже існує', false)
            ->assertSee('assets/help/screenshots/customer-import.png', false)
            ->assertDontSee('tenant', false)
            ->assertDontSee('endpoint', false);
    }

    public function test_customers_help_explains_customer_creation_and_booking_as_separate_actions(): void
    {
        $this->get(route('help.show', 'customers-bookings', false))
            ->assertStatus(200)
            ->assertSee('Дії в цій темі', false)
            ->assertSee('Як додати клієнта вручну', false)
            ->assertSee('Це саме створення клієнта в базі студії', false)
            ->assertSee('Натисніть Додати клієнта', false)
            ->assertSee('Як записати людину на групове заняття', false)
            ->assertSee('Як створити індивідуальне заняття або оренду', false)
            ->assertSee('Що зміниться в Ladna', false)
            ->assertSee('assets/help/screenshots/customers.png', false)
            ->assertDontSee('manual booking', false)
            ->assertDontSee('как добавить клиента', false);
    }

    public function test_help_documents_studio_class_cancellation_flow(): void
    {
        $this->get(route('help.show', 'schedule', false))
            ->assertStatus(200)
            ->assertSee('Як скасувати заняття з вини студії', false)
            ->assertSee('Скасувати заняття', false)
            ->assertSee('Відновити заняття', false)
            ->assertSee('Як відмічати відвідування після заняття', false)
            ->assertSee('Як переглянути історію занять', false)
            ->assertSee('Як замінити тренера на занятті', false)
            ->assertSee('Минулі заняття можна виправити тільки за останні два дні', false)
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

    public function test_start_help_explains_dashboard_rules_and_assistant(): void
    {
        $this->get(route('help.show', 'start', false))
            ->assertStatus(200)
            ->assertSee('Як читати головний екран студії', false)
            ->assertSee('Проблемні моменти', false)
            ->assertSee('Як налаштувати правила студії', false)
            ->assertSee('Як користуватися Ladna асистентом', false)
            ->assertSee('Асистент працює тільки в межах конкретної студії', false)
            ->assertSee('Не передавайте в чат секрети, токени, паролі або платіжні дані', false)
            ->assertSee('assets/help/screenshots/studio-dashboard.png', false);
    }

    public function test_integrations_help_explains_api_mcp_payments_and_ladna_tariff(): void
    {
        $this->get(route('help.show', 'integrations-payments', false))
            ->assertStatus(200)
            ->assertSee('Онлайн-оплати', false)
            ->assertSee('Заявки з сайту', false)
            ->assertSee('Як працює історія оплат клієнтів', false)
            ->assertSee('Як підключати сайт через API або MCP', false)
            ->assertSee('Де дивитися тариф і оплату Ladna', false)
            ->assertSee('Ключ працює тільки в межах цієї студії', false)
            ->assertDontSee('Bearer', false)
            ->assertDontSee('tenant', false);
    }

    public function test_public_pages_help_explains_studio_landing_maps_and_support_links(): void
    {
        $this->get(route('help.show', 'public-pages', false))
            ->assertStatus(200)
            ->assertSee('Публічна сторінка, розклад, прайс і QR-посилання', false)
            ->assertSee('одна публічна сторінка за коротким посиланням', false)
            ->assertSee('Скопіюйте посилання на сторінку студії', false)
            ->assertSee('Як налаштувати вигляд сторінки студії', false)
            ->assertSee('слоган студії', false)
            ->assertSee('Instagram, Telegram, Viber або WhatsApp', false)
            ->assertSee('Є питання - звʼяжіться з назвою студії', false)
            ->assertSee('Google Maps embed URL', false)
            ->assertSee('assets/help/screenshots/public-studio-page.png', false)
            ->assertSee('assets/help/screenshots/public-links-qr.png', false)
            ->assertDontSee('endpoint', false)
            ->assertDontSee('tenant', false);
    }

    public function test_public_footer_links_to_help(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('href="'.route('help.index').'"', false);
    }
}
