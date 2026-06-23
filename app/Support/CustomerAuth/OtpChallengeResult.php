<?php

namespace App\Support\CustomerAuth;

use App\Models\CustomerOtpChallenge;

class OtpChallengeResult
{
    public function __construct(
        public bool $ok,
        public ?CustomerOtpChallenge $challenge = null,
        public ?string $message = null,
        public int $secondsUntilResend = 0,
        public ?string $debugCode = null,
    ) {}

    public static function ok(CustomerOtpChallenge $challenge, int $secondsUntilResend, ?string $debugCode = null): self
    {
        return new self(true, $challenge, secondsUntilResend: $secondsUntilResend, debugCode: $debugCode);
    }

    public static function failed(string $message, ?CustomerOtpChallenge $challenge = null, int $secondsUntilResend = 0): self
    {
        return new self(false, $challenge, $message, $secondsUntilResend);
    }
}
