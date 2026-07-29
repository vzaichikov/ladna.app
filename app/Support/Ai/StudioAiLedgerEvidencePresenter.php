<?php

namespace App\Support\Ai;

use App\Support\MoneyFormatter;
use App\Support\Payments\PaymentAmounts;

class StudioAiLedgerEvidencePresenter
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function present(array $payload): array
    {
        if (($payload['status'] ?? null) !== 'found' || ! is_array($payload['passes'] ?? null)) {
            return $payload;
        }

        $outstandingByCurrency = [];
        $payload['passes'] = collect($payload['passes'])
            ->map(function (mixed $pass) use (&$outstandingByCurrency): mixed {
                if (! is_array($pass)) {
                    return $pass;
                }

                $currency = strtoupper((string) ($pass['currency'] ?? 'UAH'));
                $priceCents = (int) ($pass['price_cents'] ?? 0);
                $paidAmountCents = (int) ($pass['paid_amount_cents'] ?? 0);
                $remainingPaymentCents = (int) ($pass['remaining_payment_cents'] ?? 0);

                unset(
                    $pass['price_cents'],
                    $pass['paid_amount_cents'],
                    $pass['remaining_payment_cents'],
                );

                $pass['price'] = $this->money($priceCents, $currency);
                $pass['paid_amount'] = $this->money($paidAmountCents, $currency);
                $pass['remaining_payment'] = $this->money($remainingPaymentCents, $currency);

                if (($pass['has_outstanding_balance'] ?? false) === true && $remainingPaymentCents > 0) {
                    $outstandingByCurrency[$currency] ??= [
                        'currency' => $currency,
                        'amount_cents' => 0,
                        'pass_count' => 0,
                    ];
                    $outstandingByCurrency[$currency]['amount_cents'] += $remainingPaymentCents;
                    $outstandingByCurrency[$currency]['pass_count']++;
                }

                return $pass;
            })
            ->all();

        $trialPlans = data_get($payload, 'trial_eligibility.trial_plans.items');

        if (is_array($trialPlans)) {
            data_set(
                $payload,
                'trial_eligibility.trial_plans.items',
                collect($trialPlans)
                    ->map(function (mixed $trialPlan): mixed {
                        if (! is_array($trialPlan)) {
                            return $trialPlan;
                        }

                        $currency = strtoupper((string) ($trialPlan['currency'] ?? 'UAH'));
                        $priceCents = (int) ($trialPlan['price_cents'] ?? 0);
                        unset($trialPlan['price_cents']);
                        $trialPlan['price'] = $this->money($priceCents, $currency);

                        return $trialPlan;
                    })
                    ->all(),
            );
        }

        $payload['monetary_summary'] = [
            'unit' => 'major_currency_units',
            'totals_calculated_by_ladna' => true,
            'evidence_complete' => data_get($payload, 'truncation.passes.truncated') === false,
            'outstanding_by_currency' => collect($outstandingByCurrency)
                ->map(function (array $summary): array {
                    $amountCents = $summary['amount_cents'];
                    unset($summary['amount_cents']);

                    return [
                        ...$summary,
                        ...$this->money($amountCents, $summary['currency']),
                    ];
                })
                ->values()
                ->all(),
        ];

        return $payload;
    }

    /**
     * @return array{amount: string, currency: string, formatted: string}
     */
    private function money(int $amountCents, string $currency): array
    {
        return [
            'amount' => PaymentAmounts::centsToDecimalString($amountCents),
            'currency' => $currency,
            'formatted' => MoneyFormatter::format($amountCents, $currency),
        ];
    }
}
