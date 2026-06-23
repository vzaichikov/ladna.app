<?php

namespace App\Support\CustomerAuth;

class CustomerAuthMethodAvailability
{
    public function __construct(
        public bool $emailPassword,
        public bool $otp,
        public bool $google,
        public ?string $turnstileSiteKey = null,
    ) {}

    public function hasAnyMethod(): bool
    {
        return $this->emailPassword || $this->otp || $this->google;
    }
}
