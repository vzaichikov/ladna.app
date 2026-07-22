<?php

namespace Tests\Feature;

use Tests\TestCase;

class HelpPagesTest extends TestCase
{
    public function test_help_index_is_public_and_links_to_all_owner_pages(): void
    {
        $response = $this->get(route('help.index', absolute: false));

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
        $this->get('/app/help/not-a-page')->assertNotFound();
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
            ->assertSee('Як фіксувати оплату або передоплату абонемента', false)
            ->assertSee('Оплачено сьогодні', false)
            ->assertSee('1000 для 1000 грн, а не 100000', false)
            ->assertSee('Частково оплачено', false)
            ->assertSee('Сума оплати', false)
            ->assertSee('Зафіксувати оплату', false)
            ->assertSee('Каса готівки в студії', false)
            ->assertSee('Не оплачено', false)
            ->assertSee('assets/help/screenshots/class-pass-payment.png', false)
            ->assertSee('відфільтруйте історію за потрібною локацією', false)
            ->assertDontSee('customer_purchases', false)
            ->assertDontSee('endpoint', false);
    }

    public function test_passes_prices_help_explains_cancelling_mistaken_customer_pass(): void
    {
        $this->get(route('help.show', 'passes-prices', false))
            ->assertStatus(200)
            ->assertSee('Як скасувати помилково виданий абонемент', false)
            ->assertSee('поставте статус Скасовано', false)
            ->assertSee('зніміть Активний', false)
            ->assertSee('заповніть Закрито', false)
            ->assertSee('вона залишиться в історії платежів', false)
            ->assertSee('не стирається з історії студії', false)
            ->assertSee('не буде підбиратися для нових записів', false)
            ->assertSee('Нормалізувати записи', false)
            ->assertDontSee('database', false)
            ->assertDontSee('destroy', false);
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
            ->assertSee('частково оплачені абонементи', false)
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

    public function test_trainers_help_explains_private_lesson_timeframes(): void
    {
        $this->get(route('help.show', 'trainers', false))
            ->assertStatus(200)
            ->assertSee('Як працюють індивідуальні таймфрейми', false)
            ->assertSee('тренер сам позначив, коли він готовий вести індивідуальне заняття', false)
            ->assertSee('Таймфрейм не бронює зал', false)
            ->assertSee('Якщо локації в картці тренера не вибрані', false)
            ->assertSee('білий час можна позначити, жовтий показує заняття тренера, сірий недоступний', false)
            ->assertSee('адміністратор може вручну не враховувати таймфрейми', false)
            ->assertSee('assets/help/screenshots/trainer-private-timeframes.png', false)
            ->assertDontSee('endpoint', false)
            ->assertDontSee('database', false);
    }

    public function test_trainers_help_explains_private_lesson_activity_directions(): void
    {
        $this->get(route('help.show', 'trainers', false))
            ->assertStatus(200)
            ->assertSee('Як обмежити тренера по напрямах', false)
            ->assertSee('не вибрано жодного напряму', false)
            ->assertSee('може вести всі активні напрями студії', false)
            ->assertSee('спочатку просять вибрати Напрям', false)
            ->assertSee('показує тільки сумісні послуги й тренерів', false)
            ->assertSee('Формат індивідуального заняття без власного напряму', false)
            ->assertSee('це обходить тільки таймфрейми, а не сумісність тренера з напрямом', false)
            ->assertDontSee('trainer_activity_direction', false)
            ->assertDontSee('endpoint', false);
    }

    public function test_customers_help_explains_customer_import_and_export(): void
    {
        $this->get(route('help.show', 'customers-bookings', false))
            ->assertStatus(200)
            ->assertSee('Як імпортувати або експортувати клієнтів', false)
            ->assertSee('Подивитися приклад', false)
            ->assertSee('name, phone, email', false)
            ->assertSee('Телефон порівнюється за цифрами', false)
            ->assertSee('скільки оновлено', false)
            ->assertSee('Порожній телефон або email у файлі не стирає наявний контакт', false)
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
            ->assertSee('Як додати оренду залу на довільний час', false)
            ->assertSee('Пряма оренда на довільний час', false)
            ->assertSee('не привʼязує запис до абонемента', false)
            ->assertSee('не може накладатися на іншу подію в цьому ж залі', false)
            ->assertSee('Готівка внесена', false)
            ->assertSee('assets/help/screenshots/rent-anytime-modal.png', false)
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

    public function test_help_documents_closed_class_corrections_cashflow_and_sensitive_trainer_rights(): void
    {
        $this->get(route('help.show', 'schedule', false))
            ->assertStatus(200)
            ->assertSee('Як виправити записи у вже завершеному занятті', false)
            ->assertSee('Розблокувати виправлення', false)
            ->assertSee('повернути заняття в абонемент або залишити заняття списаним', false)
            ->assertSee('Готівкова оплата, яка вже була привʼязана до помилкового запису, не змінюється автоматично', false)
            ->assertSee('assets/help/screenshots/closed-class-corrections.png', false);

        $this->get(route('help.show', 'integrations-payments', false))
            ->assertStatus(200)
            ->assertSee('Як виправляти готівкові оплати і вести касу', false)
            ->assertSee('Внесення готівки', false)
            ->assertSee('Вилучення власником', false)
            ->assertSee('Онлайн-оплати, платежі через платіжний сервіс і фіскалізовані платежі не редагуються вручну', false)
            ->assertSee('assets/help/screenshots/payments-period.png', false);

        $this->get(route('help.show', 'trainers', false))
            ->assertStatus(200)
            ->assertSee('кольоровий рівень чутливості', false)
            ->assertSee('Коригувати закриті заняття', false)
            ->assertSee('дозволяє прибрати майбутній запис після закриття строку скасування', false)
            ->assertSee('Керувати касою студії', false)
            ->assertSee('всі інші ролі отримують чутливі дії тільки явно', false)
            ->assertSee('assets/help/screenshots/trainer-permissions.png', false)
            ->assertDontSee('correct_closed_classes', false)
            ->assertDontSee('manage_studio_cashflow', false);
    }

    public function test_help_explains_locked_booking_removal_and_returned_pass_reactivation(): void
    {
        $this->get(route('help.show', 'schedule', false))
            ->assertStatus(200)
            ->assertSee('Як прибрати запис після закриття строку скасування', false)
            ->assertSee('Заняття -&gt; картка заняття -&gt; запис клієнта -&gt; Видалити', false)
            ->assertSee('Коригувати закриті заняття', false)
            ->assertSee('автоматично стає активним', false)
            ->assertDontSee('correct_closed_classes', false)
            ->assertDontSee('used_up', false);

        $this->get(route('help.show', 'passes-prices', false))
            ->assertStatus(200)
            ->assertSee('автоматично робить його активним з доступним заняттям', false)
            ->assertSee('Скасований, прострочений або заморожений абонемент самостійно не відкривається', false)
            ->assertDontSee('used_up', false);
    }

    public function test_start_help_explains_dashboard_rules_and_assistant(): void
    {
        $this->get(route('help.show', 'start', false))
            ->assertStatus(200)
            ->assertSee('Як читати головний екран студії', false)
            ->assertSee('Проблемні моменти', false)
            ->assertSee('Як налаштувати правила студії та публічну оферту', false)
            ->assertSee('погодження перед онлайн-покупкою залишається повʼязаним тільки з правилами студії', false)
            ->assertSee('assets/help/screenshots/public-legal-documents.png', false)
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

    public function test_payments_help_explains_periods_operational_expenses_and_cash_audit(): void
    {
        $this->get(route('help.show', 'integrations-payments', false))
            ->assertStatus(200)
            ->assertSee('від першого дня поточного місяця до сьогодні', false)
            ->assertSee('Поточний Баланс каси за локаціями не обмежується вибраними датами', false)
            ->assertSee('Як записувати операційні витрати студії', false)
            ->assertSee('Для Готівки з каси локація обовʼязкова', false)
            ->assertSee('Банківська картка, Банківський переказ та Інше не змінюють касу', false)
            ->assertSee('Вилучення власником не є витратою студії', false)
            ->assertSee('повернення в ту саму касу', false)
            ->assertSee('assets/help/screenshots/payments-period.png', false)
            ->assertSee('assets/help/screenshots/operational-expenses.png', false);
    }

    public function test_public_pages_help_explains_studio_landing_maps_and_support_links(): void
    {
        $this->get(route('help.show', 'public-pages', false))
            ->assertStatus(200)
            ->assertSee('Публічна сторінка, розклад, прайс і QR-посилання', false)
            ->assertSee('одна публічна сторінка за коротким посиланням', false)
            ->assertSee('Скопіюйте посилання на сторінку студії', false)
            ->assertSee('Як додати правила студії та договір публічної оферти', false)
            ->assertSee('Загальні налаштування -&gt; Правила та оферта', false)
            ->assertSee('посилання тільки на цей документ не показується', false)
            ->assertSee('повернутися саме до сторінки студії, розкладу або прайсу', false)
            ->assertSee('Під час онлайн-купівлі абонемента клієнт, як і раніше, погоджується з правилами студії', false)
            ->assertSee('відповідні посилання зʼявляються у публічному розкладі та прайсі незалежно одне від одного', false)
            ->assertSee('Як налаштувати вигляд сторінки студії', false)
            ->assertSee('слоган студії', false)
            ->assertSee('Телефон вводиться як звичайний номер', false)
            ->assertSee('Є питання - звʼяжіться з назвою студії', false)
            ->assertSee('Як отримати код від Google', false)
            ->assertSee('Вбудувати карту (Embed a map)', false)
            ->assertSee('Копіювати HTML (Copy HTML)', false)
            ->assertSee('Ladna сама візьме потрібну адресу карти', false)
            ->assertSee('id="help-section-public-pages-google-maps-code"', false)
            ->assertSee('assets/help/screenshots/location-google-maps-code.png', false)
            ->assertSee('assets/help/screenshots/public-studio-page.png', false)
            ->assertSee('assets/help/screenshots/public-links-qr.png', false)
            ->assertSee('assets/help/screenshots/public-legal-documents.png', false)
            ->assertDontSee('endpoint', false)
            ->assertDontSee('public_offer_html', false)
            ->assertDontSee('return_to', false)
            ->assertDontSee('tenant', false);
    }

    public function test_install_web_app_help_explains_android_and_ios_pwa_installation(): void
    {
        $this->get(route('help.show', 'install-web-app', false))
            ->assertStatus(200)
            ->assertSee('Як встановити Ladna на телефон як веб-застосунок', false)
            ->assertSee('Як додати Ladna в Chrome на Android', false)
            ->assertSee('Встановити застосунок', false)
            ->assertSee('Як додати Ladna в Safari на iPhone', false)
            ->assertSee('На екран Додому', false)
            ->assertSee('Як додати Ladna в Chrome на iPhone', false)
            ->assertSee('скористайтесь Safari', false)
            ->assertSee('Це не окремий Android-застосунок із магазину', false)
            ->assertSee('застосунок ще в розробці', false)
            ->assertSee('assets/help/screenshots/studio-dashboard.png', false)
            ->assertDontSee('Flutter', false)
            ->assertDontSee('endpoint', false)
            ->assertDontSee('tenant', false);
    }

    public function test_reports_cameras_help_explains_rtsp_and_people_counter(): void
    {
        $this->get(route('help.show', 'reports-cameras', false))
            ->assertStatus(200)
            ->assertSee('Звіти, камери та People Counter', false)
            ->assertSee('Що показує розділ Звіти', false)
            ->assertSee('Як увімкнути RTSP-камери для студії', false)
            ->assertSee('Підтримка RTSP-камер', false)
            ->assertSee('службових кімнатах', false)
            ->assertSee('не потрапляє в розклад, записи, фільтри чи звіти занять', false)
            ->assertSee('Як читати звіт People Counter', false)
            ->assertSee('Різниця рахується між Записано та Виявлено', false)
            ->assertSee('віднімається один тренер', false)
            ->assertSee('assets/help/screenshots/rooms.png', false)
            ->assertSee('assets/help/screenshots/studio-dashboard.png', false)
            ->assertDontSee('endpoint', false)
            ->assertDontSee('payload', false)
            ->assertDontSee('database', false);
    }

    public function test_trainer_report_help_explains_completed_work_private_details_and_financial_access(): void
    {
        $this->get(route('help.show', 'reports-cameras', false))
            ->assertStatus(200)
            ->assertSee('Майбутні й скасовані заняття до звіту по тренерах не потрапляють', false)
            ->assertSee('людей на групових заняттях і людей на індивідуальних заняттях', false)
            ->assertSee('Натисніть на кількість індивідуальних занять тренера', false)
            ->assertSee('Керувати касою студії також бачить суму одного заняття', false)
            ->assertSee('Суму не вказано', false)
            ->assertSee('Кнопка з іконкою фільтра', false)
            ->assertSee('Моделі оплати тренерів поки має статус Не налаштовано', false)
            ->assertSee('assets/help/screenshots/trainer-report.png', false);
    }

    public function test_real_workflows_help_uses_submenu_and_question_pages(): void
    {
        $this->get(route('help.index', [], false))
            ->assertStatus(200)
            ->assertSee('Робочі ситуації: що робити, якщо...', false)
            ->assertSee(route('help.show', 'case-no-show-with-pass', false), false)
            ->assertSee(route('help.show', 'case-new-customer-before-booking', false), false);

        $this->get(route('help.show', 'case-no-show-with-pass', false))
            ->assertStatus(200)
            ->assertSee('Робочі ситуації: що робити, якщо...', false)
            ->assertSee('Що робити, якщо клієнт не прийшов, а заняття має списатися?', false)
            ->assertSee('Шлях у Ladna', false)
            ->assertSee('assets/help/screenshots/classes-calendar.png', false)
            ->assertSee('assets/help/screenshots/active-passes.png', false)
            ->assertDontSee('CRM', false)
            ->assertDontSee('database', false);
    }

    public function test_real_workflows_submenu_is_collapsed_until_question_page_is_opened(): void
    {
        $closedSubmenuPattern = '/<details[^>]*data-help-submenu="real-workflows"[^>]*>/';
        $openSubmenuPattern = '/<details[^>]*data-help-submenu="real-workflows"[^>]*\sopen\b/';

        $indexResponse = $this->get(route('help.index', [], false))
            ->assertStatus(200)
            ->assertSee('Питання в темі', false);

        $this->assertMatchesRegularExpression($closedSubmenuPattern, $indexResponse->getContent());
        $this->assertDoesNotMatchRegularExpression($openSubmenuPattern, $indexResponse->getContent());

        $parentResponse = $this->get(route('help.show', 'real-workflows', false))
            ->assertStatus(200);

        $this->assertMatchesRegularExpression($closedSubmenuPattern, $parentResponse->getContent());
        $this->assertDoesNotMatchRegularExpression($openSubmenuPattern, $parentResponse->getContent());

        $questionResponse = $this->get(route('help.show', 'case-no-show-with-pass', false))
            ->assertStatus(200);

        $this->assertMatchesRegularExpression($openSubmenuPattern, $questionResponse->getContent());
    }

    public function test_real_workflows_help_answers_trainer_questions(): void
    {
        $this->get(route('help.show', 'case-no-show-with-pass', false))
            ->assertStatus(200)
            ->assertSee('Не прийшов/прийшла', false)
            ->assertSee('не видаляйте запис', false)
            ->assertSee('заняття за правилами студії згоріло', false);

        $this->get(route('help.show', 'case-no-show-without-pass', false))
            ->assertStatus(200)
            ->assertSee('не має що списати автоматично', false)
            ->assertSee('видайте відповідний разовий або пакетний абонемент', false)
            ->assertSee('Нормалізувати записи', false);

        $this->get(route('help.show', 'case-walk-in-after-training', false))
            ->assertStatus(200)
            ->assertSee('клієнт прийшов без запису', false)
            ->assertSee('Розблокувати виправлення', false)
            ->assertSee('Додати правильного клієнта', false)
            ->assertSee('статус Відвідано', false);

        $this->get(route('help.show', 'case-new-customer-before-booking', false))
            ->assertStatus(200)
            ->assertSee('якщо клієнта ще немає в базі студії, його треба додати', false)
            ->assertSee('Клієнти -&gt; Додати клієнта -&gt; Зберегти', false)
            ->assertSee('Запис на заняття привʼязується до картки клієнта', false);
    }

    public function test_public_footer_links_to_help(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('href="'.route('help.index').'"', false);
    }
}
