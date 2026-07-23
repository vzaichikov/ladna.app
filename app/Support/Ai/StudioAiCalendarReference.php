<?php

namespace App\Support\Ai;

use DateTimeImmutable;
use Illuminate\Support\Str;

class StudioAiCalendarReference
{
    private const AllowedKeys = [
        'date',
        'requested_weekday',
        'weekday_occurrence',
        'uses_schedule_details',
    ];

    private const Weekdays = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    public function __construct(
        public readonly string $date,
        public readonly ?string $requestedWeekday,
        public readonly ?string $weekdayOccurrence,
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
        $requestedWeekday = $input['requested_weekday'];
        $weekdayOccurrence = $input['weekday_occurrence'];
        $usesScheduleDetails = $input['uses_schedule_details'];

        if (! is_string($date) || ! self::isIsoDate($date)) {
            return null;
        }

        if ($requestedWeekday !== null
            && (! is_string($requestedWeekday) || ! in_array($requestedWeekday, self::Weekdays, true))) {
            return null;
        }

        if (($requestedWeekday === null && $weekdayOccurrence !== null)
            || ($requestedWeekday !== null && ! in_array($weekdayOccurrence, ['first', 'next'], true))) {
            return null;
        }

        if (! is_bool($usesScheduleDetails)) {
            return null;
        }

        return new self($date, $requestedWeekday, $weekdayOccurrence, $usesScheduleDetails);
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

    public function matchesRequestedWeekday(): bool
    {
        if ($this->requestedWeekday === null) {
            return true;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $this->date);

        return $date !== false
            && Str::lower($date->format('l')) === $this->requestedWeekday;
    }

    /**
     * @param  array<int, array{date: string, weekday: string, iso_weekday: int}>  $calendarAnchors
     */
    public function matchesCalendarAnchors(array $calendarAnchors): bool
    {
        if ($this->requestedWeekday === null) {
            return true;
        }

        $matchingDates = collect($calendarAnchors)
            ->where('weekday', $this->requestedWeekday)
            ->pluck('date')
            ->values();
        $expectedIndex = $this->weekdayOccurrence === 'next' ? 1 : 0;

        return $matchingDates->get($expectedIndex) === $this->date;
    }

    /**
     * @param  array<int, array{date: string, weekday: string, iso_weekday: int}>  $calendarAnchors
     */
    public function existsInCalendarAnchors(array $calendarAnchors): bool
    {
        return collect($calendarAnchors)->contains('date', $this->date);
    }

    /**
     * @return array{date: string, requested_weekday: string|null, weekday_occurrence: string|null, uses_schedule_details: bool}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'requested_weekday' => $this->requestedWeekday,
            'weekday_occurrence' => $this->weekdayOccurrence,
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
