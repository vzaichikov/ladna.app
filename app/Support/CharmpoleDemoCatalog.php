<?php

namespace App\Support;

class CharmpoleDemoCatalog
{
    /**
     * @return array<int, string>
     */
    public static function enabledScheduleKinds(): array
    {
        return self::account()['enabled_schedule_kinds'];
    }

    /**
     * @return array<string, string>
     */
    public static function scheduleKindColors(): array
    {
        return self::account()['schedule_kind_colors'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function account(): array
    {
        return [
            'name' => 'Charmpole',
            'slug' => 'charmpole',
            'status' => 'active',
            'default_language' => 'uk',
            'country_code' => 'UA',
            'default_currency' => 'UAH',
            'logo_path' => 'brand/charmpole-icon.svg',
            'brand_color' => '#d80a7d',
            'studio_slogan' => 'Pole Dance, Exotic, Stretching і тренування для вашого ритму.',
            'support_phone_url' => 'tel:+380939470278',
            'timezone' => 'Europe/Kyiv',
            'enabled_schedule_kinds' => [
                0 => 'group_class',
                1 => 'private_lesson',
                2 => 'room_rental',
            ],
            'schedule_kind_colors' => [
                'group_class' => '#A3E635',
                'private_lesson' => '#A78AB9',
                'room_rental' => '#38BDF8',
            ],
            'opening_hours' => [
                1 => [
                    'enabled' => true,
                    'opens_at' => '08:00',
                    'closes_at' => '21:00',
                ],
                2 => [
                    'enabled' => true,
                    'opens_at' => '08:00',
                    'closes_at' => '21:00',
                ],
                3 => [
                    'enabled' => true,
                    'opens_at' => '08:00',
                    'closes_at' => '21:00',
                ],
                4 => [
                    'enabled' => true,
                    'opens_at' => '08:00',
                    'closes_at' => '21:00',
                ],
                5 => [
                    'enabled' => true,
                    'opens_at' => '08:00',
                    'closes_at' => '21:00',
                ],
                6 => [
                    'enabled' => true,
                    'opens_at' => '08:00',
                    'closes_at' => '21:00',
                ],
                7 => [
                    'enabled' => true,
                    'opens_at' => '08:00',
                    'closes_at' => '21:00',
                ],
            ],
            'studio_rules_html' => '<h1>Правила відвідування студії</h1><p><br></p><h1>Відвідування по запису. Якщо ви не записалися, то є два випадки: або може не бути місць в групі, або не відбудеться тренування, бо нема записаних людей.<br>Можна оплачувати тренування як разово, так і по абонементу.</h1><p><br>Термін дії будь-якого абонемента — 30 календарних днів з дня його активації. Якщо ви не активуєте абонемент протягом пів року - він згорає.<br><br>Абонемент може бути використаний на усі види групових занять.<br><br>Якщо ви не можете відвідати заняття, про це слід попередити за 24 години ДО початку тренування, в інакшому випадку заняття згорає.<br><br>Абонементи не заморожуються і не можуть бути передані іншій людині.<br><br></p><blockquote>На кожне заняття слід носити рушничок, який ми стелимо на килимки.</blockquote><br>Ми не ліземо на пілон без розігріву і дозволу тренера. Це наша безпека!<br><br>Складні трюки — виконуємо зі страховкою тренера, поки він не дозволить виконати самостійно.<p></p><p><br></p><p>Якщо студія, відмінила групове заняття, з власних причин, ви отримаєте бонус +1 день (до 30) для того щоб виходити свій абонемент.<br><br>Після заняття залиште свій реквізит в гарному стані: витріть після всіх своїх засобів для зчеплення пілон, чи приберіть чирки від стріпів.<br><br></p>',
            'class_pass_cancellation_rules' => [
                'return_sessions_enabled' => false,
                'return_sessions_count' => 1,
                'extend_days_enabled' => true,
                'extend_days_count' => 1,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function location(): array
    {
        return [
            'name' => 'Студія Charmpole',
            'slug' => 'studio',
            'address' => 'Київ, проспект Берестейський (Перемоги), 56',
            'google_maps_embed_url' => 'https://www.google.com/maps?q=%D0%9A%D0%B8%D1%97%D0%B2%2C%20%D0%BF%D1%80%D0%BE%D1%81%D0%BF%D0%B5%D0%BA%D1%82%20%D0%91%D0%B5%D1%80%D0%B5%D1%81%D1%82%D0%B5%D0%B9%D1%81%D1%8C%D0%BA%D0%B8%D0%B9%2056&output=embed',
            'phone' => '+380939470278',
            'email' => 'hello@charmpole.dance',
            'timezone' => 'Europe/Kyiv',
            'is_active' => true,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function directions(): array
    {
        return [
            'pole-dance' => [
                'name' => 'Pole Dance',
                'description' => 'Техніка, сила та танцювальні комбінації на пілоні.',
                'color' => '#c7f000',
                'is_active' => true,
            ],
            'exotic' => [
                'name' => 'Exotic',
                'description' => 'Exotic pole, флоу, музикальність та хореографія.',
                'color' => '#ff008c',
                'is_active' => true,
            ],
            'stretching' => [
                'name' => 'Stretching',
                'description' => 'Гнучкість, мобільність та відновлення.',
                'color' => '#ff2b2b',
                'is_active' => true,
            ],
            'kids' => [
                'name' => 'Kids',
                'description' => 'Дитячі групи Pole Kids.',
                'color' => '#FF8000',
                'is_active' => true,
            ],
            'acro' => [
                'name' => 'Acro',
                'description' => 'Акробатика та трюкова підготовка.',
                'color' => '#ffffff',
                'is_active' => false,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function rooms(): array
    {
        return [
            'big-hall' => [
                'name' => 'Великий зал',
                'description' => null,
                'capacity' => 12,
                'is_active' => true,
            ],
            'small-hall' => [
                'name' => 'Малий зал',
                'description' => null,
                'capacity' => 6,
                'is_active' => true,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function trainerTypes(): array
    {
        return [
            'trainer' => [
                'name' => 'Тренер',
                'icon' => 'user-round',
                'color' => '#3b223f',
                'is_default' => true,
                'sort_order' => 10,
            ],
            'top' => [
                'name' => 'TOП-тренер',
                'icon' => 'crown',
                'color' => '#d80a7d',
                'is_default' => false,
                'sort_order' => 20,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function classTypes(): array
    {
        return [
            'pole-dance' => [
                'name' => 'Pole Dance',
                'description' => null,
                'color' => '#c7f000',
                'schedule_kind' => 'group_class',
                'default_duration_minutes' => 60,
                'booking_cutoff_minutes' => 60,
                'cancellation_cutoff_minutes' => 1440,
                'default_capacity' => 10,
                'is_active' => true,
                'direction_slug' => 'pole-dance',
            ],
            'pole-kids' => [
                'name' => 'Pole Kids',
                'description' => null,
                'color' => '#ffffff',
                'schedule_kind' => 'group_class',
                'default_duration_minutes' => 60,
                'booking_cutoff_minutes' => 60,
                'cancellation_cutoff_minutes' => 1440,
                'default_capacity' => 8,
                'is_active' => true,
                'direction_slug' => 'kids',
            ],
            'exot-easy' => [
                'name' => 'Exot Easy',
                'description' => null,
                'color' => '#c7f000',
                'schedule_kind' => 'group_class',
                'default_duration_minutes' => 60,
                'booking_cutoff_minutes' => 60,
                'cancellation_cutoff_minutes' => 1440,
                'default_capacity' => 9,
                'is_active' => true,
                'direction_slug' => 'exotic',
            ],
            'exot' => [
                'name' => 'Exot',
                'description' => null,
                'color' => '#ff008c',
                'schedule_kind' => 'group_class',
                'default_duration_minutes' => 60,
                'booking_cutoff_minutes' => 60,
                'cancellation_cutoff_minutes' => 1440,
                'default_capacity' => 9,
                'is_active' => true,
                'direction_slug' => 'exotic',
            ],
            'exot-middle' => [
                'name' => 'Exot Middle',
                'description' => null,
                'color' => '#ffad00',
                'schedule_kind' => 'group_class',
                'default_duration_minutes' => 60,
                'booking_cutoff_minutes' => 60,
                'cancellation_cutoff_minutes' => 1440,
                'default_capacity' => 9,
                'is_active' => true,
                'direction_slug' => 'exotic',
            ],
            'stretching' => [
                'name' => 'Stretching',
                'description' => null,
                'color' => '#ff2b2b',
                'schedule_kind' => 'group_class',
                'default_duration_minutes' => 60,
                'booking_cutoff_minutes' => 60,
                'cancellation_cutoff_minutes' => 1440,
                'default_capacity' => 12,
                'is_active' => true,
                'direction_slug' => 'stretching',
            ],
            'tricks' => [
                'name' => 'Tricks',
                'description' => null,
                'color' => '#ff008c',
                'schedule_kind' => 'group_class',
                'default_duration_minutes' => 60,
                'booking_cutoff_minutes' => 60,
                'cancellation_cutoff_minutes' => 1440,
                'default_capacity' => 10,
                'is_active' => true,
                'direction_slug' => 'pole-dance',
            ],
            'acro-class' => [
                'name' => 'Acro class*',
                'description' => null,
                'color' => '#ffffff',
                'schedule_kind' => 'group_class',
                'default_duration_minutes' => 60,
                'booking_cutoff_minutes' => 60,
                'cancellation_cutoff_minutes' => 1440,
                'default_capacity' => 10,
                'is_active' => true,
                'direction_slug' => null,
            ],
            'individualne-60-xv' => [
                'name' => 'Індивідуальне 60 хв',
                'description' => null,
                'color' => '#d80a7d',
                'schedule_kind' => 'private_lesson',
                'default_duration_minutes' => 60,
                'booking_cutoff_minutes' => 60,
                'cancellation_cutoff_minutes' => 1440,
                'default_capacity' => 2,
                'is_active' => true,
                'direction_slug' => null,
            ],
            'individualne-90-xv' => [
                'name' => 'Індивідуальне 90 хв',
                'description' => null,
                'color' => '#d80a7d',
                'schedule_kind' => 'private_lesson',
                'default_duration_minutes' => 90,
                'booking_cutoff_minutes' => 60,
                'cancellation_cutoff_minutes' => 1440,
                'default_capacity' => 2,
                'is_active' => true,
                'direction_slug' => null,
            ],
            'orenda-60-xv' => [
                'name' => 'Оренда 60 хв',
                'description' => null,
                'color' => '#3b223f',
                'schedule_kind' => 'room_rental',
                'default_duration_minutes' => 60,
                'booking_cutoff_minutes' => 60,
                'cancellation_cutoff_minutes' => 1440,
                'default_capacity' => 12,
                'is_active' => true,
                'direction_slug' => null,
            ],
            'orenda-90-xv' => [
                'name' => 'Оренда 90 хв',
                'description' => null,
                'color' => '#3b223f',
                'schedule_kind' => 'room_rental',
                'default_duration_minutes' => 90,
                'booking_cutoff_minutes' => 60,
                'cancellation_cutoff_minutes' => 1440,
                'default_capacity' => 12,
                'is_active' => true,
                'direction_slug' => null,
            ],
            'orenda-120-xv' => [
                'name' => 'Оренда 120 хв',
                'description' => null,
                'color' => '#3b223f',
                'schedule_kind' => 'room_rental',
                'default_duration_minutes' => 120,
                'booking_cutoff_minutes' => 60,
                'cancellation_cutoff_minutes' => 1440,
                'default_capacity' => 12,
                'is_active' => true,
                'direction_slug' => null,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function trainers(): array
    {
        return [
            'Настя' => [
                'name' => 'Настя',
                'slug' => 'nastia',
                'bio' => 'Тренер студії Charmpole.',
                'photo_path' => 'trainer-photos/charmpole/avatar-nastya.png',
                'is_active' => true,
                'trainer_type_key' => 'trainer',
            ],
            'Slastya' => [
                'name' => 'Slastya',
                'slug' => 'slastya',
                'bio' => 'Тренер студії Charmpole.',
                'photo_path' => 'trainer-photos/charmpole/avatar-slastya.png',
                'is_active' => true,
                'trainer_type_key' => 'top',
            ],
            'Катя' => [
                'name' => 'Катя',
                'slug' => 'katia',
                'bio' => 'Тренер студії Charmpole.',
                'photo_path' => 'trainer-photos/charmpole/avatar-katya.png',
                'is_active' => true,
                'trainer_type_key' => 'top',
            ],
            'Ліза' => [
                'name' => 'Ліза',
                'slug' => 'liza',
                'bio' => 'Тренер студії Charmpole.',
                'photo_path' => 'trainer-photos/charmpole/avatar-liza.png',
                'is_active' => true,
                'trainer_type_key' => 'trainer',
            ],
            'Женя' => [
                'name' => 'Женя',
                'slug' => 'zenia',
                'bio' => 'Тренер студії Charmpole.',
                'photo_path' => 'trainer-photos/charmpole/avatar-jenya.png',
                'is_active' => true,
                'trainer_type_key' => 'trainer',
            ],
            'Аліна' => [
                'name' => 'Аліна',
                'slug' => 'alina',
                'bio' => 'Тренер студії Charmpole.',
                'photo_path' => 'trainer-photos/charmpole/avatar-alina.png',
                'is_active' => true,
                'trainer_type_key' => 'trainer',
            ],
            '_loco_man' => [
                'name' => '_loco_man',
                'slug' => 'loco-man',
                'bio' => 'Тренер студії Charmpole.',
                'photo_path' => 'trainer-photos/charmpole/avatar-loco-man.png',
                'is_active' => true,
                'trainer_type_key' => 'trainer',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function classPassSegments(): array
    {
        return [
            'rankovi-abonementy' => [
                'schedule_kind' => 'group_class',
                'name' => 'Ранкові абонементи',
                'sort_order' => 0,
                'is_active' => true,
                'direction_slugs' => [
                    0 => 'pole-dance',
                    1 => 'exotic',
                    2 => 'stretching',
                ],
            ],
            'dytyachi-abonementy' => [
                'schedule_kind' => 'group_class',
                'name' => 'Дитячі абонементи',
                'sort_order' => 0,
                'is_active' => true,
                'direction_slugs' => [
                    0 => 'kids',
                ],
            ],
            'povnyy-den' => [
                'schedule_kind' => 'group_class',
                'name' => 'Повний день',
                'sort_order' => 0,
                'is_active' => true,
                'direction_slugs' => [
                    0 => 'pole-dance',
                    1 => 'exotic',
                    2 => 'stretching',
                ],
            ],
            'z-trenerom' => [
                'schedule_kind' => 'private_lesson',
                'name' => 'З тренером',
                'sort_order' => 0,
                'is_active' => true,
                'direction_slugs' => [
                ],
            ],
            'z-top-trenerom' => [
                'schedule_kind' => 'private_lesson',
                'name' => 'З ТОП-тренером',
                'sort_order' => 0,
                'is_active' => true,
                'direction_slugs' => [
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function classPassPlans(): array
    {
        return [
            'razove-vidviduvannya-povnyy-den' => [
                'name' => 'Разове відвідування повний день',
                'schedule_kind' => 'group_class',
                'description' => 'Разове відвідування на групове заняття',
                'price_cents' => 45000,
                'sessions_count' => 1,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 1,
                'class_pass_segment_slug' => null,
                'class_type_slugs' => [
                    0 => 'pole-dance',
                    1 => 'pole-kids',
                    2 => 'exot-easy',
                    3 => 'exot',
                    4 => 'exot-middle',
                    5 => 'stretching',
                    6 => 'tricks',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'big-hall',
                ],
            ],
            'razove-vidviduvannya-ranok' => [
                'name' => 'Разове відвідування ранок',
                'schedule_kind' => 'group_class',
                'description' => 'Разовий абонемент на 1 заняття до 12:00',
                'price_cents' => 40000,
                'sessions_count' => 1,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => '08:00:00',
                'available_until_time' => '15:00:00',
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 3,
                'class_pass_segment_slug' => 'rankovi-abonementy',
                'class_type_slugs' => [
                    0 => 'pole-dance',
                    1 => 'exot-easy',
                    2 => 'exot',
                    3 => 'exot-middle',
                    4 => 'stretching',
                    5 => 'tricks',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'big-hall',
                ],
            ],
            'trial-class' => [
                'name' => 'Пробне заняття',
                'schedule_kind' => 'group_class',
                'description' => 'Пробне заняття для нового клієнта.',
                'price_cents' => 40000,
                'sessions_count' => 1,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => true,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 5,
                'class_pass_segment_slug' => null,
                'class_type_slugs' => [
                    0 => 'pole-dance',
                    1 => 'pole-kids',
                    2 => 'exot-easy',
                    3 => 'exot',
                    4 => 'exot-middle',
                    5 => 'stretching',
                    6 => 'tricks',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                ],
            ],
            'full-day-start' => [
                'name' => 'START повний день',
                'schedule_kind' => 'group_class',
                'description' => 'Повний абонемент на 4 заняття.',
                'price_cents' => 150000,
                'sessions_count' => 4,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 10,
                'class_pass_segment_slug' => 'povnyy-den',
                'class_type_slugs' => [
                    0 => 'pole-dance',
                    1 => 'exot-easy',
                    2 => 'exot',
                    3 => 'exot-middle',
                    4 => 'stretching',
                    5 => 'tricks',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'big-hall',
                ],
            ],
            'full-day-amateur' => [
                'name' => 'AMATEUR повний день',
                'schedule_kind' => 'group_class',
                'description' => 'Повний абонемент на 6 занять.',
                'price_cents' => 200000,
                'sessions_count' => 6,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 20,
                'class_pass_segment_slug' => 'povnyy-den',
                'class_type_slugs' => [
                    0 => 'pole-dance',
                    1 => 'exot-easy',
                    2 => 'exot',
                    3 => 'exot-middle',
                    4 => 'stretching',
                    5 => 'tricks',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'big-hall',
                ],
            ],
            'full-day-base' => [
                'name' => 'BASE повний день',
                'schedule_kind' => 'group_class',
                'description' => 'Повний абонемент на 8 занять.',
                'price_cents' => 250000,
                'sessions_count' => 8,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 30,
                'class_pass_segment_slug' => 'povnyy-den',
                'class_type_slugs' => [
                    0 => 'pole-dance',
                    1 => 'exot-easy',
                    2 => 'exot',
                    3 => 'exot-middle',
                    4 => 'stretching',
                    5 => 'tricks',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'big-hall',
                ],
            ],
            'full-day-semi-pro' => [
                'name' => 'Semi pro повний день',
                'schedule_kind' => 'group_class',
                'description' => 'Повний абонемент на 12 занять.',
                'price_cents' => 350000,
                'sessions_count' => 12,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 40,
                'class_pass_segment_slug' => 'povnyy-den',
                'class_type_slugs' => [
                    0 => 'pole-dance',
                    1 => 'exot-easy',
                    2 => 'exot',
                    3 => 'exot-middle',
                    4 => 'stretching',
                    5 => 'tricks',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'big-hall',
                ],
            ],
            'full-day-pro' => [
                'name' => 'Pro повний день',
                'schedule_kind' => 'group_class',
                'description' => 'Повний абонемент на 16 занять.',
                'price_cents' => 440000,
                'sessions_count' => 16,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 50,
                'class_pass_segment_slug' => 'povnyy-den',
                'class_type_slugs' => [
                    0 => 'pole-dance',
                    1 => 'exot-easy',
                    2 => 'exot',
                    3 => 'exot-middle',
                    4 => 'stretching',
                    5 => 'tricks',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                ],
            ],
            'morning-start' => [
                'name' => 'START ранок',
                'schedule_kind' => 'group_class',
                'description' => 'Ранковий абонемент на 4 заняття до 12:00.',
                'price_cents' => 140000,
                'sessions_count' => 4,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => '15:00:00',
                'allows_any_time' => true,
                'is_trial' => false,
                'any_time_addon_price_cents' => 3500,
                'is_active' => true,
                'sort_order' => 60,
                'class_pass_segment_slug' => 'rankovi-abonementy',
                'class_type_slugs' => [
                    0 => 'pole-dance',
                    1 => 'exot-easy',
                    2 => 'exot',
                    3 => 'exot-middle',
                    4 => 'stretching',
                    5 => 'tricks',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'big-hall',
                ],
            ],
            'morning-amateur' => [
                'name' => 'AMATEUR ранок',
                'schedule_kind' => 'group_class',
                'description' => 'Ранковий абонемент на 6 занять до 12:00.',
                'price_cents' => 190000,
                'sessions_count' => 6,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => '15:00:00',
                'allows_any_time' => true,
                'is_trial' => false,
                'any_time_addon_price_cents' => 3500,
                'is_active' => true,
                'sort_order' => 70,
                'class_pass_segment_slug' => 'rankovi-abonementy',
                'class_type_slugs' => [
                    0 => 'pole-dance',
                    1 => 'exot-easy',
                    2 => 'exot',
                    3 => 'exot-middle',
                    4 => 'stretching',
                    5 => 'tricks',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'big-hall',
                ],
            ],
            'morning-base' => [
                'name' => 'BASE ранок',
                'schedule_kind' => 'group_class',
                'description' => 'Ранковий абонемент на 8 занять до 12:00.',
                'price_cents' => 240000,
                'sessions_count' => 8,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => '15:00:00',
                'allows_any_time' => true,
                'is_trial' => false,
                'any_time_addon_price_cents' => 3500,
                'is_active' => true,
                'sort_order' => 80,
                'class_pass_segment_slug' => 'rankovi-abonementy',
                'class_type_slugs' => [
                    0 => 'pole-dance',
                    1 => 'exot-easy',
                    2 => 'exot',
                    3 => 'exot-middle',
                    4 => 'stretching',
                    5 => 'tricks',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'big-hall',
                ],
            ],
            'morning-semi-pro' => [
                'name' => 'Semi pro ранок',
                'schedule_kind' => 'group_class',
                'description' => 'Ранковий абонемент на 12 занять до 12:00.',
                'price_cents' => 310000,
                'sessions_count' => 12,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => '15:00:00',
                'allows_any_time' => true,
                'is_trial' => false,
                'any_time_addon_price_cents' => 3500,
                'is_active' => true,
                'sort_order' => 90,
                'class_pass_segment_slug' => 'rankovi-abonementy',
                'class_type_slugs' => [
                    0 => 'pole-dance',
                    1 => 'exot-easy',
                    2 => 'exot',
                    3 => 'exot-middle',
                    4 => 'stretching',
                    5 => 'tricks',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'big-hall',
                ],
            ],
            'morning-pro' => [
                'name' => 'Pro ранок',
                'schedule_kind' => 'group_class',
                'description' => 'Ранковий абонемент на 16 занять до 12:00.',
                'price_cents' => 390000,
                'sessions_count' => 16,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => '15:00:00',
                'allows_any_time' => true,
                'is_trial' => false,
                'any_time_addon_price_cents' => 3500,
                'is_active' => false,
                'sort_order' => 100,
                'class_pass_segment_slug' => 'rankovi-abonementy',
                'class_type_slugs' => [
                    0 => 'pole-dance',
                    1 => 'exot-easy',
                    2 => 'exot',
                    3 => 'exot-middle',
                    4 => 'stretching',
                    5 => 'tricks',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'big-hall',
                ],
            ],
            'private-top-60' => [
                'name' => 'TOP-1',
                'schedule_kind' => 'private_lesson',
                'description' => '1 год. з ТОП-тренером для 1 людини.',
                'price_cents' => 110000,
                'sessions_count' => 1,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 200,
                'class_pass_segment_slug' => 'z-top-trenerom',
                'class_type_slugs' => [
                    0 => 'individualne-60-xv',
                ],
                'trainer_type_keys' => [
                    0 => 'top',
                ],
                'room_slugs' => [
                ],
            ],
            'private-top-90' => [
                'name' => 'TOP-1.5',
                'schedule_kind' => 'private_lesson',
                'description' => '1.5 год. з ТОП-тренером для 1 людини.',
                'price_cents' => 160000,
                'sessions_count' => 1,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 210,
                'class_pass_segment_slug' => 'z-top-trenerom',
                'class_type_slugs' => [
                    0 => 'individualne-90-xv',
                ],
                'trainer_type_keys' => [
                    0 => 'top',
                ],
                'room_slugs' => [
                ],
            ],
            'private-standard-60' => [
                'name' => 'STANDART-1',
                'schedule_kind' => 'private_lesson',
                'description' => '1 год. з тренером для 1 людини.',
                'price_cents' => 100000,
                'sessions_count' => 1,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 220,
                'class_pass_segment_slug' => 'z-trenerom',
                'class_type_slugs' => [
                    0 => 'individualne-60-xv',
                ],
                'trainer_type_keys' => [
                    0 => 'trainer',
                ],
                'room_slugs' => [
                ],
            ],
            'private-standard-90' => [
                'name' => 'STANDART-1.5',
                'schedule_kind' => 'private_lesson',
                'description' => '1.5 год. з тренером для 1 людини.',
                'price_cents' => 140000,
                'sessions_count' => 1,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 230,
                'class_pass_segment_slug' => 'z-trenerom',
                'class_type_slugs' => [
                    0 => 'individualne-90-xv',
                ],
                'trainer_type_keys' => [
                    0 => 'trainer',
                ],
                'room_slugs' => [
                ],
            ],
            'big-hall-rental-60' => [
                'name' => 'Великий зал 1г',
                'schedule_kind' => 'room_rental',
                'description' => 'Оренда великого залу на 1 годину.',
                'price_cents' => 55000,
                'sessions_count' => 1,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 300,
                'class_pass_segment_slug' => null,
                'class_type_slugs' => [
                    0 => 'orenda-60-xv',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'big-hall',
                ],
            ],
            'big-hall-rental-90' => [
                'name' => 'Великий зал 1.5г',
                'schedule_kind' => 'room_rental',
                'description' => 'Оренда великого залу на 1.5 години.',
                'price_cents' => 65000,
                'sessions_count' => 1,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 310,
                'class_pass_segment_slug' => null,
                'class_type_slugs' => [
                    0 => 'orenda-90-xv',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'big-hall',
                ],
            ],
            'big-hall-rental-120' => [
                'name' => 'Великий зал 2г',
                'schedule_kind' => 'room_rental',
                'description' => 'Оренда великого залу на 2 години.',
                'price_cents' => 85000,
                'sessions_count' => 1,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 320,
                'class_pass_segment_slug' => null,
                'class_type_slugs' => [
                    0 => 'orenda-120-xv',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'big-hall',
                ],
            ],
            'small-hall-rental-60' => [
                'name' => 'Малий зал 1г',
                'schedule_kind' => 'room_rental',
                'description' => 'Оренда малого залу на 1 годину.',
                'price_cents' => 40000,
                'sessions_count' => 1,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 330,
                'class_pass_segment_slug' => null,
                'class_type_slugs' => [
                    0 => 'orenda-60-xv',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'small-hall',
                ],
            ],
            'small-hall-rental-90' => [
                'name' => 'Малий зал 1.5г',
                'schedule_kind' => 'room_rental',
                'description' => 'Оренда малого залу на 1.5 години.',
                'price_cents' => 60000,
                'sessions_count' => 1,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 340,
                'class_pass_segment_slug' => null,
                'class_type_slugs' => [
                    0 => 'orenda-90-xv',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'small-hall',
                ],
            ],
            'small-hall-rental-120' => [
                'name' => 'Малий зал 2г',
                'schedule_kind' => 'room_rental',
                'description' => 'Оренда малого залу на 2 години.',
                'price_cents' => 70000,
                'sessions_count' => 1,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'available_from_time' => null,
                'available_until_time' => null,
                'allows_any_time' => false,
                'is_trial' => false,
                'any_time_addon_price_cents' => null,
                'is_active' => true,
                'sort_order' => 350,
                'class_pass_segment_slug' => null,
                'class_type_slugs' => [
                    0 => 'orenda-120-xv',
                ],
                'trainer_type_keys' => [
                ],
                'room_slugs' => [
                    0 => 'small-hall',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function scheduleRows(): array
    {
        return [
            0 => [
                'weekday' => 1,
                'start_time' => '09:00',
                'class_type_slug' => 'exot-easy',
                'trainer_name' => 'Настя',
                'room_slug' => 'big-hall',
            ],
            1 => [
                'weekday' => 1,
                'start_time' => '10:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Настя',
                'room_slug' => 'big-hall',
            ],
            2 => [
                'weekday' => 1,
                'start_time' => '11:00',
                'class_type_slug' => 'stretching',
                'trainer_name' => 'Настя',
                'room_slug' => 'big-hall',
            ],
            3 => [
                'weekday' => 1,
                'start_time' => '16:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Настя',
                'room_slug' => 'big-hall',
            ],
            4 => [
                'weekday' => 1,
                'start_time' => '17:00',
                'class_type_slug' => 'exot-easy',
                'trainer_name' => 'Настя',
                'room_slug' => 'big-hall',
            ],
            5 => [
                'weekday' => 1,
                'start_time' => '18:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Катя',
                'room_slug' => 'big-hall',
            ],
            6 => [
                'weekday' => 1,
                'start_time' => '19:00',
                'class_type_slug' => 'exot-middle',
                'trainer_name' => 'Катя',
                'room_slug' => 'big-hall',
            ],
            7 => [
                'weekday' => 1,
                'start_time' => '20:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Катя',
                'room_slug' => 'big-hall',
            ],
            8 => [
                'weekday' => 2,
                'start_time' => '09:00',
                'class_type_slug' => 'tricks',
                'trainer_name' => 'Slastya',
                'room_slug' => 'big-hall',
            ],
            9 => [
                'weekday' => 2,
                'start_time' => '10:00',
                'class_type_slug' => 'exot',
                'trainer_name' => 'Slastya',
                'room_slug' => 'big-hall',
            ],
            10 => [
                'weekday' => 2,
                'start_time' => '16:00',
                'class_type_slug' => 'pole-kids',
                'trainer_name' => 'Ліза',
                'room_slug' => 'big-hall',
            ],
            11 => [
                'weekday' => 2,
                'start_time' => '17:00',
                'class_type_slug' => 'pole-kids',
                'trainer_name' => 'Ліза',
                'room_slug' => 'big-hall',
            ],
            12 => [
                'weekday' => 2,
                'start_time' => '18:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Ліза',
                'room_slug' => 'big-hall',
            ],
            13 => [
                'weekday' => 2,
                'start_time' => '19:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Аліна',
                'room_slug' => 'big-hall',
            ],
            14 => [
                'weekday' => 2,
                'start_time' => '20:00',
                'class_type_slug' => 'exot-easy',
                'trainer_name' => 'Аліна',
                'room_slug' => 'big-hall',
            ],
            15 => [
                'weekday' => 3,
                'start_time' => '09:00',
                'class_type_slug' => 'exot-easy',
                'trainer_name' => 'Настя',
                'room_slug' => 'big-hall',
            ],
            16 => [
                'weekday' => 3,
                'start_time' => '10:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Настя',
                'room_slug' => 'big-hall',
            ],
            17 => [
                'weekday' => 3,
                'start_time' => '11:00',
                'class_type_slug' => 'stretching',
                'trainer_name' => 'Настя',
                'room_slug' => 'big-hall',
            ],
            18 => [
                'weekday' => 3,
                'start_time' => '16:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Настя',
                'room_slug' => 'big-hall',
            ],
            19 => [
                'weekday' => 3,
                'start_time' => '17:00',
                'class_type_slug' => 'exot-easy',
                'trainer_name' => 'Настя',
                'room_slug' => 'big-hall',
            ],
            20 => [
                'weekday' => 3,
                'start_time' => '18:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Катя',
                'room_slug' => 'big-hall',
            ],
            21 => [
                'weekday' => 3,
                'start_time' => '19:00',
                'class_type_slug' => 'exot-middle',
                'trainer_name' => 'Катя',
                'room_slug' => 'big-hall',
            ],
            22 => [
                'weekday' => 3,
                'start_time' => '20:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Катя',
                'room_slug' => 'big-hall',
            ],
            23 => [
                'weekday' => 4,
                'start_time' => '09:00',
                'class_type_slug' => 'stretching',
                'trainer_name' => 'Slastya',
                'room_slug' => 'big-hall',
            ],
            24 => [
                'weekday' => 4,
                'start_time' => '10:00',
                'class_type_slug' => 'exot',
                'trainer_name' => 'Slastya',
                'room_slug' => 'big-hall',
            ],
            25 => [
                'weekday' => 4,
                'start_time' => '16:00',
                'class_type_slug' => 'pole-kids',
                'trainer_name' => 'Ліза',
                'room_slug' => 'big-hall',
            ],
            26 => [
                'weekday' => 4,
                'start_time' => '17:00',
                'class_type_slug' => 'stretching',
                'trainer_name' => 'Женя',
                'room_slug' => 'big-hall',
            ],
            27 => [
                'weekday' => 4,
                'start_time' => '18:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Женя',
                'room_slug' => 'big-hall',
            ],
            28 => [
                'weekday' => 4,
                'start_time' => '19:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Женя',
                'room_slug' => 'big-hall',
            ],
            29 => [
                'weekday' => 4,
                'start_time' => '20:00',
                'class_type_slug' => 'exot-easy',
                'trainer_name' => 'Аліна',
                'room_slug' => 'big-hall',
            ],
            30 => [
                'weekday' => 5,
                'start_time' => '18:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Катя',
                'room_slug' => 'big-hall',
            ],
            31 => [
                'weekday' => 5,
                'start_time' => '19:00',
                'class_type_slug' => 'exot-middle',
                'trainer_name' => 'Катя',
                'room_slug' => 'big-hall',
            ],
            32 => [
                'weekday' => 6,
                'start_time' => '09:00',
                'class_type_slug' => 'acro-class',
                'trainer_name' => '_loco_man',
                'room_slug' => 'big-hall',
            ],
            33 => [
                'weekday' => 6,
                'start_time' => '11:00',
                'class_type_slug' => 'exot-easy',
                'trainer_name' => 'Настя',
                'room_slug' => 'big-hall',
            ],
            34 => [
                'weekday' => 6,
                'start_time' => '12:00',
                'class_type_slug' => 'stretching',
                'trainer_name' => 'Настя',
                'room_slug' => 'big-hall',
            ],
            35 => [
                'weekday' => 6,
                'start_time' => '13:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Настя',
                'room_slug' => 'big-hall',
            ],
            36 => [
                'weekday' => 7,
                'start_time' => '10:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Женя',
                'room_slug' => 'big-hall',
            ],
            37 => [
                'weekday' => 7,
                'start_time' => '11:00',
                'class_type_slug' => 'pole-dance',
                'trainer_name' => 'Женя',
                'room_slug' => 'big-hall',
            ],
            38 => [
                'weekday' => 7,
                'start_time' => '12:00',
                'class_type_slug' => 'stretching',
                'trainer_name' => 'Женя',
                'room_slug' => 'big-hall',
            ],
            39 => [
                'weekday' => 7,
                'start_time' => '13:00',
                'class_type_slug' => 'exot-easy',
                'trainer_name' => 'Аліна',
                'room_slug' => 'big-hall',
            ],
        ];
    }
}
