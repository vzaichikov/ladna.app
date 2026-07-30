<?php

namespace App\Support\Ai;

use Illuminate\Support\Carbon;

class StudioAiFirewallDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly ?string $scope = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?Carbon $blockedUntil = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(
        string $reason,
        string $scope,
        int $retryAfterSeconds,
        ?Carbon $blockedUntil = null,
    ): self {
        return new self(
            allowed: false,
            reason: $reason,
            scope: $scope,
            retryAfterSeconds: max(1, $retryAfterSeconds),
            blockedUntil: $blockedUntil,
        );
    }
}
