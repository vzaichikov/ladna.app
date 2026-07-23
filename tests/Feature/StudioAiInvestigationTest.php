<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\AiConversationMessageRole;
use App\Enums\AiProvider;
use App\Enums\McpToolInvocationStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AiConversation;
use App\Models\Customer;
use App\Models\McpToolInvocation;
use App\Models\PlatformAiProviderCredential;
use App\Models\PlatformAiSetting;
use App\Models\User;
use App\Support\Ai\StudioAiInference;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudioAiInvestigationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_authorized_owner_can_run_an_ephemeral_audited_customer_investigation_tool_loop(): void
    {
        Http::preventStrayRequests();
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $customer = Customer::factory()->for($account)->create(['name' => 'Test Customer']);
        $this->configureOllama();
        $conversation = AiConversation::factory()->for($account)->for($owner, 'user')->create([
            'channel' => 'dashboard_chat',
        ]);
        $currentMessage = $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::User->value,
            'content' => 'Чому записи Test Customer розподілились між старим і новим абонементом?',
            'occurred_at' => now(),
        ]);
        Http::fake([
            'ollama.com/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'search_customers',
                                'arguments' => ['query' => 'Test Customer'],
                            ],
                        ]],
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'investigate_customer_booking_ledger',
                                'arguments' => [
                                    'customer_id' => $customer->id,
                                    'from_date' => now('Europe/Kyiv')->subDays(30)->toDateString(),
                                    'to_date' => now('Europe/Kyiv')->addDays(30)->toDateString(),
                                ],
                            ],
                        ]],
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'get_business_logic_reference',
                                'arguments' => [
                                    'key' => 'class_pass_issuance_backfill',
                                ],
                            ],
                        ]],
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode([
                            'disposition' => 'answer',
                            'answer' => 'Перевірив реєстр: дублювань або невідповідностей не виявлено.',
                            'follow_up_actions' => [],
                            'action' => null,
                            'reason' => 'Evidence-backed booking ledger investigation.',
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]),
        ]);
        $statuses = [];

        $result = app(StudioAiInference::class)->respond(
            $account,
            $currentMessage->content,
            conversation: $conversation,
            currentMessage: $currentMessage,
            actorUser: $owner,
            beforeProviderRequest: function (string $statusKey) use (&$statuses): void {
                $statuses[] = $statusKey;
            },
        );

        $this->assertTrue($result->usedAi);
        $this->assertSame('Перевірив реєстр: дублювань або невідповідностей не виявлено.', $result->text);
        $this->assertContains('assistant_status_searching_customer', $statuses);
        $this->assertContains('assistant_status_checking_bookings', $statuses);
        $this->assertContains('assistant_status_checking_class_passes', $statuses);
        $this->assertContains('assistant_status_checking_business_rules', $statuses);
        $this->assertContains('assistant_status_preparing_answer', $statuses);
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertSame(3, McpToolInvocation::query()
            ->whereBelongsTo($account)
            ->whereNull('account_api_token_id')
            ->where('ai_conversation_id', $conversation->id)
            ->where('ai_conversation_message_id', $currentMessage->id)
            ->where('status', McpToolInvocationStatus::Succeeded->value)
            ->count());

        $requests = collect(Http::recorded())
            ->map(fn (array $record): Request => $record[0])
            ->filter(fn (Request $request): bool => $request->url() === 'https://ollama.com/api/chat')
            ->values();
        $this->assertCount(4, $requests);
        $this->assertSame(
            ['search_customers', 'investigate_customer_booking_ledger', 'get_business_logic_reference'],
            collect($requests[0]->data()['tools'])->pluck('function.name')->all(),
        );
        $this->assertTrue(collect($requests[1]->data()['messages'])->contains(
            fn (array $message): bool => ($message['role'] ?? null) === 'tool'
                && ($message['tool_name'] ?? null) === 'search_customers',
        ));
        $this->assertTrue(collect($requests[2]->data()['messages'])->contains(
            fn (array $message): bool => ($message['role'] ?? null) === 'tool'
                && ($message['tool_name'] ?? null) === 'investigate_customer_booking_ledger',
        ));
        $this->assertTrue(collect($requests[3]->data()['messages'])->contains(
            fn (array $message): bool => ($message['role'] ?? null) === 'tool'
                && ($message['tool_name'] ?? null) === 'get_business_logic_reference',
        ));
    }

    public function test_investigation_tools_are_not_advertised_without_class_pass_management_permission(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"disposition":"answer","answer":"Для детальної перевірки потрібен доступ до керування абонементами.","follow_up_actions":[],"action":null,"reason":"Permission is required."}',
                ],
            ]),
        ]);
        $account = Account::factory()->create();
        $staff = User::factory()->create();
        AccountMembership::factory()->for($account)->for($staff)->create([
            'role' => AccountRole::Manager->value,
            'permissions' => [StudioPermission::InteractWithTelegramBot->value],
        ]);
        $this->configureOllama();

        $result = app(StudioAiInference::class)->respond(
            $account,
            'Перевір записи клієнта',
            actorUser: $staff,
        );

        $this->assertTrue($result->usedAi);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://ollama.com/api/chat'
                && ! array_key_exists('tools', $request->data())
                && str_contains(
                    $request->data()['messages'][0]['content'],
                    'class-pass investigation tools are unavailable',
                );
        });
        $this->assertSame(0, McpToolInvocation::query()->whereBelongsTo($account)->count());
    }

    public function test_account_specific_pass_claims_are_blocked_when_the_model_skips_evidence_tools(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => $this->answerEnvelope('Подвійного списання точно немає.'),
                ],
            ]),
        ]);
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $this->configureOllama();

        $result = app(StudioAiInference::class)->respond(
            $account,
            'Перевір, чому в клієнта абонемент списався двічі.',
            actorUser: $owner,
        );

        $this->assertTrue($result->usedAi);
        $this->assertSame(__('app.assistant_investigation_unable_to_verify'), $result->text);
        $this->assertSame(0, McpToolInvocation::query()->whereBelongsTo($account)->count());
    }

    public function test_ambiguous_customer_evidence_forces_a_masked_clarification(): void
    {
        Http::preventStrayRequests();
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        Customer::factory()->for($account)->create([
            'name' => 'Anna Test',
            'phone' => '+380671112233',
        ]);
        Customer::factory()->for($account)->create([
            'name' => 'Anna Other',
            'phone' => '+380679998877',
        ]);
        $this->configureOllama();
        Http::fake([
            'ollama.com/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'search_customers',
                                'arguments' => ['query' => 'Anna'],
                            ],
                        ]],
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => $this->answerEnvelope('Я вибрала першу клієнтку.'),
                    ],
                ]),
        ]);

        $result = app(StudioAiInference::class)->respond(
            $account,
            'Перевір незрозуміле списання абонемента Anna.',
            actorUser: $owner,
        );

        $this->assertTrue($result->usedAi);
        $this->assertStringContainsString(__('app.assistant_investigation_customer_ambiguous'), $result->text);
        $this->assertStringContainsString('Anna Test', $result->text);
        $this->assertStringContainsString('2233', $result->text);
        $this->assertStringNotContainsString('+380671112233', $result->text);
    }

    public function test_unknown_tool_calls_are_audited_and_cannot_become_claims(): void
    {
        Http::preventStrayRequests();
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $this->configureOllama();
        Http::fake([
            'ollama.com/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'invented_customer_tool',
                                'arguments' => [],
                            ],
                        ]],
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => $this->answerEnvelope('Дані перевірені.'),
                    ],
                ]),
        ]);

        $result = app(StudioAiInference::class)->respond(
            $account,
            'Перевір помилкове списання абонемента.',
            actorUser: $owner,
        );

        $this->assertSame(__('app.assistant_investigation_unable_to_verify'), $result->text);
        $this->assertSame(1, McpToolInvocation::query()
            ->whereBelongsTo($account)
            ->where('status', McpToolInvocationStatus::Failed->value)
            ->count());
    }

    public function test_invalid_tool_arguments_are_audited_and_cannot_become_claims(): void
    {
        Http::preventStrayRequests();
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $this->configureOllama();
        Http::fake([
            'ollama.com/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'search_customers',
                                'arguments' => ['query' => 'x'],
                            ],
                        ]],
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => $this->answerEnvelope('Дані перевірені.'),
                    ],
                ]),
        ]);

        $result = app(StudioAiInference::class)->respond(
            $account,
            'Перевір помилкове списання абонемента.',
            actorUser: $owner,
        );

        $this->assertSame(__('app.assistant_investigation_unable_to_verify'), $result->text);
        $this->assertDatabaseHas('mcp_tool_invocations', [
            'account_id' => $account->id,
            'tool_name' => 'search_customers',
            'status' => McpToolInvocationStatus::Failed->value,
        ]);
    }

    public function test_the_investigation_agent_executes_at_most_six_tool_calls(): void
    {
        Http::preventStrayRequests();
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        Customer::factory()->for($account)->create(['name' => 'Limit Customer']);
        $this->configureOllama();
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => array_fill(0, 7, [
                        'function' => [
                            'name' => 'search_customers',
                            'arguments' => ['query' => 'Limit Customer'],
                        ],
                    ]),
                ],
            ]),
        ]);

        $result = app(StudioAiInference::class)->respond(
            $account,
            'Перевір списання абонемента Limit Customer.',
            actorUser: $owner,
        );

        $this->assertFalse($result->usedAi);
        $this->assertSame('ai_tool_loop_limit', $result->fallbackReason);
        $this->assertSame(6, McpToolInvocation::query()
            ->whereBelongsTo($account)
            ->where('tool_name', 'search_customers')
            ->count());
    }

    public function test_the_investigation_agent_stops_after_four_provider_rounds(): void
    {
        Http::preventStrayRequests();
        $toolCallResponse = [
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'function' => [
                        'name' => 'search_customers',
                        'arguments' => ['query' => 'Loop Customer'],
                    ],
                ]],
            ],
        ];
        Http::fake([
            'ollama.com/api/chat' => Http::sequence()
                ->push($toolCallResponse)
                ->push($toolCallResponse)
                ->push($toolCallResponse)
                ->push($toolCallResponse),
        ]);
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        Customer::factory()->for($account)->create(['name' => 'Loop Customer']);
        $this->configureOllama();

        $result = app(StudioAiInference::class)->respond(
            $account,
            'Перевір Loop Customer',
            actorUser: $owner,
        );

        $this->assertFalse($result->usedAi);
        $this->assertSame('ai_tool_loop_limit', $result->fallbackReason);
        Http::assertSentCount(4);
        $this->assertSame(3, McpToolInvocation::query()
            ->whereBelongsTo($account)
            ->where('tool_name', 'search_customers')
            ->count());
    }

    private function answerEnvelope(string $answer): string
    {
        return json_encode([
            'disposition' => 'answer',
            'answer' => $answer,
            'follow_up_actions' => [],
            'action' => null,
            'reason' => 'Test response.',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function configureOllama(): void
    {
        PlatformAiSetting::query()->delete();
        PlatformAiProviderCredential::query()->delete();
        PlatformAiSetting::factory()->create([
            'owner_ai_assistant_enabled' => true,
            'active_provider' => AiProvider::OllamaCloud->value,
            'active_model' => 'gemma4:31b',
        ]);
        PlatformAiProviderCredential::factory()->create([
            'provider' => AiProvider::OllamaCloud->value,
            'model' => 'gemma4:31b',
            'credentials' => ['api_key' => 'test-ollama-key'],
            'is_configured' => true,
        ]);
    }
}
