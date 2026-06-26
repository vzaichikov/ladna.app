<?php

namespace App\Enums;

enum MailEngine: string
{
    case SendpulseSmtp = 'sendpulse_smtp';
    case Smtp = 'smtp';
    case Sendmail = 'sendmail';
    case Log = 'log';

    public function labelKey(): string
    {
        return 'app.mail_engine_'.$this->value;
    }

    /**
     * @return array<int, string>
     */
    public static function smtpValues(): array
    {
        return [
            self::SendpulseSmtp->value,
            self::Smtp->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function localFallbackValues(): array
    {
        return [
            self::Sendmail->value,
            self::Log->value,
        ];
    }
}
