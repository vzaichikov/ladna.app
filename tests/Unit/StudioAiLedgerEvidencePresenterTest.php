<?php

namespace Tests\Unit;

use App\Support\Ai\StudioAiLedgerEvidencePresenter;
use Tests\TestCase;

class StudioAiLedgerEvidencePresenterTest extends TestCase
{
    public function test_it_replaces_raw_cents_and_calculates_outstanding_totals(): void
    {
        $payload = [
            'status' => 'found',
            'passes' => [
                $this->pass('FIRST', 110000, 0, 110000, true),
                $this->pass('SECOND', 150000, 40000, 110000, true),
                $this->pass('PAID', 80000, 80000, 0, false),
            ],
            'truncation' => [
                'passes' => ['truncated' => false],
            ],
            'trial_eligibility' => [
                'trial_plans' => [
                    'items' => [[
                        'name' => 'Trial',
                        'price_cents' => 25000,
                        'currency' => 'UAH',
                    ]],
                ],
            ],
        ];

        $presented = app(StudioAiLedgerEvidencePresenter::class)->present($payload);

        $this->assertSame('1100.00', data_get($presented, 'passes.0.remaining_payment.amount'));
        $this->assertSame('1 100 ₴', data_get($presented, 'passes.0.remaining_payment.formatted'));
        $this->assertArrayNotHasKey('price_cents', $presented['passes'][0]);
        $this->assertArrayNotHasKey('paid_amount_cents', $presented['passes'][0]);
        $this->assertArrayNotHasKey('remaining_payment_cents', $presented['passes'][0]);
        $this->assertTrue(data_get($presented, 'monetary_summary.totals_calculated_by_ladna'));
        $this->assertTrue(data_get($presented, 'monetary_summary.evidence_complete'));
        $this->assertSame('2200.00', data_get($presented, 'monetary_summary.outstanding_by_currency.0.amount'));
        $this->assertSame('2 200 ₴', data_get($presented, 'monetary_summary.outstanding_by_currency.0.formatted'));
        $this->assertSame(2, data_get($presented, 'monetary_summary.outstanding_by_currency.0.pass_count'));
        $this->assertSame('250.00', data_get($presented, 'trial_eligibility.trial_plans.items.0.price.amount'));
        $this->assertArrayNotHasKey('price_cents', $presented['trial_eligibility']['trial_plans']['items'][0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pass(
        string $code,
        int $priceCents,
        int $paidAmountCents,
        int $remainingPaymentCents,
        bool $hasOutstandingBalance,
    ): array {
        return [
            'code' => $code,
            'price_cents' => $priceCents,
            'paid_amount_cents' => $paidAmountCents,
            'remaining_payment_cents' => $remainingPaymentCents,
            'currency' => 'UAH',
            'has_outstanding_balance' => $hasOutstandingBalance,
        ];
    }
}
