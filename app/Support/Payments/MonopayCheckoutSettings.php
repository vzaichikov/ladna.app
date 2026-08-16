<?php

namespace App\Support\Payments;

use App\Models\SystemSetting;

class MonopayCheckoutSettings
{
    public const EventIframeV2EnabledKey = 'payments.monopay.event_iframe_v2_enabled';

    public function ticketIframeV2Enabled(): bool
    {
        return filter_var(
            SystemSetting::stringValue(self::EventIframeV2EnabledKey, '0'),
            FILTER_VALIDATE_BOOL,
        );
    }

    public function eventIframeV2Enabled(): bool
    {
        return $this->ticketIframeV2Enabled();
    }

    public function saveEventIframeV2Enabled(bool $enabled): void
    {
        SystemSetting::setValue(self::EventIframeV2EnabledKey, $enabled ? '1' : '0');
    }
}
