<?php

namespace Tests\Unit;

use App\Support\Payments\MonopayIframeCompatibility;
use PHPUnit\Framework\TestCase;

class MonopayIframeCompatibilityTest extends TestCase
{
    public function test_apple_mobile_devices_do_not_use_ticket_iframes(): void
    {
        $compatibility = new MonopayIframeCompatibility;

        $this->assertFalse($compatibility->allowsTicketIframe('Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 Version/18.6 Mobile/15E148 Safari/604.1'));
        $this->assertFalse($compatibility->allowsTicketIframe('Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 CriOS/138.0.7204.119 Mobile/15E148 Safari/604.1'));
        $this->assertFalse($compatibility->allowsTicketIframe('Mozilla/5.0 (iPad; CPU OS 18_6 like Mac OS X) AppleWebKit/605.1.15 Version/18.6 Mobile/15E148 Safari/604.1'));
        $this->assertFalse($compatibility->allowsTicketIframe('Mozilla/5.0 (iPod touch; CPU iPhone OS 15_7 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148'));
    }

    public function test_other_clients_keep_ticket_iframes(): void
    {
        $compatibility = new MonopayIframeCompatibility;

        $this->assertTrue($compatibility->allowsTicketIframe('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/18.6 Safari/605.1.15'));
        $this->assertTrue($compatibility->allowsTicketIframe('Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 Chrome/138.0.0.0 Mobile Safari/537.36'));
        $this->assertTrue($compatibility->allowsTicketIframe(null));
    }
}
