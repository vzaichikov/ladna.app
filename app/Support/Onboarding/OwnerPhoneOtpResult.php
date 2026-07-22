<?php

namespace App\Support\Onboarding;

use App\Models\UserPhoneOtpChallenge;

class OwnerPhoneOtpResult
{
    public function __construct(
        public bool $ok,
        public ?UserPhoneOtpChallenge $challenge = null,
        public ?string $message = null,
        public int $secondsUntilResend = 0,
        public ?string $debugCode = null,
    ) {}

    public static function ok(UserPhoneOtpChallenge $challenge, int $secondsUntilResend, ?string $debugCode = null): self
    {
        return new self(true, $challenge, secondsUntilResend: $secondsUntilResend, debugCode: $debugCode);
    }

    public static function failed(string $message, ?UserPhoneOtpChallenge $challenge = null, int $secondsUntilResend = 0): self
    {
        return new self(false, $challenge, $message, $secondsUntilResend);
    }
}
