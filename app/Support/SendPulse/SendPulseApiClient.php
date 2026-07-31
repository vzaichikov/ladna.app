<?php

namespace App\Support\SendPulse;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

class SendPulseApiClient
{
    public function __construct(
        #[SensitiveParameter]
        private readonly string $apiKey,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(string $path, array $payload): Response
    {
        return $this->request()
            ->retry(
                times: 2,
                sleepMilliseconds: 200,
                when: fn (Throwable $exception, PendingRequest $request): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            )
            ->post($path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function postWithoutRetry(string $path, array $payload): Response
    {
        return $this->request()->post($path, $payload);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl('https://api.sendpulse.com')
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout(10);
    }
}
