<?php

namespace App\Support\Festivals;

use App\Models\FestivalOtpChallenge;

class FestivalOtpResult
{
    public function __construct(
        public bool $ok,
        public ?FestivalOtpChallenge $challenge = null,
        public ?string $message = null,
        public int $secondsUntilResend = 0,
        public ?string $debugCode = null,
    ) {}

    public static function ok(FestivalOtpChallenge $challenge, int $secondsUntilResend, ?string $debugCode = null): self
    {
        return new self(true, $challenge, secondsUntilResend: $secondsUntilResend, debugCode: $debugCode);
    }

    public static function failed(string $message, ?FestivalOtpChallenge $challenge = null, int $secondsUntilResend = 0): self
    {
        return new self(false, $challenge, $message, $secondsUntilResend);
    }
}
