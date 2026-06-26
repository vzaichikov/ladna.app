<?php

namespace App\Support\Mail;

use App\Enums\MailEngine;

class MailDeliverySettings
{
    public function __construct(
        public readonly string $mailer,
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly MailEngine $engine,
        public readonly bool $configured,
    ) {}
}
