<?php

namespace App\Support\Finance;

use App\Enums\PayrollCadence;
use App\Models\Account;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class PayrollPeriodResolver
{
    /**
     * @return array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}
     */
    public function containing(Account $account, CarbonInterface|string $date): array
    {
        $localDate = $this->localDate($account, $date);

        return match ($account->payroll_cadence) {
            PayrollCadence::Weekly => [
                'starts_on' => $localDate->startOfWeek(CarbonInterface::MONDAY),
                'ends_on' => $localDate->endOfWeek(CarbonInterface::SUNDAY),
            ],
            PayrollCadence::Biweekly => $this->biweeklyPeriod($account, $localDate),
            PayrollCadence::Monthly => [
                'starts_on' => $localDate->startOfMonth(),
                'ends_on' => $localDate->endOfMonth(),
            ],
        };
    }

    /**
     * @return array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}
     */
    public function latestCompleted(Account $account, ?CarbonInterface $asOf = null): array
    {
        $today = $this->localDate($account, $asOf ?? now());
        $currentPeriod = $this->containing($account, $today);

        return $this->containing($account, $currentPeriod['starts_on']->subDay());
    }

    public function matches(
        Account $account,
        CarbonInterface|string $startsOn,
        CarbonInterface|string $endsOn,
    ): bool {
        $start = $this->localDate($account, $startsOn);
        $end = $this->localDate($account, $endsOn);
        $period = $this->containing($account, $start);

        return $period['starts_on']->isSameDay($start)
            && $period['ends_on']->isSameDay($end);
    }

    public function isCompleted(
        Account $account,
        CarbonInterface|string $endsOn,
        ?CarbonInterface $asOf = null,
    ): bool {
        $end = $this->localDate($account, $endsOn);
        $today = $this->localDate($account, $asOf ?? now());

        return $end->lessThan($today);
    }

    /**
     * @return array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}
     */
    private function biweeklyPeriod(Account $account, CarbonImmutable $date): array
    {
        $anchor = $account->payroll_anchor_date
            ? $this->localDate($account, $account->payroll_anchor_date)
            : $this->localDate($account, $account->created_at ?? $date)->startOfWeek(CarbonInterface::MONDAY);
        $daysFromAnchor = (int) $anchor->diffInDays($date, false);
        $periodOffset = (int) floor($daysFromAnchor / 14);
        $startsOn = $anchor->addDays($periodOffset * 14);

        return [
            'starts_on' => $startsOn,
            'ends_on' => $startsOn->addDays(13),
        ];
    }

    private function localDate(Account $account, CarbonInterface|string $date): CarbonImmutable
    {
        $timezone = $account->timezone ?: config('app.timezone');

        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::instance($date)->setTimezone($timezone)->startOfDay();
        }

        return CarbonImmutable::parse($date, $timezone)->startOfDay();
    }
}
