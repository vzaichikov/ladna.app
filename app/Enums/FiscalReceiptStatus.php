<?php

namespace App\Enums;

enum FiscalReceiptStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Fiscalized = 'fiscalized';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return in_array($this, [self::Fiscalized, self::Failed], true);
    }
}
