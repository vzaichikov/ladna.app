<?php

namespace Tests\Feature;

use App\Enums\AccountApiTokenAbility;
use App\Enums\AccountRole;
use App\Enums\AiConversationMessageRole;
use App\Enums\AiProvider;
use App\Enums\CustomerClassPassStatus;
use App\Enums\McpToolInvocationStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AiConversation;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\McpToolInvocation;
use App\Models\PlatformAiProviderCredential;
use App\Models\PlatformAiSetting;
use App\Models\User;
use App\Support\Ai\StudioAiContextBuilder;
use App\Support\Ai\StudioAiInference;
use App\Support\Ai\StudioAiToolExecutor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudioAiInvestigationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ai_context_counts_outstanding_debt_across_pass_lifecycle_and_keeps_tenant_scope(): void
    {
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $customer = Customer::factory()->for($account)->create();
        $classPassPlan = ClassPassPlan::factory()->for($account)->create([
            'price_cents' => 100000,
        ]);

        foreach ([
            ['ACTIVE-DEBT', CustomerClassPassStatus::Active, true],
            ['FROZEN-DEBT', CustomerClassPassStatus::Freezed, false],
            ['USED-DEBT', CustomerClassPassStatus::UsedUp, false],
        ] as [$code, $status, $isActive]) {
            CustomerClassPass::factory()
                ->for($account)
                ->for($customer, 'customer')
                ->for($classPassPlan)
                ->create([
                    'code' => $code,
                    'price_cents' => 100000,
                    'paid_amount_cents' => 0,
                    'is_paid' => false,
                    'status' => $status->value,
                    'is_active' => $isActive,
                ]);
        }

        CustomerClassPass::factory()
            ->for($account)
            ->for($customer, 'customer')
            ->for($classPassPlan)
            ->create([
                'code' => 'EXPIRED-PARTIAL',
                'price_cents' => 100000,
                'paid_amount_cents' => 40000,
                'is_paid' => false,
                'status' => CustomerClassPassStatus::Expired->value,
                'is_active' => false,
            ]);
        CustomerClassPass::factory()
            ->for($account)
            ->for($customer, 'customer')
            ->for($classPassPlan)
            ->create([
                'code' => 'USED-PAID',
                'price_cents' => 100000,
                'paid_amount_cents' => 100000,
                'is_paid' => true,
                'status' => CustomerClassPassStatus::UsedUp->value,
                'is_active' => false,
            ]);
        CustomerClassPass::factory()
            ->for($account)
            ->for($customer, 'customer')
            ->for($classPassPlan)
            ->create([
                'code' => 'CANCELLED-DEBT',
                'price_cents' => 100000,
                'paid_amount_cents' => 0,
                'is_paid' => false,
                'status' => CustomerClassPassStatus::Cancelled->value,
                'is_active' => false,
            ]);
        $otherAccount = Account::factory()->create();
        CustomerClassPass::factory()
            ->for($otherAccount)
            ->create([
                'price_cents' => 100000,
                'paid_amount_cents' => 0,
                'is_paid' => false,
                'status' => CustomerClassPassStatus::UsedUp->value,
                'is_active' => false,
            ]);

        $context = app(StudioAiContextBuilder::class)->studioContext(
            $account,
            includeClassBookingDetails: false,
            actorUser: $owner,
        );

        $this->assertSame(1, $context['metrics']['active_class_passes']);
        $this->assertSame(3, $context['metrics']['unpaid_class_passes']);
        $this->assertSame(1, $context['metrics']['partial_class_passes']);
        $this->assertArrayNotHasKey('unpaid_active_class_passes', $context['metrics']);
        $this->assertArrayNotHasKey('partial_active_class_passes', $context['metrics']);
    }

    public function test_authorized_owner_can_run_an_ephemeral_audited_customer_investigation_tool_loop(): void
    {
        Http::preventStrayRequests();
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $customer = Customer::factory()->for($account)->create(['name' => 'Test Customer']);
        $classPassPlan = ClassPassPlan::factory()->for($account)->create(['price_cents' => 110000]);
        CustomerClassPass::factory()
            ->count(2)
            ->for($account)
            ->for($customer, 'customer')
            ->for($classPassPlan)
            ->create([
                'price_cents' => 110000,
                'paid_amount_cents' => 0,
                'is_paid' => false,
            ]);
        $this->configureOllama();
        $conversation = AiConversation::factory()->for($account)->for($owner, 'user')->create([
            'channel' => 'dashboard_chat',
        ]);
        $currentMessage = $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::User->value,
            'content' => 'Перевір, чому записи Test Customer розподілились між старим і новим абонементом.',
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
                            'calendar_reference' => null,
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
        $this->assertArrayNotHasKey('format', $requests[0]->data());
        $this->assertArrayNotHasKey('format', $requests[1]->data());
        $this->assertSame('json', $requests[2]->data()['format']);
        $this->assertSame('json', $requests[3]->data()['format']);
        $this->assertSame(
            [
                'search_owner_help',
                'get_owner_help_page',
                'get_payment_overview',
                'search_payments',
                'get_events_overview',
                'get_event_summary',
                'search_customers',
                'investigate_customer_booking_ledger',
                'get_business_logic_reference',
            ],
            collect($requests[0]->data()['tools'])->pluck('function.name')->all(),
        );
        $this->assertTrue(collect($requests[0]->data()['messages'])->contains(
            fn (array $message): bool => ($message['role'] ?? null) === 'system'
                && str_contains(
                    $message['content'] ?? '',
                    'Class-pass lifecycle and payment state are independent.',
                )
                && str_contains(
                    $message['content'] ?? '',
                    'Copy monetary_summary totals exactly as calculated by Ladna',
                )
                && str_contains(
                    $message['content'] ?? '',
                    'Do not use Markdown headings, tables, LaTeX',
                ),
        ));
        $this->assertTrue(collect($requests[1]->data()['messages'])->contains(
            fn (array $message): bool => ($message['role'] ?? null) === 'tool'
                && ($message['tool_name'] ?? null) === 'search_customers',
        ));
        $this->assertTrue(collect($requests[2]->data()['messages'])->contains(
            fn (array $message): bool => ($message['role'] ?? null) === 'tool'
                && ($message['tool_name'] ?? null) === 'investigate_customer_booking_ledger',
        ));
        $ledgerToolMessage = collect($requests[2]->data()['messages'])->first(
            fn (array $message): bool => ($message['role'] ?? null) === 'tool'
                && ($message['tool_name'] ?? null) === 'investigate_customer_booking_ledger',
        );
        $ledgerToolPayload = json_decode((string) ($ledgerToolMessage['content'] ?? ''), true);

        $this->assertIsArray($ledgerToolPayload);
        $this->assertSame('2200.00', data_get($ledgerToolPayload, 'monetary_summary.outstanding_by_currency.0.amount'));
        $this->assertSame('UAH', data_get($ledgerToolPayload, 'monetary_summary.outstanding_by_currency.0.currency'));
        $this->assertStringNotContainsString('_cents', (string) ($ledgerToolMessage['content'] ?? ''));
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
                    'content' => '{"disposition":"answer","answer":"Для детальної перевірки потрібен доступ до керування абонементами.","follow_up_actions":[],"action":null,"calendar_reference":null,"reason":"Permission is required."}',
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
            $toolNames = collect($request->data()['tools'] ?? [])->pluck('function.name')->all();

            return $request->url() === 'https://ollama.com/api/chat'
                && $toolNames === ['search_owner_help', 'get_owner_help_page']
                && str_contains(
                    $request->data()['messages'][0]['content'],
                    'class-pass investigation tools are unavailable',
                );
        });
        $this->assertSame(0, McpToolInvocation::query()->whereBelongsTo($account)->count());
    }

    public function test_help_only_staff_can_run_an_audited_model_selected_help_tool_loop(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'ollama.com/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'search_owner_help',
                                'arguments' => [
                                    'query' => 'додати тренера доступ до системи',
                                    'limit' => 5,
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
                                'name' => 'get_owner_help_page',
                                'arguments' => ['slug' => 'trainers'],
                            ],
                        ]],
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => $this->answerEnvelope('Відкрийте Тренери, додайте тренера й увімкніть вхід у систему.'),
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
        $statuses = [];

        $result = app(StudioAiInference::class)->respond(
            $account,
            'прівєт 😅 де додати нову тринершу і дати їй вхід?',
            actorUser: $staff,
            beforeProviderRequest: function (string $statusKey) use (&$statuses): void {
                $statuses[] = $statusKey;
            },
        );

        $this->assertTrue($result->usedAi);
        $this->assertSame('trainers', $result->helpSources[0]['slug']);
        $this->assertContains('assistant_status_searching_help', $statuses);
        $this->assertContains('assistant_status_reading_help', $statuses);
        $this->assertSame(2, McpToolInvocation::query()
            ->whereBelongsTo($account)
            ->where('required_ability', AccountApiTokenAbility::McpRead->value)
            ->where('status', McpToolInvocationStatus::Succeeded->value)
            ->count());

        $requests = collect(Http::recorded())
            ->map(fn (array $record): Request => $record[0])
            ->filter(fn (Request $request): bool => $request->url() === 'https://ollama.com/api/chat')
            ->values();
        $this->assertCount(3, $requests);
        $this->assertSame(
            ['search_owner_help', 'get_owner_help_page'],
            collect($requests[0]->data()['tools'])->pluck('function.name')->all(),
        );
        $this->assertTrue(collect($requests[1]->data()['messages'])->contains(
            fn (array $message): bool => ($message['role'] ?? null) === 'tool'
                && ($message['tool_name'] ?? null) === 'search_owner_help'
                && str_contains($message['content'] ?? '', '"slug":"trainers"'),
        ));
        $this->assertTrue(collect($requests[2]->data()['messages'])->contains(
            fn (array $message): bool => ($message['role'] ?? null) === 'tool'
                && ($message['tool_name'] ?? null) === 'get_owner_help_page'
                && str_contains($message['content'] ?? '', 'увімкніть вхід у систему'),
        ));
    }

    public function test_help_tool_loop_can_retry_with_a_better_model_selected_query(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'ollama.com/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'search_owner_help',
                                'arguments' => ['query' => 'невдалий пошуковий запит xyz'],
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
                                'name' => 'search_owner_help',
                                'arguments' => ['query' => 'додати тренера доступ до системи'],
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
                                'name' => 'get_owner_help_page',
                                'arguments' => ['slug' => 'trainers'],
                            ],
                        ]],
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => $this->answerEnvelope('Додайте тренера в розділі Тренери та ввімкніть вхід.'),
                    ],
                ]),
        ]);
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $this->configureOllama();

        $result = app(StudioAiInference::class)->respond(
            $account,
            'де створити нову співробітницю шоб могла зайти?',
            actorUser: $owner,
        );

        $this->assertTrue($result->usedAi);
        $this->assertSame('trainers', $result->helpSources[0]['slug']);
        $this->assertSame(3, McpToolInvocation::query()
            ->whereBelongsTo($account)
            ->where('required_ability', AccountApiTokenAbility::McpRead->value)
            ->where('status', McpToolInvocationStatus::Succeeded->value)
            ->count());

        $requests = collect(Http::recorded())
            ->map(fn (array $record): Request => $record[0])
            ->filter(fn (Request $request): bool => $request->url() === 'https://ollama.com/api/chat')
            ->values();
        $this->assertCount(4, $requests);
        $this->assertArrayNotHasKey('format', $requests[0]->data());
        $this->assertSame('json', $requests[1]->data()['format']);
        $this->assertSame('json', $requests[2]->data()['format']);
        $this->assertSame('json', $requests[3]->data()['format']);
        $firstSearchResult = collect($requests[1]->data()['messages'])->first(
            fn (array $message): bool => ($message['role'] ?? null) === 'tool'
                && ($message['tool_name'] ?? null) === 'search_owner_help',
        );
        $this->assertStringContainsString(
            '"status":"not_found"',
            (string) ($firstSearchResult['content'] ?? ''),
        );
    }

    public function test_cross_account_actor_cannot_execute_or_read_help_tools(): void
    {
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $otherOwner = User::factory()->create();
        $otherAccount->addOwner($otherOwner);

        $result = app(StudioAiToolExecutor::class)->execute(
            $account,
            $otherOwner,
            'search_owner_help',
            ['query' => 'додати тренера'],
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('permission_denied', $result['error_code']);
        $this->assertArrayNotHasKey('results', $result);
        $this->assertDatabaseHas('mcp_tool_invocations', [
            'account_id' => $account->id,
            'account_api_token_id' => null,
            'tool_name' => 'search_owner_help',
            'required_ability' => AccountApiTokenAbility::McpRead->value,
            'status' => McpToolInvocationStatus::Denied->value,
        ]);
    }

    public function test_verified_investigation_retries_one_invalid_final_envelope(): void
    {
        Http::preventStrayRequests();
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $customer = Customer::factory()->for($account)->create(['name' => 'Retry Customer']);
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
                                'arguments' => ['query' => 'Retry Customer'],
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
                                'arguments' => ['customer_id' => $customer->id],
                            ],
                        ]],
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"answer":"No duplicates."}',
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => $this->answerEnvelope('Перевірено: дублювань немає.'),
                    ],
                ]),
        ]);

        $result = app(StudioAiInference::class)->respond(
            $account,
            'Перевір незрозуміле списання абонемента Retry Customer.',
            actorUser: $owner,
        );

        $this->assertTrue($result->usedAi);
        $this->assertSame('Перевірено: дублювань немає.', $result->text);
        $this->assertSame(2, McpToolInvocation::query()->whereBelongsTo($account)->count());

        $requests = collect(Http::recorded())
            ->map(fn (array $record): Request => $record[0])
            ->filter(fn (Request $request): bool => $request->url() === 'https://ollama.com/api/chat')
            ->values();
        $this->assertCount(4, $requests);
        $this->assertTrue(collect($requests[3]->data()['messages'])->contains(
            fn (array $message): bool => ($message['role'] ?? null) === 'user'
                && str_contains($message['content'] ?? '', 'required final JSON envelope'),
        ));
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

    public function test_pass_count_discrepancy_wording_requires_customer_ledger_evidence(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => $this->answerEnvelope('У клієнтки один неоплачений абонемент.'),
                ],
            ]),
        ]);
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $this->configureOllama();

        $result = app(StudioAiInference::class)->respond(
            $account,
            'Слесаренко Анна показує 1 неоплачений абон, а в картці клієнта інша кількість.',
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
            ->where('status', McpToolInvocationStatus::Invalid->value)
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
            'status' => McpToolInvocationStatus::Invalid->value,
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
            'calendar_reference' => null,
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
