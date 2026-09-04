<?php

namespace App\Support\Promotions;

use App\Enums\PromoCodeDiscountType;

class PromotionDiscountCalculator
{
    /**
     * @param  array<int|string, int>  $lineSubtotals
     * @param  array<int|string>  $eligibleLineIds
     */
    public function calculate(
        array $lineSubtotals,
        array $eligibleLineIds,
        PromoCodeDiscountType $discountType,
        int $discountValue,
    ): PromotionQuote {
        $normalizedLineSubtotals = collect($lineSubtotals)
            ->map(fn (mixed $subtotal): int => max(0, (int) $subtotal))
            ->all();
        $eligibleLookup = array_fill_keys(array_map('strval', $eligibleLineIds), true);
        $eligibleLines = collect($normalizedLineSubtotals)
            ->filter(fn (int $subtotal, int|string $lineId): bool => $subtotal > 0 && isset($eligibleLookup[(string) $lineId]))
            ->all();
        $subtotalCents = (int) array_sum($normalizedLineSubtotals);
        $eligibleSubtotalCents = (int) array_sum($eligibleLines);

        $discountCents = match ($discountType) {
            PromoCodeDiscountType::Fixed => min(max(0, $discountValue), $eligibleSubtotalCents),
            PromoCodeDiscountType::Percent => min(
                $eligibleSubtotalCents,
                intdiv(($eligibleSubtotalCents * max(0, min(100, $discountValue))) + 50, 100),
            ),
        };

        return new PromotionQuote(
            subtotalCents: $subtotalCents,
            eligibleSubtotalCents: $eligibleSubtotalCents,
            discountCents: $discountCents,
            totalCents: max(0, $subtotalCents - $discountCents),
            lineDiscounts: $this->allocateLineDiscounts($eligibleLines, $eligibleSubtotalCents, $discountCents),
        );
    }

    /**
     * @param  array<int|string, int>  $eligibleLines
     * @return array<int|string, int>
     */
    private function allocateLineDiscounts(array $eligibleLines, int $eligibleSubtotalCents, int $discountCents): array
    {
        $discounts = array_fill_keys(array_keys($eligibleLines), 0);

        if ($eligibleSubtotalCents === 0 || $discountCents === 0) {
            return $discounts;
        }

        $remainders = [];
        $allocatedCents = 0;

        foreach ($eligibleLines as $lineId => $lineSubtotalCents) {
            $weightedDiscount = $lineSubtotalCents * $discountCents;
            $discounts[$lineId] = intdiv($weightedDiscount, $eligibleSubtotalCents);
            $allocatedCents += $discounts[$lineId];
            $remainders[] = [
                'line_id' => $lineId,
                'remainder' => $weightedDiscount % $eligibleSubtotalCents,
            ];
        }

        usort($remainders, static function (array $left, array $right): int {
            $remainderOrder = $right['remainder'] <=> $left['remainder'];

            return $remainderOrder !== 0
                ? $remainderOrder
                : ((string) $left['line_id'] <=> (string) $right['line_id']);
        });

        for ($remainingCents = $discountCents - $allocatedCents; $remainingCents > 0; $remainingCents--) {
            $lineId = $remainders[($discountCents - $allocatedCents - $remainingCents) % count($remainders)]['line_id'];
            $discounts[$lineId]++;
        }

        return $discounts;
    }
}
