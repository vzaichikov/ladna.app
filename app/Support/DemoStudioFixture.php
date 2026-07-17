<?php

namespace App\Support;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassStatus;
use App\Enums\ScheduleKind;
use App\Enums\WebsiteLeadStatus;

class DemoStudioFixture
{
    public const AccountSlug = 'ladna-demo';

    public const PeopleCounterSampleCount = 14;

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
            'enabled_schedule_kinds' => ScheduleKindRegistry::defaultEnabledValues(),
            'schedule_kind_colors' => [
                ScheduleKind::GroupClass->value => '#8B6A9B',
                ScheduleKind::PrivateLesson->value => '#C7B4D3',
                ScheduleKind::RoomRental->value => '#D9B8C4',
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
            'public_schedule_view' => 'classic',
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
}
