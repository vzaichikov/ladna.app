<?php

namespace App\Support;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassStatus;
use App\Enums\ScheduleKind;
use App\Enums\WebsiteLeadStatus;

class DemoStudioFixture
{
    public const AccountSlug = 'ladna-demo';

    public const ShowcaseEventProvider = 'demo_showcase';

    public const ShowcaseMetadataKey = 'demo_showcase_key';

    public const PeopleCounterSampleCount = 14;

    public static function showcaseMetadataKeyPath(): string
    {
        return 'metadata->'.self::ShowcaseMetadataKey;
    }

    /** @return array<string, mixed> */
    public static function account(): array
    {
        return [
            'name' => 'Ladna Demo Studio',
            'slug' => self::AccountSlug,
            'status' => 'active',
            'mode' => 'demo_readonly',
            'default_language' => 'uk',
            'country_code' => 'UA',
            'default_currency' => 'UAH',
            'logo_path' => 'brand/ladna-demo-studio.svg',
            'brand_color' => '#6F4B7A',
            'studio_slogan' => 'Демонстраційний простір для розкладу, записів і абонементів.',
            'timezone' => 'Europe/Kyiv',
            'enabled_schedule_kinds' => ScheduleKindRegistry::allValues(),
            'schedule_kind_colors' => [
                ScheduleKind::GroupClass->value => '#8B6A9B',
                ScheduleKind::PrivateLesson->value => '#C7B4D3',
                ScheduleKind::RoomRental->value => '#D9B8C4',
                ScheduleKind::InternalClass->value => '#D7A94A',
            ],
            'opening_hours' => collect(range(1, 7))->mapWithKeys(fn (int $weekday): array => [
                $weekday => [
                    'enabled' => $weekday <= 6,
                    'opens_at' => '08:00',
                    'closes_at' => $weekday <= 5 ? '21:00' : '18:00',
                ],
            ])->all(),
            'studio_rules_html' => '<p>Це синтетичні демонстраційні дані. Записи, абонементи та суми не належать реальним людям або студії.</p><p>Будь ласка, приходьте за 10 хвилин до початку заняття та повідомляйте про скасування завчасно.</p>',
            'class_pass_cancellation_rules' => [
                'return_sessions_enabled' => true,
                'return_sessions_count' => 1,
                'extend_days_enabled' => false,
                'extend_days_count' => 1,
            ],
            'public_schedule_view' => 'compact_booking',
            'allow_guest_public_booking' => false,
            'allow_rtsp_cameras' => true,
            'enable_people_counter' => true,
            'enable_telegram_alerts' => false,
            'enable_customer_notifications' => false,
            'schedule_generation_weeks' => 8,
            'trainer_private_timeframes_enabled' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function location(): array
    {
        return [
            'name' => 'Демонстраційна локація',
            'slug' => 'demo-location',
            'address' => 'Навчальний простір Ladna, Київ (не фактична адреса)',
            'google_maps_embed_url' => null,
            'phone' => '+380000000001',
            'email' => 'location@ladna-demo.example.test',
            'timezone' => 'Europe/Kyiv',
            'is_active' => true,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function rooms(): array
    {
        return [
            'lavender-hall' => [
                'name' => 'Лавандова зала',
                'description' => 'Простора синтетична зала для групових занять.',
                'capacity' => 14,
                'color' => '#A78AB9',
                'is_active' => true,
                'rtsp_url' => 'rtsp://lavender-hall.ladna-demo.example.test/live',
                'rtsp_enabled' => true,
                'people_counter_capture_delay_seconds' => 0,
            ],
            'plum-studio' => [
                'name' => 'Сливова студія',
                'description' => 'Камерна синтетична зала для персональних занять.',
                'capacity' => 6,
                'color' => '#6F4B7A',
                'is_active' => true,
                'rtsp_url' => 'rtsp://plum-studio.ladna-demo.example.test/live',
                'rtsp_enabled' => true,
                'people_counter_capture_delay_seconds' => 0,
            ],
        ];
    }

    public static function cameraImagePath(string $roomSlug): string
    {
        return match ($roomSlug) {
            'lavender-hall' => 'demo-camera://lavender-hall',
            'plum-studio' => 'demo-camera://plum-studio',
            default => throw new \InvalidArgumentException("Unknown demo camera room [{$roomSlug}]."),
        };
    }

    public static function cameraAssetPath(string $imagePath): ?string
    {
        return match ($imagePath) {
            'demo-camera://lavender-hall' => 'assets/demo/cameras/lavender-hall.jpg',
            'demo-camera://plum-studio' => 'assets/demo/cameras/plum-studio.jpg',
            default => null,
        };
    }

    /** @return array<string, array<string, mixed>> */
    public static function directions(): array
    {
        return [
            'yoga' => ['name' => 'Йога', 'description' => 'Баланс, мобільність і дихання.', 'color' => '#8B6A9B', 'is_active' => true],
            'pilates' => ['name' => 'Пілатес', 'description' => 'Контроль руху та міцний центр.', 'color' => '#A78AB9', 'is_active' => true],
            'barre' => ['name' => 'Barre', 'description' => 'Ритмічне тренування біля станка.', 'color' => '#C18AA6', 'is_active' => true],
            'functional' => ['name' => 'Функціональний тренінг', 'description' => 'Сила, витривалість і координація.', 'color' => '#6F7C9B', 'is_active' => true],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function trainerTypes(): array
    {
        return [
            'trainer' => ['name' => 'Тренер', 'icon' => 'user-round', 'color' => '#6F4B7A', 'is_default' => true, 'sort_order' => 10],
            'senior' => ['name' => 'Старший тренер', 'icon' => 'sparkles', 'color' => '#A78AB9', 'is_default' => false, 'sort_order' => 20],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function trainers(): array
    {
        return [
            'mariia' => ['name' => 'Марія', 'email' => 'mariia@ladna-demo.example.test', 'phone' => '+380000000101', 'trainer_type' => 'senior'],
            'sofiia' => ['name' => 'Софія', 'email' => 'sofiia@ladna-demo.example.test', 'phone' => '+380000000102', 'trainer_type' => 'trainer'],
            'olena' => ['name' => 'Олена', 'email' => 'olena@ladna-demo.example.test', 'phone' => '+380000000103', 'trainer_type' => 'trainer'],
            'iryna' => ['name' => 'Ірина', 'email' => 'iryna@ladna-demo.example.test', 'phone' => '+380000000104', 'trainer_type' => 'senior'],
            'victoriia' => ['name' => 'Вікторія', 'email' => 'victoriia@ladna-demo.example.test', 'phone' => '+380000000105', 'trainer_type' => 'trainer'],
            'nataliia' => ['name' => 'Наталія', 'email' => 'nataliia@ladna-demo.example.test', 'phone' => '+380000000106', 'trainer_type' => 'trainer'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function classTypes(): array
    {
        return [
            'morning-yoga' => ['name' => 'Ранкова йога', 'direction' => 'yoga', 'schedule_kind' => ScheduleKind::GroupClass->value, 'duration' => 60, 'capacity' => 12, 'color' => '#8B6A9B'],
            'pilates-flow' => ['name' => 'Pilates Flow', 'direction' => 'pilates', 'schedule_kind' => ScheduleKind::GroupClass->value, 'duration' => 60, 'capacity' => 12, 'color' => '#A78AB9'],
            'barre-balance' => ['name' => 'Barre Balance', 'direction' => 'barre', 'schedule_kind' => ScheduleKind::GroupClass->value, 'duration' => 55, 'capacity' => 10, 'color' => '#C18AA6'],
            'functional-fit' => ['name' => 'Functional Fit', 'direction' => 'functional', 'schedule_kind' => ScheduleKind::GroupClass->value, 'duration' => 50, 'capacity' => 12, 'color' => '#6F7C9B'],
            'personal-session' => ['name' => 'Персональне заняття', 'direction' => null, 'schedule_kind' => ScheduleKind::PrivateLesson->value, 'duration' => 60, 'capacity' => 1, 'color' => '#C7B4D3'],
            'studio-rental' => ['name' => 'Оренда студії', 'direction' => null, 'schedule_kind' => ScheduleKind::RoomRental->value, 'duration' => 60, 'capacity' => 6, 'color' => '#D9B8C4'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function classPassSegments(): array
    {
        return [
            'group' => ['name' => 'Групові заняття', 'schedule_kind' => ScheduleKind::GroupClass->value, 'directions' => ['yoga', 'pilates', 'barre', 'functional'], 'sort_order' => 10],
            'personal' => ['name' => 'Персональні заняття', 'schedule_kind' => ScheduleKind::PrivateLesson->value, 'directions' => [], 'sort_order' => 20],
            'rental' => ['name' => 'Оренда', 'schedule_kind' => ScheduleKind::RoomRental->value, 'directions' => [], 'sort_order' => 30],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function classPassPlans(): array
    {
        return [
            'trial' => ['name' => 'Пробне заняття', 'segment' => 'group', 'kind' => ScheduleKind::GroupClass->value, 'price' => 25000, 'sessions' => 1, 'validity' => 14, 'total_validity' => 30, 'trial' => true, 'class_types' => ['morning-yoga', 'pilates-flow', 'barre-balance', 'functional-fit'], 'trainer_types' => [], 'rooms' => ['lavender-hall']],
            'group-4' => ['name' => '4 групові заняття', 'segment' => 'group', 'kind' => ScheduleKind::GroupClass->value, 'price' => 140000, 'sessions' => 4, 'validity' => 30, 'total_validity' => 90, 'trial' => false, 'class_types' => ['morning-yoga', 'pilates-flow', 'barre-balance', 'functional-fit'], 'trainer_types' => [], 'rooms' => ['lavender-hall']],
            'group-8' => ['name' => '8 групових занять', 'segment' => 'group', 'kind' => ScheduleKind::GroupClass->value, 'price' => 240000, 'sessions' => 8, 'validity' => 35, 'total_validity' => 120, 'trial' => false, 'class_types' => ['morning-yoga', 'pilates-flow', 'barre-balance', 'functional-fit'], 'trainer_types' => [], 'rooms' => ['lavender-hall']],
            'personal' => ['name' => 'Персональне заняття', 'segment' => 'personal', 'kind' => ScheduleKind::PrivateLesson->value, 'price' => 110000, 'sessions' => 1, 'validity' => 30, 'total_validity' => 60, 'trial' => false, 'class_types' => ['personal-session'], 'trainer_types' => ['trainer', 'senior'], 'rooms' => ['plum-studio']],
            'personal-4' => ['name' => '4 персональні заняття', 'segment' => 'personal', 'kind' => ScheduleKind::PrivateLesson->value, 'price' => 390000, 'sessions' => 4, 'validity' => 45, 'total_validity' => 90, 'trial' => false, 'class_types' => ['personal-session'], 'trainer_types' => ['trainer', 'senior'], 'rooms' => ['plum-studio']],
            'rental' => ['name' => 'Оренда на 60 хвилин', 'segment' => 'rental', 'kind' => ScheduleKind::RoomRental->value, 'price' => 70000, 'sessions' => 1, 'validity' => 30, 'total_validity' => 60, 'trial' => false, 'class_types' => ['studio-rental'], 'trainer_types' => [], 'rooms' => ['plum-studio']],
        ];
    }

    /** @return array<int, array{weekday: int, start_time: string, room: string, class_type: string, trainer: string}> */
    public static function scheduleRows(): array
    {
        return [
            ['weekday' => 1, 'start_time' => '09:00', 'room' => 'lavender-hall', 'class_type' => 'morning-yoga', 'trainer' => 'mariia'],
            ['weekday' => 1, 'start_time' => '18:30', 'room' => 'lavender-hall', 'class_type' => 'pilates-flow', 'trainer' => 'sofiia'],
            ['weekday' => 2, 'start_time' => '10:00', 'room' => 'lavender-hall', 'class_type' => 'barre-balance', 'trainer' => 'olena'],
            ['weekday' => 2, 'start_time' => '19:00', 'room' => 'lavender-hall', 'class_type' => 'functional-fit', 'trainer' => 'iryna'],
            ['weekday' => 3, 'start_time' => '09:00', 'room' => 'lavender-hall', 'class_type' => 'morning-yoga', 'trainer' => 'victoriia'],
            ['weekday' => 3, 'start_time' => '18:30', 'room' => 'lavender-hall', 'class_type' => 'barre-balance', 'trainer' => 'nataliia'],
            ['weekday' => 4, 'start_time' => '10:00', 'room' => 'lavender-hall', 'class_type' => 'pilates-flow', 'trainer' => 'sofiia'],
            ['weekday' => 4, 'start_time' => '19:00', 'room' => 'lavender-hall', 'class_type' => 'functional-fit', 'trainer' => 'iryna'],
            ['weekday' => 5, 'start_time' => '09:00', 'room' => 'lavender-hall', 'class_type' => 'morning-yoga', 'trainer' => 'mariia'],
            ['weekday' => 5, 'start_time' => '18:30', 'room' => 'lavender-hall', 'class_type' => 'barre-balance', 'trainer' => 'olena'],
            ['weekday' => 6, 'start_time' => '11:00', 'room' => 'lavender-hall', 'class_type' => 'pilates-flow', 'trainer' => 'victoriia'],
            ['weekday' => 6, 'start_time' => '13:00', 'room' => 'lavender-hall', 'class_type' => 'functional-fit', 'trainer' => 'nataliia'],
        ];
    }

    /** @return array<int, string> */
    public static function customerNames(): array
    {
        return ['Анна', 'Дарина', 'Юлія', 'Поліна', 'Христина', 'Валерія', 'Єлизавета', 'Діана', 'Вероніка', 'Злата', 'Соломія', 'Тетяна', 'Оксана', 'Марина', 'Людмила', 'Надія', 'Світлана', 'Ольга', 'Інна', 'Каріна', 'Богдана', 'Яна', 'Мирослава', 'Лариса'];
    }

    /** @return array<int, array{name: string, phone: string, status: WebsiteLeadStatus}> */
    public static function leads(): array
    {
        return [
            ['name' => 'Аліса', 'phone' => '+380000000301', 'status' => WebsiteLeadStatus::New],
            ['name' => 'Емілія', 'phone' => '+380000000302', 'status' => WebsiteLeadStatus::Callback],
            ['name' => 'Катерина', 'phone' => '+380000000303', 'status' => WebsiteLeadStatus::Booked],
            ['name' => 'Любов', 'phone' => '+380000000304', 'status' => WebsiteLeadStatus::Rejected],
        ];
    }

    /** @return array<int, CustomerClassPassStatus> */
    public static function passStatuses(): array
    {
        return [
            CustomerClassPassStatus::Active,
            CustomerClassPassStatus::Active,
            CustomerClassPassStatus::Freezed,
            CustomerClassPassStatus::UsedUp,
            CustomerClassPassStatus::Expired,
            CustomerClassPassStatus::Cancelled,
        ];
    }

    /** @return array<int, ClassBookingStatus> */
    public static function bookingStatuses(): array
    {
        return [
            ClassBookingStatus::Attended,
            ClassBookingStatus::Attended,
            ClassBookingStatus::NoShow,
            ClassBookingStatus::Cancelled,
            ClassBookingStatus::Booked,
        ];
    }

    /** @return array<string, array{name: string, description: string, duration: int, color: string}> */
    public static function internalClassTypes(): array
    {
        return [
            'demo-internal-team-training' => [
                'name' => 'Командне тренування',
                'description' => 'Закрите тренування команди на синтетичних демонстраційних даних.',
                'duration' => 90,
                'color' => '#D7A94A',
            ],
            'demo-internal-methodical-meeting' => [
                'name' => 'Методична зустріч тренерів',
                'description' => 'Закрита методична зустріч тренерів без клієнтських записів.',
                'duration' => 75,
                'color' => '#B892C4',
            ],
            'demo-internal-content-shoot' => [
                'name' => 'Зйомка контенту',
                'description' => 'Внутрішня зйомка матеріалів студії, прихована з публічного розкладу.',
                'duration' => 120,
                'color' => '#C98CA5',
            ],
        ];
    }

    /**
     * @return array<string, array{class_type: string, room: string, trainer: string, additional_trainers: array<int, string>, starts_at: string, description: string}>
     */
    public static function internalClassOccurrences(): array
    {
        return [
            'demo-internal-team-training-past' => [
                'class_type' => 'demo-internal-team-training',
                'room' => 'lavender-hall',
                'trainer' => 'mariia',
                'additional_trainers' => ['sofiia', 'olena'],
                'starts_at' => '2026-06-08 15:00:00',
                'description' => 'Минуле закрите командне тренування.',
            ],
            'demo-internal-team-training-future' => [
                'class_type' => 'demo-internal-team-training',
                'room' => 'lavender-hall',
                'trainer' => 'iryna',
                'additional_trainers' => ['victoriia', 'nataliia'],
                'starts_at' => '2026-10-04 15:00:00',
                'description' => 'Майбутнє закрите командне тренування.',
            ],
            'demo-internal-methodical-meeting-past' => [
                'class_type' => 'demo-internal-methodical-meeting',
                'room' => 'plum-studio',
                'trainer' => 'mariia',
                'additional_trainers' => ['iryna'],
                'starts_at' => '2026-07-06 13:00:00',
                'description' => 'Минуле обговорення методики та програм занять.',
            ],
            'demo-internal-methodical-meeting-future' => [
                'class_type' => 'demo-internal-methodical-meeting',
                'room' => 'plum-studio',
                'trainer' => 'sofiia',
                'additional_trainers' => ['olena'],
                'starts_at' => '2026-11-09 13:00:00',
                'description' => 'Майбутня методична зустріч тренерської команди.',
            ],
            'demo-internal-content-shoot-past' => [
                'class_type' => 'demo-internal-content-shoot',
                'room' => 'lavender-hall',
                'trainer' => 'victoriia',
                'additional_trainers' => ['nataliia'],
                'starts_at' => '2026-05-17 14:00:00',
                'description' => 'Минула внутрішня зйомка контенту.',
            ],
            'demo-internal-content-shoot-future' => [
                'class_type' => 'demo-internal-content-shoot',
                'room' => 'lavender-hall',
                'trainer' => 'olena',
                'additional_trainers' => ['mariia', 'sofiia'],
                'starts_at' => '2027-02-07 14:00:00',
                'description' => 'Запланована внутрішня зйомка контенту.',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function showcaseEvents(): array
    {
        return [
            'demo-showcase-paid-workshop-2026' => [
                'status' => 'published',
                'title' => 'Майстер-клас «Сильний центр»',
                'summary' => 'Синтетичний платний майстер-клас із продажами, квитками та відмітками на вході.',
                'description_html' => '<p>Демонстраційний майстер-клас із пілатесу для прикладу продажів і контролю входу.</p>',
                'starts_at' => '2026-06-20 10:00:00',
                'ends_at' => '2026-06-20 13:00:00',
                'capacity' => 72,
                'published_at' => '2026-05-20 09:00:00',
                'cancelled_at' => null,
                'ticket_types' => [
                    'standard' => ['name' => 'Стандарт', 'description' => 'Участь у майстер-класі.', 'inventory' => 60, 'price_cents' => 75000, 'sort_order' => 10],
                    'vip' => ['name' => 'VIP', 'description' => 'Участь і коротка персональна консультація.', 'inventory' => 12, 'price_cents' => 125000, 'sort_order' => 20],
                ],
                'orders' => [
                    'paid-workshop-001' => [
                        'ticket_type' => 'standard',
                        'quantity' => 2,
                        'status' => 'paid',
                        'buyer_name' => 'Демо Покупець Один',
                        'buyer_email' => 'event-buyer-001@ladna-demo.example.test',
                        'amount_cents' => 150000,
                        'paid_at' => '2026-06-02 12:00:00',
                        'tickets' => [
                            ['status' => 'valid', 'checked_in_at' => '2026-06-20 09:42:00'],
                            ['status' => 'valid', 'checked_in_at' => '2026-06-20 09:47:00'],
                        ],
                    ],
                    'paid-workshop-002' => [
                        'ticket_type' => 'vip',
                        'quantity' => 1,
                        'status' => 'refunded',
                        'buyer_name' => 'Демо Покупець Два',
                        'buyer_email' => 'event-buyer-002@ladna-demo.example.test',
                        'amount_cents' => 125000,
                        'paid_at' => '2026-06-05 15:30:00',
                        'refunded_at' => '2026-06-12 10:15:00',
                        'tickets' => [
                            ['status' => 'refunded', 'checked_in_at' => null],
                        ],
                    ],
                ],
            ],
            'demo-showcase-free-open-day-2026' => [
                'status' => 'published',
                'title' => 'День відкритих дверей',
                'summary' => 'Синтетична безкоштовна подія з реєстраціями та контролем входу.',
                'description_html' => '<p>Знайомство з напрямами студії, тренерами та форматом занять.</p>',
                'starts_at' => '2026-07-12 11:00:00',
                'ends_at' => '2026-07-12 15:00:00',
                'capacity' => 80,
                'published_at' => '2026-06-15 09:00:00',
                'cancelled_at' => null,
                'ticket_types' => [
                    'free' => ['name' => 'Безкоштовна реєстрація', 'description' => 'Вхід за попередньою реєстрацією.', 'inventory' => 80, 'price_cents' => 0, 'sort_order' => 10],
                ],
                'orders' => [
                    'free-open-day-001' => [
                        'ticket_type' => 'free',
                        'quantity' => 3,
                        'status' => 'paid',
                        'buyer_name' => 'Демо Відвідувач',
                        'buyer_email' => 'open-day-001@ladna-demo.example.test',
                        'amount_cents' => 0,
                        'paid_at' => '2026-07-01 14:00:00',
                        'tickets' => [
                            ['status' => 'valid', 'checked_in_at' => '2026-07-12 10:51:00'],
                            ['status' => 'valid', 'checked_in_at' => '2026-07-12 10:56:00'],
                            ['status' => 'valid', 'checked_in_at' => null],
                        ],
                    ],
                ],
            ],
            'demo-showcase-cancelled-intensive-2026' => [
                'status' => 'cancelled',
                'title' => 'Інтенсив із мобільності',
                'summary' => 'Синтетична скасована подія з оплатою, яка потребує уваги до повернення.',
                'description_html' => '<p>Скасований демонстраційний інтенсив для показу процесу повернення коштів.</p>',
                'starts_at' => '2026-09-05 10:00:00',
                'ends_at' => '2026-09-05 14:00:00',
                'capacity' => 30,
                'published_at' => '2026-06-30 09:00:00',
                'cancelled_at' => '2026-07-20 16:00:00',
                'ticket_types' => [
                    'general' => ['name' => 'Загальний', 'description' => 'Участь в інтенсиві.', 'inventory' => 30, 'price_cents' => 90000, 'sort_order' => 10],
                ],
                'orders' => [
                    'cancelled-intensive-001' => [
                        'ticket_type' => 'general',
                        'quantity' => 2,
                        'status' => 'refund_required',
                        'buyer_name' => 'Демо Покупець Повернення',
                        'buyer_email' => 'refund-attention@ladna-demo.example.test',
                        'amount_cents' => 180000,
                        'paid_at' => '2026-07-10 12:30:00',
                        'tickets' => [
                            ['status' => 'voided', 'checked_in_at' => null],
                            ['status' => 'voided', 'checked_in_at' => null],
                        ],
                    ],
                ],
            ],
            'demo-showcase-draft-retreat-2026' => [
                'status' => 'draft',
                'title' => 'Чернетка: осінній ретрит',
                'summary' => 'Синтетична чернетка майбутньої події.',
                'description_html' => '<p>Подія ще готується і не опублікована для клієнтів.</p>',
                'starts_at' => '2026-11-15 09:00:00',
                'ends_at' => '2026-11-15 18:00:00',
                'capacity' => 24,
                'published_at' => null,
                'cancelled_at' => null,
                'ticket_types' => [
                    'participant' => ['name' => 'Учасник', 'description' => 'Попередній тариф чернетки.', 'inventory' => 24, 'price_cents' => 160000, 'sort_order' => 10],
                ],
                'orders' => [],
            ],
            'demo-showcase-spring-festival-2027' => [
                'status' => 'published',
                'title' => 'Весняний фестиваль руху 2027',
                'summary' => 'Синтетична подія в березні 2027 року з ранньою ціною, стандартним і VIP-квитком.',
                'description_html' => '<p>День майстер-класів, відкритих тренувань і знайомства зі спільнотою студії.</p>',
                'starts_at' => '2027-03-20 10:00:00',
                'ends_at' => '2027-03-20 19:00:00',
                'capacity' => 120,
                'published_at' => '2026-07-25 09:00:00',
                'cancelled_at' => null,
                'ticket_types' => [
                    'early-bird' => [
                        'name' => 'Early bird',
                        'description' => 'Рання ціна для перших учасників.',
                        'inventory' => 40,
                        'price_cents' => 110000,
                        'early_bird_price_cents' => 75000,
                        'early_bird_ends_at' => '2026-12-31 23:59:59',
                        'early_bird_quota' => 20,
                        'sort_order' => 10,
                    ],
                    'standard' => ['name' => 'Стандарт', 'description' => 'Повний день фестивалю.', 'inventory' => 60, 'price_cents' => 110000, 'sort_order' => 20],
                    'vip' => ['name' => 'VIP', 'description' => 'Фестиваль і закрита зустріч із тренерами.', 'inventory' => 20, 'price_cents' => 175000, 'sort_order' => 30],
                ],
                'orders' => [
                    'spring-festival-001' => [
                        'ticket_type' => 'early-bird',
                        'price_tier' => 'early_bird',
                        'quantity' => 2,
                        'status' => 'paid',
                        'buyer_name' => 'Демо Рання Реєстрація',
                        'buyer_email' => 'spring-early-001@ladna-demo.example.test',
                        'amount_cents' => 150000,
                        'paid_at' => '2026-07-27 11:20:00',
                        'tickets' => [
                            ['status' => 'valid', 'checked_in_at' => null],
                            ['status' => 'valid', 'checked_in_at' => null],
                        ],
                    ],
                ],
            ],
        ];
    }
}
