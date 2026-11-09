<?php

namespace App\Support\Payments;

use Illuminate\Support\Str;
use Illuminate\Support\Uri;

class MonopayIframeCompatibility
{
    public function allowsTicketIframe(?string $userAgent): bool
    {
        return ! Str::contains(Str::lower((string) $userAgent), [
            'iphone',
            'ipad',
            'ipod',
            'android',
        ]);
    }

    public function hostedCheckoutUrl(string $pageUrl): string
    {
        $uri = Uri::of($pageUrl);

        return (string) $uri->withPath(Str::replaceStart('frame/', '', $uri->path()));
    }
}
