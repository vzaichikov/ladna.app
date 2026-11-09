<?php

namespace App\Support\Sms;

use App\Support\CustomerAuth\SmsGatewayResult;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

class SmsGatewayTransport
{
    /**
     * Guzzle's _curl_retries guard disables cURL rewind recovery, which can
     * otherwise replay an SMS POST independently of Laravel's retry policy.
     *
     * @param  array<string, mixed>  $payload
     */
    public function post(string $url, #[SensitiveParameter] string $token, array $payload): Response|SmsGatewayResult
    {
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(10)
                ->withOptions([
                    'curl' => [CURLOPT_FRESH_CONNECT => true],
                    '_curl_retries' => 2,
                ])
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            return $this->connectionFailure($exception);
        }

        if ($response->serverError() || $response->redirect() || $response->status() === 408) {
            return SmsGatewayResult::unknown('SMS request outcome is unknown after HTTP '.$response->status().'.');
        }

        if ($response->status() === 429) {
            return SmsGatewayResult::failed('SMS provider rate limit exceeded.', retryable: true);
        }

        return $response;
    }

    private function connectionFailure(ConnectionException $exception): SmsGatewayResult
    {
        $previous = $exception->getPrevious();
        $context = $previous instanceof ConnectException || ($previous instanceof RequestException && ! $previous->hasResponse())
            ? $previous->getHandlerContext()
            : [];

        if (in_array($context['errno'] ?? null, [CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT], true)
            && isset($context['request_size'], $context['size_upload'], $context['http_code'], $context['redirect_count'])
            && (int) $context['request_size'] === 0
            && (float) $context['size_upload'] === 0.0
            && (int) $context['http_code'] === 0
            && (int) $context['redirect_count'] === 0) {
            return SmsGatewayResult::failed('SMS provider could not be reached before submission.', retryable: true);
        }

        return SmsGatewayResult::unknown('SMS request outcome is unknown.');
    }
}
