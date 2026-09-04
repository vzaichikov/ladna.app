<?php

namespace App\Support\Payments;

use Illuminate\Support\Str;

class MonopayIframeCompatibility
{
    public function allowsTicketIframe(?string $userAgent): bool
    {
        return ! Str::contains(Str::lower((string) $userAgent), [
            'iphone',
            'ipad',
            'ipod',
        ]);
    }
}
