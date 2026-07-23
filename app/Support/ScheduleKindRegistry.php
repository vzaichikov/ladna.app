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
                'enabled_by_default' => true,
                'admin_one_off' => true,
                'recurring' => true,
                'customer_bookable' => true,
                'class_pass_eligible' => true,
                'trainer_reportable' => true,
                'public_schedule' => true,
                'trainer_required' => false,
                'people_counter_trainer_adjustment' => 1,
                'full_occurrence_editable' => false,
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
                'enabled_by_default' => true,
                'admin_one_off' => false,
                'recurring' => false,
                'customer_bookable' => true,
                'class_pass_eligible' => true,
                'trainer_reportable' => true,
                'public_schedule' => false,
                'trainer_required' => true,
                'people_counter_trainer_adjustment' => 0,
                'full_occurrence_editable' => false,
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
                'enabled_by_default' => true,
                'admin_one_off' => false,
                'recurring' => false,
                'customer_bookable' => true,
                'class_pass_eligible' => true,
                'trainer_reportable' => true,
                'public_schedule' => false,
                'trainer_required' => false,
                'people_counter_trainer_adjustment' => 0,
                'full_occurrence_editable' => false,
                'default_is_public' => false,
            ],
            ScheduleKind::InternalClass->value => [
                'kind' => ScheduleKind::InternalClass,
                'route_name' => 'internal-classes',
                'title_key' => 'internal_classes',
                'copy_key' => 'internal_classes_copy',
                'create_key' => 'create_internal_class',
                'empty_key' => 'no_internal_classes',
                'icon' => 'lock',
                'default_color' => '#F59E0B',
                'capacity_label_key' => 'capacity',
                'manual_record' => false,
                'enabled_by_default' => false,
                'admin_one_off' => true,
                'recurring' => false,
                'customer_bookable' => false,
                'class_pass_eligible' => false,
                'trainer_reportable' => false,
                'public_schedule' => false,
                'trainer_required' => true,
                'people_counter_trainer_adjustment' => 1,
                'full_occurrence_editable' => true,
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
        return collect(self::all())
            ->filter(fn (array $definition): bool => (bool) $definition['enabled_by_default'])
            ->keys()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        return array_keys(self::all());
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    public static function validValues(array $values): array
    {
        $allowedValues = self::allValues();

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
        return self::kindsWithCapability('manual_record');
    }

    /**
     * @return array<int, ScheduleKind>
     */
    public static function oneOffRecordKinds(): array
    {
        return self::kindsWithCapability('admin_one_off');
    }

    /**
     * @return array<int, ScheduleKind>
     */
    public static function customerBookableKinds(): array
    {
        return self::kindsWithCapability('customer_bookable');
    }

    /**
     * @return array<int, ScheduleKind>
     */
    public static function classPassEligibleKinds(): array
    {
        return self::kindsWithCapability('class_pass_eligible');
    }

    /**
     * @return array<int, ScheduleKind>
     */
    public static function trainerReportableKinds(): array
    {
        return self::kindsWithCapability('trainer_reportable');
    }

    /**
     * @return array<int, string>
     */
    public static function classPassEligibleValues(): array
    {
        return array_map(
            fn (ScheduleKind $scheduleKind): string => $scheduleKind->value,
            self::classPassEligibleKinds(),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function customerBookableValues(): array
    {
        return array_map(
            fn (ScheduleKind $scheduleKind): string => $scheduleKind->value,
            self::customerBookableKinds(),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function trainerReportableValues(): array
    {
        return array_map(
            fn (ScheduleKind $scheduleKind): string => $scheduleKind->value,
            self::trainerReportableKinds(),
        );
    }

    public static function hasCapability(ScheduleKind $scheduleKind, string $capability): bool
    {
        return (bool) (self::get($scheduleKind)[$capability] ?? false);
    }

    public static function routeName(ScheduleKind $scheduleKind, string $action): string
    {
        return 'dashboard.accounts.'.self::get($scheduleKind)['route_name'].'.'.$action;
    }

    /**
     * @return array<int, ScheduleKind>
     */
    private static function kindsWithCapability(string $capability): array
    {
        return collect(self::all())
            ->filter(fn (array $definition): bool => (bool) ($definition[$capability] ?? false))
            ->map(fn (array $definition): ScheduleKind => $definition['kind'])
            ->values()
            ->all();
    }
}
