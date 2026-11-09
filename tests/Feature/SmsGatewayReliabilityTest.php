<?php

namespace Tests\Feature;

use App\Support\CustomerAuth\SendPulseSmsGateway;
use App\Support\CustomerAuth\SmsClubGateway;
use App\Support\CustomerAuth\SmsGateway;
use App\Support\CustomerAuth\SmsGatewayAcceptanceStatus;
use App\Support\CustomerAuth\TurboSmsGateway;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsGatewayReliabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    #[DataProvider('connectionFailures')]
    public function test_transport_distinguishes_proven_unsent_failures_from_unknown_outcomes(string $provider, int $errno, bool $retryable): void
    {
        $calls = 0;
        Http::fake(function ($request, array $options) use ($errno, &$calls) {
            $calls++;
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['curl'][CURLOPT_FRESH_CONNECT]);
            $this->assertSame(2, $options['_curl_retries']);

            $context = ['errno' => $errno, 'request_size' => 0, 'size_upload' => 0, 'http_code' => 0, 'redirect_count' => 0];
            $previous = $errno === 5
                ? new RequestException('Connection failed.', new GuzzleRequest('POST', $request->url()), handlerContext: $context)
                : new ConnectException('Connection failed.', new GuzzleRequest('POST', $request->url()), handlerContext: $context);

            throw new ConnectionException('Connection failed.', previous: $previous);
        });

        $result = $this->gateway($provider)->sendSms('+380501112233', 'Message');

        $this->assertSame($retryable ? SmsGatewayAcceptanceStatus::Rejected : SmsGatewayAcceptanceStatus::Unknown, $result->acceptanceStatus);
        $this->assertSame($retryable, $result->retryable);
        $this->assertSame(1, $calls);
    }

    /** @return array<string, array{string, int, bool}> */
    public static function connectionFailures(): array
    {
        $cases = [];
        foreach (['smsclub', 'turbosms', 'sendpulse'] as $provider) {
            foreach ([5, 6, 7, 28, 35, 52, 55, 56] as $errno) {
                $cases[$provider.' errno '.$errno] = [$provider, $errno, in_array($errno, [5, 6, 7], true)];
            }
        }

        return $cases;
    }

    #[DataProvider('unsafeConnectionContexts')]
    public function test_incomplete_or_previously_submitted_transfers_remain_unknown(array $context): void
    {
        Http::fake(fn ($request) => throw new ConnectionException('Could not resolve host: cURL error 6', previous: new ConnectException(
            'Connection failed.', new GuzzleRequest('POST', $request->url()), handlerContext: $context,
        )));

        $result = $this->gateway('smsclub')->sendSms('+380501112233', 'Message');

        $this->assertSame(SmsGatewayAcceptanceStatus::Unknown, $result->acceptanceStatus);
        $this->assertFalse($result->retryable);
    }

    /** @return array<string, array{array<string, int>}> */
    public static function unsafeConnectionContexts(): array
    {
        $safe = ['errno' => 7, 'request_size' => 0, 'size_upload' => 0, 'http_code' => 0, 'redirect_count' => 0];

        return [
            'missing transfer evidence' => [['errno' => 7]],
            'request headers sent' => [[...$safe, 'request_size' => 100]],
            'body uploaded' => [[...$safe, 'size_upload' => 100]],
            'response received' => [[...$safe, 'http_code' => 200]],
            'redirect followed' => [[...$safe, 'redirect_count' => 1]],
        ];
    }

    #[DataProvider('httpFailures')]
    public function test_common_http_failures_are_classified_consistently(string $provider, int $status, SmsGatewayAcceptanceStatus $acceptance, bool $retryable): void
    {
        Http::fake(['*' => Http::response([], $status, ['Location' => 'https://unexpected.example/sms'])]);

        $result = $this->gateway($provider)->sendSms('+380501112233', 'Message');

        $this->assertSame($acceptance, $result->acceptanceStatus);
        $this->assertSame($retryable, $result->retryable);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{string, int, SmsGatewayAcceptanceStatus, bool}> */
    public static function httpFailures(): array
    {
        $cases = [];
        foreach (['smsclub', 'turbosms', 'sendpulse'] as $provider) {
            foreach ([301, 307, 400, 401, 408, 429, 500, 502, 503, 504] as $status) {
                $cases[$provider.' HTTP '.$status] = [$provider, $status,
                    in_array($status, [400, 401, 429], true) ? SmsGatewayAcceptanceStatus::Rejected : SmsGatewayAcceptanceStatus::Unknown,
                    $status === 429,
                ];
            }
        }

        return $cases;
    }

    #[DataProvider('providers')]
    public function test_success_without_an_identifier_is_never_assumed_accepted(string $provider): void
    {
        Http::fake(['*' => Http::response(['unexpected' => 'response'])]);

        $result = $this->gateway($provider)->sendSms('+380501112233', 'Message');

        $this->assertSame(SmsGatewayAcceptanceStatus::Unknown, $result->acceptanceStatus);
        $this->assertFalse($result->retryable);
        Http::assertSentCount(1);
    }

    public function test_provider_duplicate_response_does_not_authorize_another_send(): void
    {
        Http::fake(['*' => Http::response([], 453)]);

        $result = $this->gateway('smsclub')->sendSms('+380501112233', 'Message');

        $this->assertSame(SmsGatewayAcceptanceStatus::Unknown, $result->acceptanceStatus);
        $this->assertFalse($result->retryable);
    }

    /** @return array<string, array{string}> */
    public static function providers(): array
    {
        return ['smsclub' => ['smsclub'], 'turbosms' => ['turbosms'], 'sendpulse' => ['sendpulse']];
    }

    private function gateway(string $provider): SmsGateway
    {
        return match ($provider) {
            'smsclub' => new SmsClubGateway(['bearer_token' => 'test-token', 'src_addr' => 'Ladna']),
            'turbosms' => new TurboSmsGateway(['api_token' => 'test-token', 'sms_sender' => 'Ladna']),
            'sendpulse' => new SendPulseSmsGateway(['api_key' => 'test-token', 'sms_sender' => 'Ladna']),
        };
    }
}
