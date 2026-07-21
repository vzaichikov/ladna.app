<?php

namespace App\Support\SaasBilling;

use App\Models\SubscriptionPriceTier;
use InvalidArgumentException;

final class SubscriptionPriceTierValidator
{
    /**
     * @param  iterable<int, SubscriptionPriceTier|array<string, mixed>>  $tiers
     */
    public function assertValid(iterable $tiers): void
    {
        $errors = $this->errors($tiers);

        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
    }

    /**
     * @param  iterable<int, SubscriptionPriceTier|array<string, mixed>>  $tiers
     * @return list<string>
     */
    public function errors(iterable $tiers): array
    {
        $normalized = [];
        $errors = [];

        foreach ($tiers as $index => $tier) {
            $values = $tier instanceof SubscriptionPriceTier
                ? $tier->only(['starts_at_location', 'ends_at_location', 'unit_price_cents'])
                : $tier;
            $start = filter_var($values['starts_at_location'] ?? null, FILTER_VALIDATE_INT);
            $endValue = $values['ends_at_location'] ?? null;
            $end = $endValue === null || $endValue === ''
                ? null
                : filter_var($endValue, FILTER_VALIDATE_INT);
            $unitPrice = filter_var($values['unit_price_cents'] ?? null, FILTER_VALIDATE_INT);

            if ($start === false || $start < 1) {
                $errors[] = 'Tier '.((int) $index + 1).' must start at a positive location number.';

                continue;
            }

            if ($end === false || ($end !== null && $end < $start)) {
                $errors[] = 'Tier '.((int) $index + 1).' must end at or after its starting location.';

                continue;
            }

            if ($unitPrice === false || $unitPrice < 1) {
                $errors[] = 'Tier '.((int) $index + 1).' must have a positive unit price.';

                continue;
            }

            $normalized[] = [
                'start' => $start,
                'end' => $end,
            ];
        }

        if ($normalized === []) {
            $errors[] = 'At least one location pricing tier is required.';

            return array_values(array_unique($errors));
        }

        usort($normalized, fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        $expectedStart = 1;
        $lastIndex = count($normalized) - 1;

        foreach ($normalized as $index => $tier) {
            if ($tier['start'] !== $expectedStart) {
                $errors[] = $tier['start'] < $expectedStart
                    ? 'Location pricing tiers must not overlap.'
                    : 'Location pricing tiers must be contiguous and start at one.';
            }

            if ($tier['end'] === null) {
                if ($index !== $lastIndex) {
                    $errors[] = 'Only the final location pricing tier may be open-ended.';
                }

                continue;
            }

            $expectedStart = $tier['end'] + 1;
        }

        if ($normalized[$lastIndex]['end'] !== null) {
            $errors[] = 'The final location pricing tier must be open-ended.';
        }

        return array_values(array_unique($errors));
    }
}
