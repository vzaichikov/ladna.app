<?php

namespace App\Support\Ai;

use DateTimeImmutable;

class StudioAiCalendarReference
{
    private const AllowedKeys = [
        'date',
        'uses_schedule_details',
    ];

    public function __construct(
        public readonly string $date,
        public readonly bool $usesScheduleDetails,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): ?self
    {
        if (array_diff(self::AllowedKeys, array_keys($input)) !== []
            || array_diff(array_keys($input), self::AllowedKeys) !== []) {
            return null;
        }

        $date = $input['date'];
        $usesScheduleDetails = $input['uses_schedule_details'];

        if (! is_string($date) || ! self::isIsoDate($date)) {
            return null;
        }

        if (! is_bool($usesScheduleDetails)) {
            return null;
        }

        return new self($date, $usesScheduleDetails);
    }

    /**
     * @param  array<string, mixed>  $classBookingDetails
     */
    public function matchesClassBookingDetails(array $classBookingDetails): bool
    {
        foreach ($classBookingDetails as $details) {
            if (! is_array($details) || ($details['date'] ?? null) !== $this->date) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array<int, array{date: string, weekday: string, iso_weekday: int}>  $calendarAnchors
     */
    public function existsInCalendarAnchors(array $calendarAnchors): bool
    {
        return collect($calendarAnchors)->contains('date', $this->date);
    }

    /**
     * @return array{date: string, uses_schedule_details: bool}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'uses_schedule_details' => $this->usesScheduleDetails,
        ];
    }

    private static function isIsoDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $date;
    }
}
