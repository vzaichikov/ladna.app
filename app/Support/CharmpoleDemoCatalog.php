<?php

namespace App\Support;

use App\Enums\ScheduleKind;

class CharmpoleDemoCatalog
{
    /**
     * @return array<int, string>
     */
    public static function enabledScheduleKinds(): array
    {
        return [
            ScheduleKind::GroupClass->value,
            ScheduleKind::PrivateLesson->value,
            ScheduleKind::RoomRental->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function scheduleKindColors(): array
    {
        return ScheduleKindRegistry::defaultColors();
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
                'color' => '#ffffff',
                'is_active' => true,
            ],
            'acro' => [
                'name' => 'Acro',
                'description' => 'Акробатика та трюкова підготовка.',
                'color' => '#ffffff',
                'is_active' => true,
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
                'capacity' => 12,
                'is_active' => true,
            ],
            'small-hall' => [
                'name' => 'Малий зал',
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
                'color' => '#3B223F',
                'is_default' => true,
                'sort_order' => 10,
            ],
            'top' => [
                'name' => 'ТОП-тренер',
                'icon' => 'crown',
                'color' => '#D80A7D',
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
            'pole-dance' => self::classType('Pole Dance', 'pole-dance', ScheduleKind::GroupClass, '#c7f000', 60, 12, 'pole-dance'),
            'pole-kids' => self::classType('Pole Kids', 'pole-kids', ScheduleKind::GroupClass, '#ffffff', 60, 8, 'kids'),
            'exot-easy' => self::classType('Exot Easy', 'exot-easy', ScheduleKind::GroupClass, '#c7f000', 60, 10, 'exotic'),
            'exot' => self::classType('Exot', 'exot', ScheduleKind::GroupClass, '#ff008c', 60, 10, 'exotic'),
            'exot-middle' => self::classType('Exot Middle', 'exot-middle', ScheduleKind::GroupClass, '#ffad00', 60, 10, 'exotic'),
            'stretching' => self::classType('Stretching', 'stretching', ScheduleKind::GroupClass, '#ff2b2b', 60, 12, 'stretching'),
            'tricks' => self::classType('Tricks', 'tricks', ScheduleKind::GroupClass, '#ff008c', 60, 10, 'acro'),
            'acro-class' => self::classType('Acro class*', 'acro-class', ScheduleKind::GroupClass, '#ffffff', 60, 12, 'acro'),
            'individualne-60-xv' => self::classType('Індивідуальне 60 хв', 'individualne-60-xv', ScheduleKind::PrivateLesson, '#d80a7d', 60, 2),
            'individualne-90-xv' => self::classType('Індивідуальне 90 хв', 'individualne-90-xv', ScheduleKind::PrivateLesson, '#d80a7d', 90, 2),
            'orenda-60-xv' => self::classType('Оренда 60 хв', 'orenda-60-xv', ScheduleKind::RoomRental, '#3b223f', 60, 12),
            'orenda-90-xv' => self::classType('Оренда 90 хв', 'orenda-90-xv', ScheduleKind::RoomRental, '#3b223f', 90, 12),
            'orenda-120-xv' => self::classType('Оренда 120 хв', 'orenda-120-xv', ScheduleKind::RoomRental, '#3b223f', 120, 12),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function classPassPlans(): array
    {
        $groupClassTypes = ['pole-dance', 'pole-kids', 'exot-easy', 'exot', 'exot-middle', 'stretching', 'tricks', 'acro-class'];
        $groupTrainerTypes = ['trainer', 'top'];

        return [
            'trial-class' => self::plan('Пробне заняття', 'trial-class', 'Пробне заняття для нового клієнта.', 25000, 1, null, 5, true, $groupClassTypes, $groupTrainerTypes),
            'full-day-start' => self::plan('START повний день', 'full-day-start', 'Повний абонемент на 4 заняття.', 150000, 4, null, 10, false, $groupClassTypes, $groupTrainerTypes),
            'full-day-amateur' => self::plan('AMATEUR повний день', 'full-day-amateur', 'Повний абонемент на 6 занять.', 200000, 6, null, 20, false, $groupClassTypes, $groupTrainerTypes),
            'full-day-base' => self::plan('BASE повний день', 'full-day-base', 'Повний абонемент на 8 занять.', 250000, 8, null, 30, false, $groupClassTypes, $groupTrainerTypes),
            'full-day-semi-pro' => self::plan('Semi pro повний день', 'full-day-semi-pro', 'Повний абонемент на 12 занять.', 350000, 12, null, 40, false, $groupClassTypes, $groupTrainerTypes),
            'full-day-pro' => self::plan('Pro повний день', 'full-day-pro', 'Повний абонемент на 16 занять.', 440000, 16, null, 50, false, $groupClassTypes, $groupTrainerTypes),
            'morning-start' => self::plan('START ранок', 'morning-start', 'Ранковий абонемент на 4 заняття до 12:00.', 140000, 4, '12:00', 60, false, $groupClassTypes, $groupTrainerTypes),
            'morning-amateur' => self::plan('AMATEUR ранок', 'morning-amateur', 'Ранковий абонемент на 6 занять до 12:00.', 190000, 6, '12:00', 70, false, $groupClassTypes, $groupTrainerTypes),
            'morning-base' => self::plan('BASE ранок', 'morning-base', 'Ранковий абонемент на 8 занять до 12:00.', 240000, 8, '12:00', 80, false, $groupClassTypes, $groupTrainerTypes),
            'morning-semi-pro' => self::plan('Semi pro ранок', 'morning-semi-pro', 'Ранковий абонемент на 12 занять до 12:00.', 310000, 12, '12:00', 90, false, $groupClassTypes, $groupTrainerTypes),
            'morning-pro' => self::plan('Pro ранок', 'morning-pro', 'Ранковий абонемент на 16 занять до 12:00.', 390000, 16, '12:00', 100, false, $groupClassTypes, $groupTrainerTypes),
            'private-top-60' => self::plan('TOP-1', 'private-top-60', '1 год. з ТОП-тренером для 1 людини.', 110000, 1, null, 200, false, ['individualne-60-xv'], ['top']),
            'private-top-90' => self::plan('TOP-1.5', 'private-top-90', '1.5 год. з ТОП-тренером для 1 людини.', 160000, 1, null, 210, false, ['individualne-90-xv'], ['top']),
            'private-standard-60' => self::plan('STANDART-1', 'private-standard-60', '1 год. з тренером для 1 людини.', 100000, 1, null, 220, false, ['individualne-60-xv'], ['trainer']),
            'private-standard-90' => self::plan('STANDART-1.5', 'private-standard-90', '1.5 год. з тренером для 1 людини.', 140000, 1, null, 230, false, ['individualne-90-xv'], ['trainer']),
            'big-hall-rental-60' => self::plan('Великий зал 1г', 'big-hall-rental-60', 'Оренда великого залу на 1 годину.', 55000, 1, null, 300, false, ['orenda-60-xv'], [], ['big-hall']),
            'big-hall-rental-90' => self::plan('Великий зал 1.5г', 'big-hall-rental-90', 'Оренда великого залу на 1.5 години.', 65000, 1, null, 310, false, ['orenda-90-xv'], [], ['big-hall']),
            'big-hall-rental-120' => self::plan('Великий зал 2г', 'big-hall-rental-120', 'Оренда великого залу на 2 години.', 85000, 1, null, 320, false, ['orenda-120-xv'], [], ['big-hall']),
            'small-hall-rental-60' => self::plan('Малий зал 1г', 'small-hall-rental-60', 'Оренда малого залу на 1 годину.', 40000, 1, null, 330, false, ['orenda-60-xv'], [], ['small-hall']),
            'small-hall-rental-90' => self::plan('Малий зал 1.5г', 'small-hall-rental-90', 'Оренда малого залу на 1.5 години.', 60000, 1, null, 340, false, ['orenda-90-xv'], [], ['small-hall']),
            'small-hall-rental-120' => self::plan('Малий зал 2г', 'small-hall-rental-120', 'Оренда малого залу на 2 години.', 70000, 1, null, 350, false, ['orenda-120-xv'], [], ['small-hall']),
        ];
    }

    /**
     * @return array<int, array{weekday: int, start_time: string, class_type_slug: string, trainer_name: string}>
     */
    public static function scheduleRows(): array
    {
        return collect([
            [1, '09:00', 'exot-easy', 'Настя'],
            [1, '10:00', 'pole-dance', 'Настя'],
            [1, '11:00', 'stretching', 'Настя'],
            [1, '16:00', 'pole-dance', 'Настя'],
            [1, '17:00', 'exot-easy', 'Настя'],
            [1, '18:00', 'pole-dance', 'Катя'],
            [1, '19:00', 'exot-middle', 'Катя'],
            [1, '20:00', 'pole-dance', 'Катя'],
            [2, '09:00', 'tricks', 'Slastya'],
            [2, '10:00', 'exot', 'Slastya'],
            [2, '16:00', 'pole-kids', 'Ліза'],
            [2, '17:00', 'pole-kids', 'Ліза'],
            [2, '18:00', 'pole-dance', 'Ліза'],
            [2, '19:00', 'pole-dance', 'Аліна'],
            [2, '20:00', 'exot-easy', 'Аліна'],
            [3, '09:00', 'exot-easy', 'Настя'],
            [3, '10:00', 'pole-dance', 'Настя'],
            [3, '11:00', 'stretching', 'Настя'],
            [3, '16:00', 'pole-dance', 'Настя'],
            [3, '17:00', 'exot-easy', 'Настя'],
            [3, '18:00', 'pole-dance', 'Катя'],
            [3, '19:00', 'exot-middle', 'Катя'],
            [3, '20:00', 'pole-dance', 'Катя'],
            [4, '09:00', 'stretching', 'Slastya'],
            [4, '10:00', 'exot', 'Slastya'],
            [4, '16:00', 'pole-kids', 'Ліза'],
            [4, '17:00', 'stretching', 'Женя'],
            [4, '18:00', 'pole-dance', 'Женя'],
            [4, '19:00', 'pole-dance', 'Женя'],
            [4, '20:00', 'exot-easy', 'Аліна'],
            [5, '18:00', 'pole-dance', 'Катя'],
            [5, '19:00', 'exot-middle', 'Катя'],
            [6, '09:00', 'acro-class', '_loco_man'],
            [6, '11:00', 'exot-easy', 'Настя'],
            [6, '12:00', 'stretching', 'Настя'],
            [6, '13:00', 'pole-dance', 'Настя'],
            [7, '10:00', 'pole-dance', 'Женя'],
            [7, '11:00', 'pole-dance', 'Женя'],
            [7, '12:00', 'stretching', 'Женя'],
            [7, '13:00', 'exot-easy', 'Аліна'],
        ])->map(fn (array $row): array => [
            'weekday' => $row[0],
            'start_time' => $row[1],
            'class_type_slug' => $row[2],
            'trainer_name' => $row[3],
        ])->all();
    }

    /**
     * @return array<int, array{name: string, phone: string, email: string}>
     */
    public static function customers(): array
    {
        return [
            ['name' => 'Олена Коваль', 'phone' => '+380671112233', 'email' => 'olena.koval@example.com'],
            ['name' => 'Марія Шевченко', 'phone' => '+380501234567', 'email' => 'maria.shevchenko@example.com'],
            ['name' => 'Анна Мельник', 'phone' => '+380931234567', 'email' => 'anna.melnyk@example.com'],
            ['name' => 'Катерина Бондар', 'phone' => '+380681234567', 'email' => 'kateryna.bondar@example.com'],
            ['name' => 'Юлія Мороз', 'phone' => '+380661234567', 'email' => 'yuliia.moroz@example.com'],
            ['name' => 'Ірина Савчук', 'phone' => '+380991234567', 'email' => 'iryna.savchuk@example.com'],
            ['name' => 'Наталія Ткаченко', 'phone' => '+380631234567', 'email' => 'nataliia.tkachenko@example.com'],
            ['name' => 'Дарина Лисенко', 'phone' => '+380731234567', 'email' => 'daryna.lysenko@example.com'],
            ['name' => 'Sofia Parker', 'phone' => '+380971234567', 'email' => 'sofia.parker@example.com'],
            ['name' => 'Alina Dance', 'phone' => '+380951234567', 'email' => 'alina.dance@example.com'],
        ];
    }

    /**
     * @return array<int, array{customer_email: string, plan_slug: string, code: string}>
     */
    public static function customerClassPasses(): array
    {
        return [
            ['customer_email' => 'olena.koval@example.com', 'plan_slug' => 'full-day-start', 'code' => 'LDNA-1001'],
            ['customer_email' => 'maria.shevchenko@example.com', 'plan_slug' => 'morning-start', 'code' => 'LDNA-1002'],
            ['customer_email' => 'anna.melnyk@example.com', 'plan_slug' => 'private-top-60', 'code' => 'LDNA-1003'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function classType(
        string $name,
        string $slug,
        ScheduleKind $scheduleKind,
        string $color,
        int $durationMinutes,
        int $capacity,
        ?string $directionSlug = null,
    ): array {
        return [
            'name' => $name,
            'slug' => $slug,
            'direction_slug' => $directionSlug,
            'description' => null,
            'color' => $color,
            'schedule_kind' => $scheduleKind->value,
            'default_duration_minutes' => $durationMinutes,
            'booking_cutoff_minutes' => 60,
            'default_capacity' => $capacity,
            'is_active' => true,
        ];
    }

    /**
     * @param  array<int, string>  $classTypeSlugs
     * @param  array<int, string>  $trainerTypeKeys
     * @param  array<int, string>  $roomSlugs
     * @return array<string, mixed>
     */
    private static function plan(
        string $name,
        string $slug,
        string $description,
        int $priceCents,
        int $sessionsCount,
        ?string $availableUntilTime,
        int $sortOrder,
        bool $isTrial,
        array $classTypeSlugs,
        array $trainerTypeKeys,
        array $roomSlugs = [],
    ): array {
        return [
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'price_cents' => $priceCents,
            'sessions_count' => $sessionsCount,
            'validity_days' => 30,
            'available_from_time' => null,
            'available_until_time' => $availableUntilTime,
            'allows_any_time' => false,
            'any_time_addon_price_cents' => null,
            'is_trial' => $isTrial,
            'is_active' => true,
            'sort_order' => $sortOrder,
            'class_type_slugs' => $classTypeSlugs,
            'trainer_type_keys' => $trainerTypeKeys,
            'room_slugs' => $roomSlugs,
        ];
    }
}
