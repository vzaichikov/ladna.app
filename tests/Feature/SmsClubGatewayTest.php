<?php

namespace Tests\Feature;

use App\Support\CustomerAuth\SmsClubGateway;
use App\Support\CustomerAuth\SmsGatewayAcceptanceStatus;
use App\Support\CustomerAuth\SmsGatewayDeliveryStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsClubGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_send_parses_documented_id_to_phone_response(): void
    {
        Http::fake([
            'https://im.smsclub.mobi/sms/send' => Http::response([
                'success_request' => [
                    'info' => [
                        '107' => '380501112233',
                    ],
                ],
            ]),
        ]);

        $result = $this->gateway()->sendSms('+380501112233', 'Your code is 123456');

        $this->assertTrue($result->sent);
        $this->assertSame(SmsGatewayAcceptanceStatus::Accepted, $result->acceptanceStatus);
        $this->assertSame('107', $result->providerMessageId);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://im.smsclub.mobi/sms/send'
                && $request->hasHeader('Authorization', 'Bearer smsclub-token')
                && $request->data() === [
                    'phone' => ['+380501112233'],
                    'message' => 'Your code is 123456',
                    'src_addr' => 'Ladna',
                    'integration_id' => 'integration-1',
                ];
        });
        Http::assertSentCount(1);
    }

    public function test_send_keeps_legacy_response_compatibility(): void
    {
        Http::fake([
            'https://im.smsclub.mobi/sms/send' => Http::response([
                'info' => [
                    ['id' => 'legacy-id'],
                ],
            ]),
        ]);

        $result = $this->gateway()->sendSms('+380501112233', 'Message');

        $this->assertTrue($result->sent);
        $this->assertSame('legacy-id', $result->providerMessageId);
    }

    public function test_success_without_message_id_has_unknown_outcome(): void
    {
        Http::fake([
            'https://im.smsclub.mobi/sms/send' => Http::response([
                'success_request' => ['info' => []],
            ]),
        ]);

        $result = $this->gateway()->sendSms('+380501112233', 'Message');

        $this->assertFalse($result->sent);
        $this->assertSame(SmsGatewayAcceptanceStatus::Unknown, $result->acceptanceStatus);
        Http::assertSentCount(1);
    }

    public function test_connection_and_server_failures_have_unknown_outcomes_without_retries(): void
    {
        Http::fake([
            'https://im.smsclub.mobi/sms/send' => Http::failedConnection(),
        ]);

        $connectionResult = $this->gateway()->sendSms('+380501112233', 'Message');

        $this->assertSame(SmsGatewayAcceptanceStatus::Unknown, $connectionResult->acceptanceStatus);
        Http::assertSentCount(1);

        Http::fake([
            'https://im.smsclub.mobi/sms/send' => Http::response([], 503),
        ]);

        $serverResult = $this->gateway()->sendSms('+380501112233', 'Message');

        $this->assertSame(SmsGatewayAcceptanceStatus::Unknown, $serverResult->acceptanceStatus);
        Http::assertSentCount(1);
    }

    public function test_client_failure_is_an_explicit_rejection(): void
    {
        Http::fake([
            'https://im.smsclub.mobi/sms/send' => Http::response([], 400),
        ]);

        $result = $this->gateway()->sendSms('+380501112233', 'Message');

        $this->assertFalse($result->sent);
        $this->assertSame(SmsGatewayAcceptanceStatus::Rejected, $result->acceptanceStatus);
    }

    public function test_status_capability_maps_documented_smsclub_statuses(): void
    {
        Http::fake([
            'https://im.smsclub.mobi/sms/status' => Http::response([
                'success_request' => [
                    'info' => [
                        '101' => 'ENROUTE',
                        '102' => 'DELIVRD',
                        '103' => 'EXPIRED',
                        '104' => 'UNDELIV',
                        '105' => 'REJECTD',
                        '106' => 'FUTURE_STATUS',
                    ],
                ],
            ]),
        ]);

        $result = $this->gateway()->fetchDeliveryStatuses(['101', '102', '103', '104', '105', '106']);

        $this->assertTrue($result->successful);
        $this->assertSame(SmsGatewayDeliveryStatus::Accepted, $result->statuses['101']);
        $this->assertSame(SmsGatewayDeliveryStatus::Delivered, $result->statuses['102']);
        $this->assertSame(SmsGatewayDeliveryStatus::Undelivered, $result->statuses['103']);
        $this->assertSame(SmsGatewayDeliveryStatus::Undelivered, $result->statuses['104']);
        $this->assertSame(SmsGatewayDeliveryStatus::Undelivered, $result->statuses['105']);
        $this->assertSame(SmsGatewayDeliveryStatus::Unknown, $result->statuses['106']);
        $this->assertSame('FUTURE_STATUS', $result->providerStatuses['106']);
        $this->assertSame(100, $this->gateway()->maxStatusBatchSize());
        $this->assertSame(9, $this->gateway()->maxStatusRequestsPerSecond());

        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'id_sms' => ['101', '102', '103', '104', '105', '106'],
        ]);
    }

    public function test_status_capability_rejects_batches_over_provider_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->gateway()->fetchDeliveryStatuses(
            array_map(static fn (int $id): string => (string) $id, range(1, 101)),
        );

        Http::assertNothingSent();
    }

    public function test_balance_capability_preserves_exact_decimal_amount(): void
    {
        Http::fake([
            'https://im.smsclub.mobi/sms/balance' => Http::response([
                'success_request' => [
                    'info' => [
                        'money' => '8121.1800',
                        'currency' => 'uah',
                    ],
                ],
            ]),
        ]);

        $result = $this->gateway()->fetchBalance();

        $this->assertTrue($result->successful);
        $this->assertSame('8121.1800', $result->amount);
        $this->assertSame('UAH', $result->currency);
    }

    private function gateway(): SmsClubGateway
    {
        return new SmsClubGateway([
            'bearer_token' => 'smsclub-token',
            'src_addr' => 'Ladna',
            'integration_id' => 'integration-1',
        ]);
    }
}
