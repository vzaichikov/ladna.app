<?php

namespace App\Enums;

enum PublicScheduleView
{
    case Classic;
    case CompactBooking;

    public function value(): string
    {
        return match ($this) {
            self::Classic => 'classic',
            self::CompactBooking => 'compact_booking',
        };
    }

    public function labelKey(): string
    {
        return 'app.public_schedule_view_'.$this->value();
    }

    public function copyKey(): string
    {
        return 'app.public_schedule_view_'.$this->value().'_copy';
    }

    public function viewName(): string
    {
        return match ($this) {
            self::Classic => 'public.schedule',
            self::CompactBooking => 'public.schedule-compact',
        };
    }

    public static function default(): self
    {
        return self::Classic;
    }

    public static function fromValue(mixed $value): self
    {
        return collect(self::cases())
            ->first(fn (self $view): bool => $view->value() === (string) $value)
            ?? self::default();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return collect(self::cases())
            ->map(fn (self $view): string => $view->value())
            ->all();
    }
}
