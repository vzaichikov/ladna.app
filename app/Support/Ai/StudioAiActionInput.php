<?php

namespace App\Support\Ai;

use DateTimeImmutable;

class StudioAiActionInput
{
    private const AllowedKeys = [
        'customer_id',
        'scheduled_class_id',
        'customer_query',
        'trainer_query',
        'date',
        'booking_id',
        'option_number',
        'option_label',
        'use_actor_trainer',
    ];

    public function __construct(
        public readonly ?int $customerId = null,
        public readonly ?int $scheduledClassId = null,
        public readonly ?string $customerQuery = null,
        public readonly ?string $trainerQuery = null,
        public readonly ?string $date = null,
        public readonly ?int $bookingId = null,
        public readonly ?int $optionNumber = null,
        public readonly ?string $optionLabel = null,
        public readonly bool $useActorTrainer = false,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): ?self
    {
        if (array_diff(array_keys($input), self::AllowedKeys) !== []) {
            return null;
        }

        foreach (['customer_id', 'scheduled_class_id', 'booking_id', 'option_number'] as $key) {
            if (array_key_exists($key, $input)
                && $input[$key] !== null
                && self::positiveInteger($input[$key]) === null) {
                return null;
            }
        }

        foreach (['customer_query', 'trainer_query', 'option_label'] as $key) {
            if (array_key_exists($key, $input)
                && $input[$key] !== null
                && self::string($input[$key]) === null) {
                return null;
            }
        }

        if (array_key_exists('date', $input)
            && $input['date'] !== null
            && self::string($input['date'], 10) === null) {
            return null;
        }

        if (array_key_exists('use_actor_trainer', $input)
            && $input['use_actor_trainer'] !== null
            && ! is_bool($input['use_actor_trainer'])) {
            return null;
        }

        $date = self::string($input['date'] ?? null, 10);

        if ($date !== null && ! self::isIsoDate($date)) {
            return null;
        }

        return new self(
            customerId: self::positiveInteger($input['customer_id'] ?? null),
            scheduledClassId: self::positiveInteger($input['scheduled_class_id'] ?? null),
            customerQuery: self::string($input['customer_query'] ?? null),
            trainerQuery: self::string($input['trainer_query'] ?? null),
            date: $date,
            bookingId: self::positiveInteger($input['booking_id'] ?? null),
            optionNumber: self::positiveInteger($input['option_number'] ?? null),
            optionLabel: self::string($input['option_label'] ?? null),
            useActorTrainer: ($input['use_actor_trainer'] ?? false) === true,
        );
    }

    public function hasDialogInput(): bool
    {
        return $this->customerQuery !== null
            || $this->trainerQuery !== null
            || $this->date !== null
            || $this->optionNumber !== null
            || $this->optionLabel !== null
            || $this->useActorTrainer;
    }

    public function hasOnlyBookingStartInput(): bool
    {
        return $this->bookingId === null
            && $this->optionNumber === null
            && $this->optionLabel === null;
    }

    public function hasOnlyBookingDialogInput(): bool
    {
        return $this->customerId === null
            && $this->scheduledClassId === null
            && $this->bookingId === null
            && $this->hasDialogInput();
    }

    public function hasOnlyBookingCancellationInput(): bool
    {
        return $this->bookingId !== null
            && $this->customerId === null
            && $this->scheduledClassId === null
            && $this->customerQuery === null
            && $this->trainerQuery === null
            && $this->date === null
            && $this->optionNumber === null
            && $this->optionLabel === null
            && ! $this->useActorTrainer;
    }

    public function isEmpty(): bool
    {
        return $this->customerId === null
            && $this->scheduledClassId === null
            && $this->customerQuery === null
            && $this->trainerQuery === null
            && $this->date === null
            && $this->bookingId === null
            && $this->optionNumber === null
            && $this->optionLabel === null
            && ! $this->useActorTrainer;
    }

    private static function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $integer = (int) $value;

            return $integer > 0 ? $integer : null;
        }

        return null;
    }

    private static function string(mixed $value, int $limit = 120): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $limit) {
            return null;
        }

        return $value;
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
