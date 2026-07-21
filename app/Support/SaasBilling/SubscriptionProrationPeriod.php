<?php

namespace App\Support\SaasBilling;

use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class SubscriptionProrationPeriod
{
    public function __construct(
        public CarbonInterface $periodStartsAt,
        public CarbonInterface $periodEndsAt,
        public CarbonInterface $effectiveAt,
    ) {
        if ($this->periodEndsAt->lessThanOrEqualTo($this->periodStartsAt)) {
            throw new InvalidArgumentException('The proration period end must be after its start.');
        }
    }

    public function remainingFraction(): float
    {
        if ($this->effectiveAt->lessThanOrEqualTo($this->periodStartsAt)) {
            return 1.0;
        }

        if ($this->effectiveAt->greaterThanOrEqualTo($this->periodEndsAt)) {
            return 0.0;
        }

        $periodSeconds = $this->periodEndsAt->getTimestamp() - $this->periodStartsAt->getTimestamp();
        $remainingSeconds = $this->periodEndsAt->getTimestamp() - $this->effectiveAt->getTimestamp();

        return $remainingSeconds / $periodSeconds;
    }
}
