<?php

namespace Tests\Feature;

use App\Support\CustomerAuth\SendPulseSmsGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendPulseSmsGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_gateway_uses_only_the_explicit_integration_api_key_and_sender(): void
    {
        Http::fake([
            'https://api.sendpulse.com/sms/send' => Http::response([
                'data' => ['id' => 'sms-message-id'],
            ]),
        ]);

        $result = (new SendPulseSmsGateway([
            'api_key' => 'studio-sendpulse-key',
            'sms_sender' => 'Studio',
            'sms_route' => 'legacy-route-must-not-be-used',
        ]))->sendSms('+380501112233', 'Your code is 123456');

        $this->assertTrue($result->sent);
        $this->assertSame('sms-message-id', $result->providerMessageId);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.sendpulse.com/sms/send'
                && $request->hasHeader('Authorization', 'Bearer studio-sendpulse-key')
                && $request->data() === [
                    'sender' => 'Studio',
                    'phones' => ['+380501112233'],
                    'body' => 'Your code is 123456',
                ];
        });
        Http::assertSentCount(1);
    }

    public function test_gateway_does_not_send_without_an_explicit_api_key(): void
    {
        $result = (new SendPulseSmsGateway([
            'sms_sender' => 'Studio',
        ]))->sendSms('+380501112233', 'Your code is 123456');

        $this->assertFalse($result->sent);
        $this->assertSame('SendPulse token is not configured.', $result->message);
        Http::assertNothingSent();
    }
}
