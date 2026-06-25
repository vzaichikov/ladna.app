<?php

namespace App\Support;

use App\Enums\ScheduleKind;

class ScheduleKindRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            ScheduleKind::GroupClass->value => [
                'kind' => ScheduleKind::GroupClass,
                'route_name' => 'group-classes',
                'title_key' => 'group_classes',
                'copy_key' => 'group_classes_copy',
                'create_key' => 'create_group_class',
                'empty_key' => 'no_group_classes',
                'icon' => 'class-types',
                'default_color' => '#A3E635',
                'capacity_label_key' => 'capacity',
                'manual_record' => false,
                'recurring' => true,
                'public_schedule' => true,
                'default_is_public' => true,
            ],
            ScheduleKind::PrivateLesson->value => [
                'kind' => ScheduleKind::PrivateLesson,
                'route_name' => 'private-lessons',
                'title_key' => 'private_lessons',
                'copy_key' => 'private_lessons_copy',
                'create_key' => 'create_private_lesson',
                'empty_key' => 'no_private_lessons',
                'icon' => 'user-round',
                'default_color' => '#A78AB9',
                'capacity_label_key' => 'people_count',
                'manual_record' => true,
                'recurring' => false,
                'public_schedule' => false,
                'default_is_public' => false,
            ],
            ScheduleKind::RoomRental->value => [
                'kind' => ScheduleKind::RoomRental,
                'route_name' => 'room-rentals',
                'title_key' => 'room_rentals',
                'copy_key' => 'room_rentals_copy',
                'create_key' => 'create_room_rental',
                'empty_key' => 'no_room_rentals',
                'icon' => 'rooms',
                'default_color' => '#38BDF8',
                'capacity_label_key' => 'people_count',
                'manual_record' => true,
                'recurring' => false,
                'public_schedule' => false,
                'default_is_public' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(ScheduleKind $scheduleKind): array
    {
        return self::all()[$scheduleKind->value];
    }

    /**
     * @return array<int, string>
     */
    public static function defaultEnabledValues(): array
    {
        return array_keys(self::all());
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    public static function validValues(array $values): array
    {
        $allowedValues = self::defaultEnabledValues();

        return collect($values)
            ->map(fn (mixed $value): string => $value instanceof ScheduleKind ? $value->value : (string) $value)
            ->filter(fn (string $value): bool => in_array($value, $allowedValues, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function defaultColors(): array
    {
        return collect(self::all())
            ->mapWithKeys(fn (array $definition, string $value): array => [$value => $definition['default_color']])
            ->all();
    }

    /**
     * @return array<int, ScheduleKind>
     */
    public static function manualKinds(): array
    {
        return collect(self::all())
            ->filter(fn (array $definition): bool => (bool) $definition['manual_record'])
            ->map(fn (array $definition): ScheduleKind => $definition['kind'])
            ->values()
            ->all();
    }

    /**
     * @return array<int, ScheduleKind>
     */
    public static function oneOffRecordKinds(): array
    {
        return collect(self::all())
            ->map(fn (array $definition): ScheduleKind => $definition['kind'])
            ->values()
            ->all();
    }

    public static function routeName(ScheduleKind $scheduleKind, string $action): string
    {
        return 'dashboard.accounts.'.self::get($scheduleKind)['route_name'].'.'.$action;
    }
}
