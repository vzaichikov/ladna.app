<?php

namespace Tests\Feature;

use App\Support\OwnerHelpIndex;
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

    public function test_booking_help_explains_timely_cancellation_returns_session_for_customer_and_staff(): void
    {
        foreach (['schedule', 'customers-bookings'] as $slug) {
            $this->get(route('help.show', $slug, false))
                ->assertStatus(200)
                ->assertSee('команди студії', false)
                ->assertSee('клієнта', false)
                ->assertSee('повертає заняття', false);
        }
    }

    public function test_events_help_explains_the_complete_owner_flow(): void
    {
        $this->get(route('help.show', 'events', false))
            ->assertOk()
            ->assertSee('Події та квитки', false)
            ->assertSee('Подія не є заняттям із розкладу', false)
            ->assertSee('картку клієнта', false)
            ->assertSee('обрані зали стають недоступними для занять і оренди', false)
            ->assertSee('Наявні записи не скасовуються автоматично', false)
            ->assertSee('Копіювати посилання', false)
            ->assertSee('Early bird', false)
            ->assertSee('30 хвилин', false)
            ->assertSee('один QR-код на кожне місце', false)
            ->assertSee('Надіслати повторно', false)
            ->assertSee('не повертає гроші через платіжний сервіс автоматично', false)
            ->assertSee('Сканер працює тільки онлайн', false)
            ->assertSee('двом телефонам одночасно прийняти той самий квиток', false)
            ->assertSee('assets/help/screenshots/events-list.png', false)
            ->assertSee('assets/help/screenshots/event-editor.png', false)
            ->assertSee('assets/help/screenshots/event-tickets.png', false)
            ->assertSee('assets/help/screenshots/public-event-page.png', false)
            ->assertSee('assets/help/screenshots/event-ticket-scanner.png', false)
            ->assertDontSee('tenant', false)
            ->assertDontSee('callback', false)
            ->assertDontSee('lockForUpdate', false)
            ->assertDontSee('DTO', false)
            ->assertDontSee('hash', false)
            ->assertDontSee('CRM', false);
    }

    public function test_events_help_screenshots_exist(): void
    {
        foreach (config('help.pages.events.screenshots') as $screenshot) {
            $this->assertFileExists(public_path($screenshot['path']));
        }
    }

    public function test_help_screenshot_catalog_has_no_missing_or_duplicate_files(): void
    {
        $configuredPaths = $this->configuredScreenshotPaths();
        $pathsByHash = [];

        foreach ($configuredPaths as $path) {
            $absolutePath = public_path($path);

            $this->assertFileExists($absolutePath);

            $hash = hash_file('sha256', $absolutePath);
            $this->assertArrayNotHasKey(
                $hash,
                $pathsByHash,
                sprintf('%s duplicates %s.', $path, $pathsByHash[$hash] ?? ''),
            );

            $pathsByHash[$hash] = $path;
        }

        $diskPaths = array_map(
            fn (string $absolutePath): string => 'assets/help/screenshots/'.basename($absolutePath),
            glob(public_path('assets/help/screenshots/*.png')) ?: [],
        );
        sort($diskPaths);

        $this->assertSame($configuredPaths, $diskPaths);
    }

    public function test_owner_help_search_finds_events_workflows(): void
    {
        foreach ([
            'як створити подію і продати квитки',
            'як налаштувати Early bird',
            'як сканувати QR-квиток на вході',
            'як повернути гроші за скасовану подію',
            'how to create an event and sell tickets',
        ] as $question) {
            $result = app(OwnerHelpIndex::class)->search($question, 1);

            $this->assertSame('events', $result[0]['slug'] ?? null, $question);
        }
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
            ->assertSee('списано, зарезервовано або повернуто', false)
            ->assertSee('assets/help/screenshots/customer-pass-normalization.png', false);
    }

    public function test_passes_prices_help_explains_audited_trial_pass_override(): void
    {
        $this->get(route('help.show', 'passes-prices', false))
            ->assertOk()
            ->assertSee('Як видати пробний абонемент як аудитований виняток', false)
            ->assertSee('Видавати куплені абонементи', false)
            ->assertSee('Редагувати куплені абонементи', false)
            ->assertSee('Коментар до винятку', false)
            ->assertSee('працює лише для ручної видачі', false)
            ->assertSee('автора, час і обовʼязковий коментар', false)
            ->assertSee('assets/help/screenshots/trial-pass-override.png', false);

        $result = app(OwnerHelpIndex::class)->search('як вручну видати пробний абонемент як виняток', 1);

        $this->assertSame('passes-prices', $result[0]['slug'] ?? null);
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
            ->assertSee('неоплачені та частково оплачені абонементи', false)
            ->assertSee('частково оплачені абонементи', false)
            ->assertSee('Використання, заморозка або завершення строку дії абонемента не погашає залишок оплати', false)
            ->assertSee('Скасовані абонементи боргами не рахуються', false)
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

    public function test_trainers_help_uses_screenshots_for_each_documented_workflow(): void
    {
        $this->get(route('help.show', 'trainers', false))
            ->assertOk()
            ->assertSee('assets/help/screenshots/trainer-types.png?v=2026-07-30', false)
            ->assertSee('assets/help/screenshots/trainer-types.png', false)
            ->assertSee('assets/help/screenshots/trainer-editor.png', false)
            ->assertSee('assets/help/screenshots/trainer-private-timeframes.png', false)
            ->assertSee('assets/help/screenshots/trainer-unreserved-bookings.png', false)
            ->assertSee('assets/help/screenshots/trainer-permissions.png', false)
            ->assertSee('assets/help/screenshots/trainer-substitution.png', false)
            ->assertSee('assets/help/screenshots/activity-logs.png', false);
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

    public function test_schedule_help_explains_internal_classes_and_multiple_trainers(): void
    {
        $this->get(route('help.show', 'schedule', false))
            ->assertStatus(200)
            ->assertSee('Як додати закрите заняття для тренерів', false)
            ->assertSee('Загальні налаштування', false)
            ->assertSee('Основного тренера', false)
            ->assertSee('Додаткові тренери', false)
            ->assertSee('займає зал і час Основного тренера та всіх Додаткових тренерів', false)
            ->assertSee('Клієнти, місткість, записи, відвідування, абонементи й оплати тут не використовуються', false)
            ->assertSee('не потрапляє у звіт для розрахунку роботи тренерів', false)
            ->assertSee('не позначає цей проміжок як невідому присутність', false)
            ->assertSee('assets/help/screenshots/internal-class-modal.png', false)
            ->assertDontSee('internal_class', false)
            ->assertDontSee('additional_trainer_ids', false)
            ->assertDontSee('tenant', false)
            ->assertDontSee('payload', false);
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
            ->assertSee('JPEG, PNG або WebP до 2 МБ', false)
            ->assertSee('вставте скріншот із буфера або перетягніть файл', false)
            ->assertSee('вибрана модель асистента не підтримує аналіз зображень', false)
            ->assertSee('Не передавайте в чат секрети, токени, паролі або платіжні дані', false)
            ->assertSee('assets/help/screenshots/assistant-image.png', false)
            ->assertSee('assets/help/screenshots/studio-dashboard.png', false);

        $result = app(OwnerHelpIndex::class)->search('як надіслати скріншот асистенту', 1);

        $this->assertSame('start', $result[0]['slug'] ?? null);
    }

    public function test_start_help_explains_room_activity_direction_restrictions(): void
    {
        $this->get(route('help.show', 'start', false))
            ->assertStatus(200)
            ->assertSee('Якщо не вибрати жодного напряму, зал підходить для всіх активних напрямів студії', false)
            ->assertSee('в усіх варіантах публічного розкладу Ladna пропонує тільки зали', false)
            ->assertSee('сумісні з напрямом заняття', false)
            ->assertSee('Оренда залу та закриті заняття цим обмеженням не підпорядковуються', false)
            ->assertSee('уже створені заняття та їхня історія залишаються на місці', false)
            ->assertSee('несумісна активна серія отримує попередження й не створює нові заняття', false)
            ->assertSee('assets/help/screenshots/room-activity-directions.png', false)
            ->assertDontSee('room_activity_direction', false)
            ->assertDontSee('endpoint', false)
            ->assertDontSee('tenant', false);
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
            ->assertSee('У блоці Посилання відкрийте QR та посилання', false)
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

    public function test_public_pages_help_explains_opt_in_group_booking_popup(): void
    {
        $this->get(route('help.show', 'public-pages', false))
            ->assertOk()
            ->assertSee('Як увімкнути запис у вікні розкладу', false)
            ->assertSee('Загальні налаштування → Вигляд розкладу', false)
            ->assertSee('Відкривати запис на групові заняття у вікні', false)
            ->assertSee('налаштування зберігається окремо для кожного вигляду', false)
            ->assertSee('Новий сценарій не вмикається автоматично', false)
            ->assertSee('підтвердження відкриється на окремій сторінці', false)
            ->assertSee('assets/help/screenshots/public-schedule-booking-popup-settings.png', false);
    }

    public function test_notification_settings_help_explains_independent_customer_and_trainer_channels(): void
    {
        $this->get(route('help.show', 'notifications', false))
            ->assertStatus(200)
            ->assertSee('Налаштування сповіщень', false)
            ->assertSee('Сповіщення клієнтам', false)
            ->assertSee('Вихідні сповіщення клієнтам зараз надсилаються тільки через SMS', false)
            ->assertSee('тихі години для нього не застосовуються', false)
            ->assertSee('Скасування заняття студією', false)
            ->assertSee('Telegram-бота клієнтів не змінює SMS-сценарії', false)
            ->assertSee('Сповіщення тренерам', false)
            ->assertSee('через загального бота Ladna', false)
            ->assertSee('Для індивідуального заняття тренер отримує повідомлення про кожне бронювання', false)
            ->assertSee('лише після першого активного бронювання', false)
            ->assertSee('всі записані клієнти скасували свої записи', false)
            ->assertSee('Новий сценарій скасування початково вимкнений', false)
            ->assertSee('assets/help/screenshots/notification-settings-customers.png', false)
            ->assertSee('assets/help/screenshots/notification-settings-trainers.png', false)
            ->assertDontSee('endpoint', false)
            ->assertDontSee('database', false)
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
            ->assertSee('assets/help/screenshots/room-camera-settings.png', false)
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
            ->assertSee('автоматично нараховану зарплату', false)
            ->assertSee('Як налаштувати автоматичний розрахунок зарплати', false)
            ->assertSee('Мінімум людей 2', false)
            ->assertSee('Стала ставка розподіляється за календарними днями', false)
            ->assertSee('assets/help/screenshots/salary-models.png', false)
            ->assertSee('assets/help/screenshots/trainer-report.png', false);
    }

    public function test_salary_models_help_explains_every_model_with_real_studio_examples(): void
    {
        $this->get(route('help.show', 'salary-models', false))
            ->assertStatus(200)
            ->assertSee('Налаштування зарплати тренерів', false)
            ->assertSee('Налаштування студії → Налаштування зарплати → Створити модель', false)
            ->assertSee('Стала ставка за місяць, тиждень або день', false)
            ->assertSee('Стала сума за заняття', false)
            ->assertSee('Ставка за людину: як одну людину рахувати як дві', false)
            ->assertSee('2 × 120 = 240 грн', false)
            ->assertSee('База + люди понад поріг', false)
            ->assertSee('500 + 2 × 80 = 660 грн', false)
            ->assertSee('Погодинно + люди понад поріг', false)
            ->assertSee('90 хв × 400 ÷ 60 = 600 грн', false)
            ->assertSee('Діапазони відвідуваності', false)
            ->assertSee('від 0 до 2 людей — 350 грн, від 3 до 5 — 500 грн, від 6 і більше — 700 грн', false)
            ->assertSee('Оплачувати заняття без людей', false)
            ->assertSee('Фактичних записів', false)
            ->assertSee('Враховано людей', false)
            ->assertSee('Стала ставка від локації не залежить', false)
            ->assertSee('не виплачує гроші тренеру, не створює платіж і не записує витрату студії автоматично', false)
            ->assertSee('assets/help/screenshots/salary-models.png', false)
            ->assertSee('assets/help/screenshots/trainer-report.png', false);

        foreach ([
            'якщо на занятті одна людина рахувати її як дві',
            'погодинна ставка і надбавка за кожну наступну людину',
            'стала сума якщо на занятті менше трьох людей',
        ] as $question) {
            $result = app(OwnerHelpIndex::class)->search($question, 1);

            $this->assertSame('salary-models', $result[0]['slug'] ?? null, $question);
        }
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

    /**
     * @return list<string>
     */
    private function configuredScreenshotPaths(): array
    {
        $paths = [];
        $pages = config('help.pages');

        array_walk_recursive($pages, function (mixed $value) use (&$paths): void {
            if (is_string($value) && str_starts_with($value, 'assets/help/screenshots/')) {
                $paths[] = $value;
            }
        });

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }
}
