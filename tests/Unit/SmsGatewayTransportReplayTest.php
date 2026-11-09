<?php

namespace Tests\Unit;

use CurlHandle;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SmsGatewayTransportReplayTest extends TestCase
{
    #[DataProvider('replayableCurlErrors')]
    public function test_sms_retry_guard_prevents_guzzle_from_replaying_an_ambiguous_post(int $errno): void
    {
        $transfer = $this->failedTransfer($errno, ['_curl_retries' => 2]);
        $handler = function (): PromiseInterface {
            $this->fail('An SMS POST with an uncertain outcome must not be replayed by Guzzle.');
        };

        $this->expectException(RequestException::class);

        CurlFactory::finish($handler, $transfer, $this->releaseFactory())->wait();
    }

    #[DataProvider('replayableCurlErrors')]
    public function test_the_same_transfer_would_be_replayed_without_the_sms_retry_guard(int $errno): void
    {
        $transfer = $this->failedTransfer($errno);
        $replays = 0;
        $response = new Response(200);
        $handler = static function () use (&$replays, $response): PromiseInterface {
            $replays++;

            return new FulfilledPromise($response);
        };

        $result = CurlFactory::finish($handler, $transfer, $this->releaseFactory())->wait();

        $this->assertSame(1, $replays);
        $this->assertSame($response, $result);
    }

    /** @return array<string, array{int}> */
    public static function replayableCurlErrors(): array
    {
        return [
            'no response without a cURL error' => [0],
            'failed body rewind' => [65],
        ];
    }

    /** @param array<string, mixed> $options */
    private function failedTransfer(int $errno, array $options = []): EasyHandle
    {
        $transfer = new EasyHandle;
        $transfer->handle = curl_init();
        $this->assertInstanceOf(CurlHandle::class, $transfer->handle);
        $transfer->request = new Request('POST', 'https://sms-provider.invalid/sms/send', [], '{"message":"Test"}');
        $transfer->errno = $errno;
        $transfer->options = $options;

        return $transfer;
    }

    private function releaseFactory(): CurlFactoryInterface
    {
        $factory = $this->createMock(CurlFactoryInterface::class);
        $factory->expects($this->once())->method('release')->willReturnCallback(static function (EasyHandle $transfer): void {
            unset($transfer->handle);
        });

        return $factory;
    }
}
