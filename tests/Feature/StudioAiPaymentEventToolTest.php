<?php

namespace Tests\Feature;

use App\Enums\AccountApiTokenAbility;
use App\Enums\AccountRole;
use App\Enums\AiConversationMessageRole;
use App\Enums\AiProvider;
use App\Enums\McpToolInvocationStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AiConversation;
use App\Models\CustomerClassPass;
use App\Models\McpToolInvocation;
use App\Models\PlatformAiProviderCredential;
use App\Models\PlatformAiSetting;
use App\Models\TelegramChatAuthorization;
use App\Models\User;
use App\Support\Ai\StudioAiContextBuilder;
use App\Support\Ai\StudioAiToolExecutor;
use App\Support\Telegram\TelegramOwnerResponder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudioAiPaymentEventToolTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_delegated_staff_and_platform_admin_receive_only_their_available_tools(): void
    {
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $cashflowStaff = $this->addStaff($account, [
            StudioPermission::InteractWithTelegramBot,
            StudioPermission::ManageStudioCashflow,
        ]);
        $eventStaff = $this->addStaff($account, [
            StudioPermission::InteractWithTelegramBot,
            StudioPermission::ManageEvents,
        ]);
        $basicStaff = $this->addStaff($account, [
            StudioPermission::InteractWithTelegramBot,
        ]);
        $platformAdmin = User::factory()->platformAdmin()->create();
        $executor = app(StudioAiToolExecutor::class);

        $this->assertEqualsCanonicalizing(
            ['get_payment_overview', 'search_payments', 'get_events_overview', 'get_event_summary'],
            $this->sensitiveToolNames($executor->definitions($account, $owner)),
        );
        $this->assertEqualsCanonicalizing(
            ['get_payment_overview', 'search_payments'],
            $this->sensitiveToolNames($executor->definitions($account, $cashflowStaff)),
        );
        $this->assertEqualsCanonicalizing(
            ['get_events_overview', 'get_event_summary'],
            $this->sensitiveToolNames($executor->definitions($account, $eventStaff)),
        );
        $this->assertSame([], $this->sensitiveToolNames($executor->definitions($account, $basicStaff)));
        $this->assertEqualsCanonicalizing(
            ['get_payment_overview', 'search_payments', 'get_events_overview', 'get_event_summary'],
            $this->sensitiveToolNames($executor->definitions($account, $platformAdmin)),
        );
    }

    public function test_payment_permission_is_rechecked_for_every_execution_and_denied_calls_are_audited(): void
    {
        $account = Account::factory()->create();
        $staff = $this->addStaff($account, [
            StudioPermission::InteractWithTelegramBot,
            StudioPermission::ManageStudioCashflow,
        ]);
        $executor = app(StudioAiToolExecutor::class);

        $allowed = $executor->execute($account, $staff, 'get_payment_overview', []);

        $this->assertSame('ok', $allowed['status']);
        $this->assertContains('get_payment_overview', $this->sensitiveToolNames($executor->definitions($account, $staff)));

        AccountMembership::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($staff)
            ->update([
                'permissions' => [StudioPermission::InteractWithTelegramBot->value],
            ]);

        $this->assertNotContains('get_payment_overview', $this->sensitiveToolNames($executor->definitions($account, $staff)));

        $denied = $executor->execute($account, $staff, 'get_payment_overview', []);

        $this->assertSame('error', $denied['status']);
        $this->assertSame('permission_denied', $denied['error_code']);
        $this->assertDatabaseHas('mcp_tool_invocations', [
            'account_id' => $account->id,
            'tool_name' => 'get_payment_overview',
            'required_ability' => AccountApiTokenAbility::McpPaymentsRead->value,
            'status' => McpToolInvocationStatus::Denied->value,
        ]);
    }

    public function test_staff_cannot_forge_sensitive_tool_calls_and_payment_audits_do_not_store_results(): void
    {
        $account = Account::factory()->create();
        $authorizedStaff = $this->addStaff($account, [
            StudioPermission::InteractWithTelegramBot,
            StudioPermission::ManageStudioCashflow,
        ]);
        $unauthorizedStaff = $this->addStaff($account, [
            StudioPermission::InteractWithTelegramBot,
        ]);
        $executor = app(StudioAiToolExecutor::class);
        $conversation = AiConversation::factory()
            ->for($account)
            ->for($authorizedStaff)
            ->create(['channel' => 'dashboard_chat']);
        $currentMessage = $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::User->value,
            'content' => 'Find a payment.',
            'occurred_at' => now(),
        ]);

        $search = $executor->execute($account, $authorizedStaff, 'search_payments', [
            'query' => 'private customer search',
        ], $conversation, $currentMessage);
        $deniedPayment = $executor->execute($account, $unauthorizedStaff, 'search_payments', [
            'query' => 'forged payment lookup',
        ]);
        $deniedEvent = $executor->execute($account, $unauthorizedStaff, 'get_events_overview', []);

        $this->assertContains($search['status'], ['found', 'not_found']);
        $this->assertSame('permission_denied', $deniedPayment['error_code']);
        $this->assertSame('permission_denied', $deniedEvent['error_code']);

        $invocation = McpToolInvocation::query()
            ->whereBelongsTo($account)
            ->where('tool_name', 'search_payments')
            ->where('status', McpToolInvocationStatus::Succeeded->value)
            ->firstOrFail();

        $this->assertSame(true, $invocation->input['query_applied']);
        $this->assertSame($conversation->id, $invocation->ai_conversation_id);
        $this->assertSame($currentMessage->id, $invocation->ai_conversation_message_id);
        $this->assertSame($authorizedStaff->id, $conversation->user_id);
        $this->assertArrayNotHasKey('query', $invocation->input);
        $this->assertSame(
            [
                'status' => $search['status'],
                'returned' => $search['returned'],
                'truncated' => $search['truncated'],
            ],
            $invocation->output,
        );
        $this->assertArrayNotHasKey('items', $invocation->output);
    }

    public function test_payment_context_is_only_present_for_cashflow_authorized_users(): void
    {
        $account = Account::factory()->create();
        $authorizedStaff = $this->addStaff($account, [
            StudioPermission::InteractWithTelegramBot,
            StudioPermission::ManageStudioCashflow,
        ]);
        $unauthorizedStaff = $this->addStaff($account, [
            StudioPermission::InteractWithTelegramBot,
        ]);
        CustomerClassPass::factory()->for($account)->create([
            'price_cents' => 10000,
            'paid_amount_cents' => 0,
            'is_paid' => false,
        ]);
        $contextBuilder = app(StudioAiContextBuilder::class);

        $authorizedContext = $contextBuilder->studioContext(
            $account,
            includeClassBookingDetails: false,
            actorUser: $authorizedStaff,
        );
        $unauthorizedContext = $contextBuilder->studioContext(
            $account,
            includeClassBookingDetails: false,
            actorUser: $unauthorizedStaff,
        );

        $this->assertSame(1, $authorizedContext['metrics']['unpaid_class_passes']);
        $this->assertArrayNotHasKey('unpaid_class_passes', $unauthorizedContext['metrics']);
        $this->assertArrayNotHasKey('partial_class_passes', $unauthorizedContext['metrics']);
    }

    public function test_telegram_forwards_the_current_user_and_reflects_revoked_cashflow_permission_on_the_next_message(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'disposition' => 'answer',
                        'answer' => 'Перевірено.',
                        'follow_up_actions' => [],
                        'action' => null,
                        'calendar_reference' => null,
                        'reason' => 'Test response.',
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ],
            ]),
        ]);
        $this->configureOllama();
        $account = Account::factory()->create();
        $staff = $this->addStaff($account, [
            StudioPermission::InteractWithTelegramBot,
            StudioPermission::ManageStudioCashflow,
        ]);
        $authorization = TelegramChatAuthorization::factory()
            ->for($account)
            ->for($staff)
            ->create();
        $conversation = AiConversation::factory()
            ->for($account)
            ->for($authorization, 'telegramChatAuthorization')
            ->for($staff)
            ->create(['channel' => 'telegram_owner']);
        $firstMessage = $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::User->value,
            'content' => 'Покажи оплати.',
            'occurred_at' => now(),
        ]);
        $responder = app(TelegramOwnerResponder::class);

        $responder->respond($account, $firstMessage->content, $authorization, $conversation, $firstMessage);

        AccountMembership::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($staff)
            ->update([
                'permissions' => [StudioPermission::InteractWithTelegramBot->value],
            ]);
        $secondMessage = $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::User->value,
            'content' => 'Покажи оплати ще раз.',
            'occurred_at' => now(),
        ]);

        $responder->respond($account, $secondMessage->content, $authorization, $conversation, $secondMessage);

        $platformAdmin = User::factory()->platformAdmin()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($platformAdmin)
            ->create([
                'role' => AccountRole::Manager->value,
                'permissions' => [StudioPermission::InteractWithTelegramBot->value],
            ]);
        $platformAuthorization = TelegramChatAuthorization::factory()
            ->for($account)
            ->for($platformAdmin)
            ->create();
        $platformConversation = AiConversation::factory()
            ->for($account)
            ->for($platformAuthorization, 'telegramChatAuthorization')
            ->for($platformAdmin)
            ->create(['channel' => 'telegram_owner']);
        $platformMessage = $platformConversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::User->value,
            'content' => 'Покажи оплати як адміністратору.',
            'occurred_at' => now(),
        ]);

        $responder->respond(
            $account,
            $platformMessage->content,
            $platformAuthorization,
            $platformConversation,
            $platformMessage,
        );

        $requests = collect(Http::recorded())
            ->map(fn (array $record): Request => $record[0])
            ->filter(fn (Request $request): bool => $request->url() === 'https://ollama.com/api/chat')
            ->values();
        $firstToolNames = collect($requests[0]->data()['tools'])->pluck('function.name');
        $secondToolNames = collect($requests[1]->data()['tools'])->pluck('function.name');
        $platformToolNames = collect($requests[2]->data()['tools'])->pluck('function.name');

        $this->assertTrue($firstToolNames->contains('get_payment_overview'));
        $this->assertTrue($firstToolNames->contains('search_payments'));
        $this->assertFalse($secondToolNames->contains('get_payment_overview'));
        $this->assertFalse($secondToolNames->contains('search_payments'));
        $this->assertTrue($platformToolNames->contains('get_payment_overview'));
        $this->assertTrue($platformToolNames->contains('search_payments'));
    }

    /**
     * @param  array<int, StudioPermission>  $permissions
     */
    private function addStaff(Account $account, array $permissions): User
    {
        $user = User::factory()->create();

        AccountMembership::factory()
            ->for($account)
            ->for($user)
            ->create([
                'role' => AccountRole::Manager->value,
                'permissions' => array_map(
                    fn (StudioPermission $permission): string => $permission->value,
                    $permissions,
                ),
            ]);

        return $user;
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @return array<int, string>
     */
    private function sensitiveToolNames(array $definitions): array
    {
        return collect($definitions)
            ->pluck('function.name')
            ->filter(fn (string $name): bool => in_array($name, [
                'get_payment_overview',
                'search_payments',
                'get_events_overview',
                'get_event_summary',
            ], true))
            ->values()
            ->all();
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
