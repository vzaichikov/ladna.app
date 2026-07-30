<?php

namespace App\Support\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class OpenAiResponsesClient
{
    private const MaxOutputTokens = 4096;

    /**
     * @param  array<int, array<string, mixed>>  $input
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<string, mixed>|null  $textFormat
     * @return array{
     *     content: string,
     *     tool_calls: array<int, array<string, mixed>>,
     *     message: array{role: string, content: string, tool_calls: array<int, array<string, mixed>>},
     *     continuation_items: array<int, array<string, mixed>>,
     *     raw: array<string, mixed>
     * }
     */
    public function respond(
        string $apiKey,
        string $model,
        array $input,
        array $tools = [],
        ?array $textFormat = null,
        ?string $safetyIdentifier = null,
    ): array {
        $payload = [
            'model' => $model,
            'input' => $input,
            'store' => false,
            'max_output_tokens' => self::MaxOutputTokens,
            'reasoning' => ['effort' => 'low'],
            'text' => ['verbosity' => 'low'],
        ];

        if ($tools !== []) {
            $payload['tools'] = $this->tools($tools);
            $payload['parallel_tool_calls'] = false;
        }

        if ($textFormat !== null) {
            $payload['text']['format'] = $textFormat;
        }

        if ($safetyIdentifier !== null) {
            $payload['safety_identifier'] = $safetyIdentifier;
        }

        return $this->normalize($this->request($apiKey, $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $apiKey, array $payload): array
    {
        $response = Http::baseUrl((string) config('services.openai.base_url', 'https://api.openai.com'))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(90)
            ->connectTimeout(10)
            ->retry(
                [500, 1000],
                when: fn (Throwable $throwable): bool => $this->shouldRetry($throwable),
                throw: false,
            )
            ->post('/v1/responses', $payload);

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI response is not valid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @return array<int, array<string, mixed>>
     */
    private function tools(array $definitions): array
    {
        return collect($definitions)
            ->map(function (array $definition): ?array {
                $function = $definition['function'] ?? null;

                if (! is_array($function)
                    || ! is_string($function['name'] ?? null)
                    || ! is_array($function['parameters'] ?? null)) {
                    return null;
                }

                return [
                    'type' => 'function',
                    'name' => $function['name'],
                    'description' => (string) ($function['description'] ?? ''),
                    'parameters' => $function['parameters'],
                    'strict' => false,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     content: string,
     *     tool_calls: array<int, array<string, mixed>>,
     *     message: array{role: string, content: string, tool_calls: array<int, array<string, mixed>>},
     *     continuation_items: array<int, array<string, mixed>>,
     *     raw: array<string, mixed>
     * }
     */
    private function normalize(array $payload): array
    {
        if (($payload['status'] ?? null) !== 'completed') {
            throw new RuntimeException('OpenAI response did not complete.');
        }

        $output = collect($payload['output'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();
        $toolCalls = $output
            ->where('type', 'function_call')
            ->map(function (array $toolCall): ?array {
                $name = $toolCall['name'] ?? null;
                $callId = $toolCall['call_id'] ?? null;
                $arguments = $toolCall['arguments'] ?? null;

                if (! is_string($name)
                    || trim($name) === ''
                    || ! is_string($callId)
                    || $callId === ''
                    || ! is_string($arguments)) {
                    return null;
                }

                $decodedArguments = json_decode($arguments, true);

                return [
                    'id' => $callId,
                    'function' => [
                        'name' => trim($name),
                        'arguments' => is_array($decodedArguments) ? $decodedArguments : [],
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
        $content = $output
            ->where('type', 'message')
            ->flatMap(fn (array $message): array => is_array($message['content'] ?? null)
                ? $message['content']
                : [])
            ->filter(fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'output_text')
            ->pluck('text')
            ->filter(fn (mixed $text): bool => is_string($text))
            ->implode("\n");
        $content = trim($content);

        if ($content === '' && $toolCalls === []) {
            throw new RuntimeException('OpenAI response did not include assistant content or tool calls.');
        }

        return [
            'content' => $content,
            'tool_calls' => $toolCalls,
            'message' => [
                'role' => 'assistant',
                'content' => $content,
                'tool_calls' => $toolCalls,
            ],
            'continuation_items' => $output->all(),
            'raw' => $payload,
        ];
    }

    private function errorMessage(Response $response): string
    {
        $requestId = $response->header('x-request-id');
        $requestDetail = is_string($requestId) && $requestId !== ''
            ? ' (request '.$requestId.')'
            : '';

        return 'OpenAI request failed with status '.$response->status().$requestDetail;
    }

    private function shouldRetry(Throwable $throwable): bool
    {
        if ($throwable instanceof ConnectionException) {
            return true;
        }

        return $throwable instanceof RequestException
            && ($throwable->response->serverError() || $throwable->response->status() === 429);
    }
}
