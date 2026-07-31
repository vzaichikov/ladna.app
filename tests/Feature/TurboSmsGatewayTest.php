<?php

namespace Tests\Feature;

use App\Support\CustomerAuth\SmsGatewayAcceptanceStatus;
use App\Support\CustomerAuth\TurboSmsGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurboSmsGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_ambiguous_failures_are_unknown_and_are_never_retried(): void
    {
        Http::fake([
            'https://api.turbosms.ua/message/send.json' => Http::failedConnection(),
        ]);

        $connectionResult = $this->gateway()->sendSms('+380501112233', 'Message');

        $this->assertSame(SmsGatewayAcceptanceStatus::Unknown, $connectionResult->acceptanceStatus);
        Http::assertSentCount(1);

        Http::fake([
            'https://api.turbosms.ua/message/send.json' => Http::response([], 503),
        ]);

        $serverResult = $this->gateway()->sendSms('+380501112233', 'Message');

        $this->assertSame(SmsGatewayAcceptanceStatus::Unknown, $serverResult->acceptanceStatus);
        Http::assertSentCount(1);
    }

    private function gateway(): TurboSmsGateway
    {
        return new TurboSmsGateway([
            'api_token' => 'studio-turbosms-token',
            'sms_sender' => 'Studio',
        ]);
    }
}
