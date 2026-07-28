<?php

namespace Tests\Unit;

use App\Support\Ai\OpenAiResponsesClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenAiResponsesClientTest extends TestCase
{
    public function test_it_normalizes_function_calls_and_preserves_continuation_items(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'resp_tool',
                'status' => 'completed',
                'output' => [
                    [
                        'id' => 'reasoning_tool',
                        'type' => 'reasoning',
                        'summary' => [],
                    ],
                    [
                        'id' => 'function_tool',
                        'type' => 'function_call',
                        'call_id' => 'call_tool',
                        'name' => 'search_owner_help',
                        'arguments' => '{"query":"customers"}',
                    ],
                ],
            ]),
        ]);

        $result = app(OpenAiResponsesClient::class)->respond(
            'test-api-key',
            'gpt-5.5',
            [['role' => 'user', 'content' => 'How do I add a customer?']],
            [[
                'type' => 'function',
                'function' => [
                    'name' => 'search_owner_help',
                    'description' => 'Search Ladna owner help.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string'],
                        ],
                    ],
                ],
            ]],
        );

        $this->assertSame('', $result['content']);
        $this->assertSame('call_tool', data_get($result, 'tool_calls.0.id'));
        $this->assertSame('search_owner_help', data_get($result, 'tool_calls.0.function.name'));
        $this->assertSame(['query' => 'customers'], data_get($result, 'tool_calls.0.function.arguments'));
        $this->assertCount(2, $result['continuation_items']);

        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/v1/responses')
                && $request['store'] === false
                && $request['model'] === 'gpt-5.5'
                && data_get($request->data(), 'reasoning.effort') === 'low'
                && data_get($request->data(), 'tools.0.name') === 'search_owner_help'
                && data_get($request->data(), 'tools.0.strict') === false
                && ! array_key_exists('function', $request['tools'][0]);
        });
    }

    public function test_it_fails_closed_when_response_is_incomplete(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'resp_incomplete',
                'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
                'output' => [],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI response did not complete.');

        app(OpenAiResponsesClient::class)->respond(
            'test-api-key',
            'gpt-5.5',
            [['role' => 'user', 'content' => 'Hello']],
        );
    }

    public function test_it_does_not_retry_authentication_failures_or_expose_response_body(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'message' => 'Sensitive upstream error body.',
                ],
            ], 401, [
                'x-request-id' => 'req_auth',
            ]),
        ]);

        try {
            app(OpenAiResponsesClient::class)->respond(
                'test-api-key',
                'gpt-5.5',
                [['role' => 'user', 'content' => 'Hello']],
            );
            $this->fail('An authentication failure should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('OpenAI request failed with status 401 (request req_auth)', $exception->getMessage());
            $this->assertStringNotContainsString('Sensitive upstream error body.', $exception->getMessage());
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_it_retries_rate_limits_before_returning_a_completed_response(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'Rate limited']], 429)
            ->push($this->completedTextResponse('Recovered response.'));

        $result = app(OpenAiResponsesClient::class)->respond(
            'test-api-key',
            'gpt-5.5',
            [['role' => 'user', 'content' => 'Hello']],
        );

        $this->assertSame('Recovered response.', $result['content']);
        $this->assertCount(2, Http::recorded());
    }

    /**
     * @return array<string, mixed>
     */
    private function completedTextResponse(string $text): array
    {
        return [
            'id' => 'resp_text',
            'status' => 'completed',
            'output' => [[
                'id' => 'message_text',
                'type' => 'message',
                'role' => 'assistant',
                'status' => 'completed',
                'content' => [[
                    'type' => 'output_text',
                    'text' => $text,
                    'annotations' => [],
                ]],
            ]],
        ];
    }
}
