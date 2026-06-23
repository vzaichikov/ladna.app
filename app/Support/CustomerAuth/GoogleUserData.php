<?php

namespace App\Support\CustomerAuth;

class GoogleUserData
{
    public function __construct(
        public string $id,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name,
    ) {}
}
