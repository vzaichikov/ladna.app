<?php

namespace Tests\Feature;

use App\Enums\AiConversationMessageRole;
use App\Enums\AiProvider;
use App\Enums\SystemRole;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\AiProviderRequest;
use App\Models\AiUsageRestriction;
use App\Models\PlatformAiProviderCredential;
use App\Models\PlatformAiSetting;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\User;
use App\Support\Ai\StudioAiResult;
use App\Support\Ai\StudioAiUsageFirewall;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiUsageFirewallTest extends TestCase
{
    use DatabaseTransactions;

    public function test_balanced_defaults_are_database_backed_and_platform_admin_can_update_them(): void
    {
        PlatformAiSetting::query()->delete();
        $setting = PlatformAiSetting::current();

        $this->assertTrue($setting->firewall_enabled);
        $this->assertSame(6, $setting->firewall_user_turns_per_minute);
        $this->assertSame(30, $setting->firewall_user_turns_per_hour);
        $this->assertSame(100, $setting->firewall_user_turns_per_day);
        $this->assertSame(20, $setting->firewall_admin_turns_per_minute);
        $this->assertSame(500, $setting->firewall_account_turns_per_day);
        $this->assertSame(90, $setting->firewall_user_provider_calls_per_hour);
        $this->assertSame(1500, $setting->firewall_account_provider_calls_per_day);
        $this->assertSame(5, $setting->firewall_user_out_of_scope_streak);
        $this->assertSame(10, $setting->firewall_admin_out_of_scope_streak);
        $this->assertSame(60, $setting->firewall_cooldown_first_minutes);
        $this->assertSame(360, $setting->firewall_cooldown_second_minutes);
        $this->assertSame(1440, $setting->firewall_cooldown_third_minutes);
        $this->assertSame(7, $setting->firewall_escalation_reset_days);

        $administrator = User::factory()->create([
            'system_role' => SystemRole::PlatformAdmin->value,
        ]);
        $payload = $this->firewallSettingsPayload([
            'firewall_user_turns_per_minute' => 8,
            'firewall_user_turns_per_hour' => 40,
            'firewall_user_turns_per_day' => 120,
        ]);

        $this->actingAs($administrator)
            ->put(route('platform.ai-usage.update'), $payload)
            ->assertRedirect(route('platform.ai-usage.index'));

        $this->assertSame(8, PlatformAiSetting::current()->refresh()->firewall_user_turns_per_minute);

        $this->actingAs(User::factory()->create())
            ->put(route('platform.ai-usage.update'), $payload)
            ->assertForbidden();
    }

    public function test_user_turn_limit_is_shared_across_dashboard_and_telegram_channels(): void
    {
        $setting = PlatformAiSetting::factory()->create([
            'firewall_user_turns_per_minute' => 1,
            'firewall_user_turns_per_hour' => 10,
            'firewall_user_turns_per_day' => 20,
            'firewall_account_turns_per_day' => 20,
        ]);
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $firewall = app(StudioAiUsageFirewall::class);

        $this->assertTrue($firewall->admitTurn($account, $user, 'dashboard_chat', $setting)->allowed);

        $blocked = $firewall->admitTurn($account, $user, 'telegram_owner', $setting);

        $this->assertFalse($blocked->allowed);
        $this->assertSame('user_minute', $blocked->scope);
        $this->assertSame('turn_limit', $blocked->reason);
    }

    public function test_platform_admin_uses_separate_database_configured_turn_limits(): void
    {
        $setting = PlatformAiSetting::factory()->create([
            'firewall_user_turns_per_minute' => 1,
            'firewall_user_turns_per_hour' => 10,
            'firewall_user_turns_per_day' => 20,
            'firewall_admin_turns_per_minute' => 2,
            'firewall_admin_turns_per_hour' => 20,
            'firewall_admin_turns_per_day' => 40,
            'firewall_account_turns_per_day' => 100,
        ]);
        $account = Account::factory()->create();
        $user = User::factory()->create();
        $administrator = User::factory()->create([
            'system_role' => SystemRole::PlatformAdmin->value,
        ]);
        $firewall = app(StudioAiUsageFirewall::class);

        $this->assertTrue($firewall->admitTurn($account, $user, 'dashboard_chat', $setting)->allowed);
        $this->assertFalse($firewall->admitTurn($account, $user, 'dashboard_chat', $setting)->allowed);
        $this->assertTrue($firewall->admitTurn($account, $administrator, 'dashboard_chat', $setting)->allowed);
        $this->assertTrue($firewall->admitTurn($account, $administrator, 'telegram_owner', $setting)->allowed);
        $this->assertFalse($firewall->admitTurn($account, $administrator, 'dashboard_chat', $setting)->allowed);
    }

    public function test_user_admin_and_studio_provider_limits_are_enforced_independently(): void
    {
        $setting = PlatformAiSetting::factory()->create([
            'firewall_user_provider_calls_per_hour' => 1,
            'firewall_user_provider_calls_per_day' => 10,
            'firewall_admin_provider_calls_per_hour' => 2,
            'firewall_admin_provider_calls_per_day' => 20,
            'firewall_account_provider_calls_per_day' => 100,
        ]);
        $firewall = app(StudioAiUsageFirewall::class);
        $userAccount = Account::factory()->create();
        $administratorAccount = Account::factory()->create();
        $user = User::factory()->create();
        $administrator = User::factory()->create([
            'system_role' => SystemRole::PlatformAdmin->value,
        ]);

        $this->assertTrue($firewall->reserveProviderCall($userAccount, $user, $setting)->allowed);
        $this->assertSame(
            'user_hour',
            $firewall->reserveProviderCall($userAccount, $user, $setting)->scope,
        );
        $this->assertTrue($firewall->reserveProviderCall($administratorAccount, $administrator, $setting)->allowed);
        $this->assertTrue($firewall->reserveProviderCall($administratorAccount, $administrator, $setting)->allowed);
        $this->assertSame(
            'user_hour',
            $firewall->reserveProviderCall($administratorAccount, $administrator, $setting)->scope,
        );

        $setting->forceFill([
            'firewall_user_provider_calls_per_hour' => 10,
            'firewall_account_provider_calls_per_day' => 1,
        ]);
        $cappedAccount = Account::factory()->create();

        $this->assertTrue(
            $firewall->reserveProviderCall($cappedAccount, User::factory()->create(), $setting)->allowed,
        );
        $this->assertSame(
            'account_day',
            $firewall->reserveProviderCall($cappedAccount, User::factory()->create(), $setting)->scope,
        );
    }

    public function test_platform_setting_and_statistics_validation_rejects_unsafe_ranges(): void
    {
        PlatformAiSetting::factory()->create();
        $administrator = User::factory()->create([
            'system_role' => SystemRole::PlatformAdmin->value,
        ]);

        $this->actingAs($administrator)
            ->from(route('platform.ai-usage.index'))
            ->put(route('platform.ai-usage.update'), $this->firewallSettingsPayload([
                'firewall_user_turns_per_minute' => 0,
                'firewall_admin_turns_per_hour' => 5,
                'firewall_cooldown_second_minutes' => 30,
            ]))
            ->assertRedirect(route('platform.ai-usage.index'))
            ->assertSessionHasErrors([
                'firewall_user_turns_per_minute',
                'firewall_admin_turns_per_hour',
                'firewall_cooldown_second_minutes',
            ]);

        $this->actingAs($administrator)
            ->get(route('platform.ai-usage.index', [
                'period' => 'custom',
                'from' => '2025-01-01',
                'to' => '2026-07-30',
            ]))
            ->assertSessionHasErrors('to');
    }

    public function test_studio_daily_cap_is_tenant_scoped(): void
    {
        $setting = PlatformAiSetting::factory()->create([
            'firewall_user_turns_per_minute' => 20,
            'firewall_user_turns_per_hour' => 100,
            'firewall_user_turns_per_day' => 500,
            'firewall_account_turns_per_day' => 1,
        ]);
        $firstAccount = Account::factory()->create();
        $secondAccount = Account::factory()->create();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firewall = app(StudioAiUsageFirewall::class);

        $this->assertTrue($firewall->admitTurn($firstAccount, $firstUser, 'dashboard_chat', $setting)->allowed);

        $accountBlocked = $firewall->admitTurn($firstAccount, $secondUser, 'telegram_owner', $setting);

        $this->assertFalse($accountBlocked->allowed);
        $this->assertSame('account_day', $accountBlocked->scope);
        $this->assertTrue($firewall->admitTurn($secondAccount, $secondUser, 'telegram_owner', $setting)->allowed);
    }

    public function test_out_of_scope_streak_progresses_cooldowns_and_valid_ai_answer_resets_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 12:00:00', 'UTC'));
        $setting = PlatformAiSetting::factory()->create([
            'firewall_user_out_of_scope_streak' => 2,
            'firewall_cooldown_first_minutes' => 60,
            'firewall_cooldown_second_minutes' => 360,
            'firewall_cooldown_third_minutes' => 1440,
            'firewall_escalation_reset_days' => 7,
        ]);
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $user = User::factory()->create();
        $firewall = app(StudioAiUsageFirewall::class);
        $rejected = StudioAiResult::rejected('Out of scope.');

        $this->assertNull($firewall->recordOutcome($account, $user, 'dashboard_chat', $rejected, $setting)->fallbackReason);
        $blocked = $firewall->recordOutcome($account, $user, 'telegram_owner', $rejected, $setting);

        $this->assertSame('ai_cooldown', $blocked->fallbackReason);
        $this->assertSame(3600, $blocked->retryAfterSeconds);
        $this->assertSame(1, AiUsageRestriction::whereBelongsTo($user)->firstOrFail()->cooldown_level);

        Carbon::setTestNow(now()->addMinutes(61));
        $this->assertTrue($firewall->admitTurn($account, $user, 'dashboard_chat', $setting)->allowed);
        $firewall->recordOutcome(
            $account,
            $user,
            'dashboard_chat',
            StudioAiResult::answer('Valid Ladna answer.', 'test', 'test'),
            $setting,
        );
        $this->assertSame(0, AiUsageRestriction::whereBelongsTo($user)->firstOrFail()->consecutive_out_of_scope_count);

        $firewall->recordOutcome($account, $user, 'dashboard_chat', $rejected, $setting);
        $secondBlock = $firewall->recordOutcome($account, $user, 'dashboard_chat', $rejected, $setting);

        $this->assertSame(2, AiUsageRestriction::whereBelongsTo($user)->firstOrFail()->cooldown_level);
        $this->assertSame(21600, $secondBlock->retryAfterSeconds);

        Carbon::setTestNow(now()->addDays(8));
        $this->assertTrue($firewall->admitTurn($account, $user, 'dashboard_chat', $setting)->allowed);
        $this->assertSame(0, AiUsageRestriction::whereBelongsTo($user)->firstOrFail()->cooldown_level);

        Carbon::setTestNow();
    }

    public function test_provider_failure_and_clarification_do_not_change_pending_strike(): void
    {
        $setting = PlatformAiSetting::factory()->create();
        $account = Account::factory()->create();
        $user = User::factory()->create();
        AiUsageRestriction::factory()->for($user)->for($account, 'lastAccount')->create([
            'consecutive_out_of_scope_count' => 2,
        ]);
        $firewall = app(StudioAiUsageFirewall::class);

        $firewall->recordOutcome(
            $account,
            $user,
            'dashboard_chat',
            StudioAiResult::fallback('provider_request_failed'),
            $setting,
        );
        $firewall->recordOutcome(
            $account,
            $user,
            'dashboard_chat',
            StudioAiResult::fallback('invalid_ai_response'),
            $setting,
        );

        $this->assertSame(2, AiUsageRestriction::whereBelongsTo($user)->firstOrFail()->consecutive_out_of_scope_count);
    }

    public function test_dashboard_limit_blocks_provider_and_audits_usage_with_safety_identifier(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/v1/responses' => Http::response($this->openAiResponse(
                answer: 'Safe answer.',
                usage: [
                    'input_tokens' => 120,
                    'input_tokens_details' => ['cached_tokens' => 40],
                    'output_tokens' => 30,
                    'output_tokens_details' => ['reasoning_tokens' => 10],
                    'total_tokens' => 150,
                ],
            )),
        ]);
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $setting = $this->configureOpenAi([
            'firewall_user_turns_per_minute' => 1,
            'firewall_user_turns_per_hour' => 10,
            'firewall_user_turns_per_day' => 20,
        ]);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'Check the studio.',
            ])
            ->assertOk()
            ->assertJsonPath('messages.1.content', 'Safe answer.');

        $setting->update(['firewall_user_turns_per_minute' => 2]);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'Check again.',
            ])
            ->assertOk()
            ->assertJsonPath('messages.3.content', 'Safe answer.');

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'One more check.',
            ])
            ->assertOk()
            ->assertJsonPath('messages.5.metadata.fallback_reason', 'ai_rate_limited')
            ->assertJsonPath('messages.5.metadata.limit_scope', 'user_minute');

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($owner, $setting): bool {
            return $request['safety_identifier'] === app(StudioAiUsageFirewall::class)->safetyIdentifier($owner)
                && strlen($request['safety_identifier']) === 64
                && $request['safety_identifier'] !== (string) $owner->id
                && $setting->active_model === $request['model'];
        });

        $providerRequest = AiProviderRequest::query()->oldest('id')->firstOrFail();
        $this->assertSame(2, AiProviderRequest::query()->count());
        $this->assertSame('dashboard_chat', $providerRequest->channel);
        $this->assertSame(AiProvider::OpenAiApiKey->value, $providerRequest->provider);
        $this->assertSame(120, $providerRequest->input_tokens);
        $this->assertSame(40, $providerRequest->cached_input_tokens);
        $this->assertSame(30, $providerRequest->output_tokens);
        $this->assertSame(10, $providerRequest->reasoning_tokens);
        $this->assertSame(150, $providerRequest->total_tokens);
        $this->assertSame('resp_firewall_test', $providerRequest->provider_request_id);
    }

    public function test_concurrent_inference_is_blocked_without_provider_usage(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/v1/responses' => Http::response($this->openAiResponse('Should not be requested.')),
        ]);
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $this->configureOpenAi();
        $lock = app(StudioAiUsageFirewall::class)->acquireInferenceLock($owner);

        $this->assertNotNull($lock);

        try {
            $this->actingAs($owner)
                ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                    'message' => 'Check while another request is running.',
                ])
                ->assertOk()
                ->assertJsonPath('messages.1.metadata.fallback_reason', 'ai_busy')
                ->assertJsonPath('messages.1.metadata.limit_scope', 'user');
        } finally {
            $lock?->release();
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiProviderRequest::query()->count());
    }

    public function test_failed_paid_request_is_audited_without_raw_content(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'error' => ['message' => 'SECRET_PROVIDER_RESPONSE'],
            ], 401),
        ]);
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $this->configureOpenAi();

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'SECRET_PROVIDER_PROMPT',
            ])
            ->assertOk()
            ->assertJsonPath('messages.1.metadata.fallback_reason', 'provider_request_failed');

        Http::assertSentCount(1);
        $providerRequest = AiProviderRequest::query()->sole();
        $this->assertSame(AiProviderRequest::StatusFailed, $providerRequest->status);
        $this->assertSame('RuntimeException', $providerRequest->error_code);
        $this->assertNull($providerRequest->input_tokens);
        $this->assertNull($providerRequest->total_tokens);
        $this->assertArrayNotHasKey('content', $providerRequest->getAttributes());
        $this->assertArrayNotHasKey('prompt', $providerRequest->getAttributes());
        $this->assertArrayNotHasKey('response', $providerRequest->getAttributes());
    }

    public function test_provider_budget_stops_a_tool_investigation_before_another_paid_call(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/v1/responses' => Http::sequence()
                ->push([
                    'id' => 'resp_tool',
                    'status' => 'completed',
                    'output' => [[
                        'type' => 'function_call',
                        'call_id' => 'call_help',
                        'name' => 'search_owner_help',
                        'arguments' => '{"query":"customers"}',
                    ]],
                ])
                ->push($this->openAiResponse('Should not be requested.')),
        ]);
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $this->configureOpenAi([
            'firewall_user_provider_calls_per_hour' => 1,
            'firewall_user_provider_calls_per_day' => 1,
        ]);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'How do I add a customer?',
            ])
            ->assertOk()
            ->assertJsonPath('messages.1.metadata.fallback_reason', 'ai_provider_rate_limited')
            ->assertJsonPath('messages.1.metadata.limit_scope', 'user_hour');

        Http::assertSentCount(1);
        $this->assertSame(1, AiProviderRequest::query()->count());
    }

    public function test_every_tool_round_and_final_synthesis_is_counted_as_a_paid_request(): void
    {
        Http::preventStrayRequests();
        $sequence = Http::sequence();

        foreach (range(1, 4) as $round) {
            $sequence->push($this->openAiToolResponse($round));
        }

        $sequence->push($this->openAiResponse('Final bounded synthesis.'));
        Http::fake(['api.openai.com/v1/responses' => $sequence]);
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $this->configureOpenAi();

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'Keep checking the help before answering.',
            ])
            ->assertOk()
            ->assertJsonPath('messages.1.content', 'Final bounded synthesis.');

        Http::assertSentCount(5);
        $this->assertSame(
            [
                AiProviderRequest::TypeInference,
                AiProviderRequest::TypeInference,
                AiProviderRequest::TypeInference,
                AiProviderRequest::TypeInference,
                AiProviderRequest::TypeFinalSynthesis,
            ],
            AiProviderRequest::query()->oldest('id')->pluck('request_type')->all(),
        );
        $this->assertSame(
            [1, 2, 3, 4, 5],
            AiProviderRequest::query()->oldest('id')->pluck('provider_round')->all(),
        );
    }

    public function test_ollama_usage_is_normalized_without_inventing_unavailable_token_values(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'disposition' => 'answer',
                        'answer' => 'Ollama answer.',
                        'follow_up_actions' => [],
                        'action' => null,
                        'calendar_reference' => null,
                        'reason' => 'studio question',
                    ]),
                ],
                'prompt_eval_count' => 70,
                'eval_count' => 20,
            ]),
        ]);
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $this->configureOllama();

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'Studio question.',
            ])
            ->assertOk();

        $providerRequest = AiProviderRequest::query()->sole();
        $this->assertSame(70, $providerRequest->input_tokens);
        $this->assertNull($providerRequest->cached_input_tokens);
        $this->assertSame(20, $providerRequest->output_tokens);
        $this->assertNull($providerRequest->reasoning_tokens);
        $this->assertSame(90, $providerRequest->total_tokens);
    }

    public function test_manual_resets_clear_user_restriction_and_studio_counters(): void
    {
        $administrator = User::factory()->create([
            'system_role' => SystemRole::PlatformAdmin->value,
        ]);
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $setting = PlatformAiSetting::factory()->create([
            'firewall_user_turns_per_minute' => 1,
            'firewall_account_turns_per_day' => 1,
        ]);
        $firewall = app(StudioAiUsageFirewall::class);
        $this->assertTrue($firewall->admitTurn($account, $user, 'dashboard_chat', $setting)->allowed);
        AiUsageRestriction::factory()->for($user)->for($account, 'lastAccount')->create([
            'consecutive_out_of_scope_count' => 5,
            'cooldown_level' => 2,
            'blocked_reason' => 'consecutive_out_of_scope',
            'blocked_until' => now()->addHour(),
        ]);

        $this->actingAs($administrator)
            ->post(route('platform.ai-usage.users.reset', $user))
            ->assertRedirect();
        $restriction = AiUsageRestriction::whereBelongsTo($user)->firstOrFail();
        $this->assertSame(0, $restriction->consecutive_out_of_scope_count);
        $this->assertSame(0, $restriction->cooldown_level);
        $this->assertNull($restriction->blocked_until);
        $this->assertSame($administrator->id, $restriction->manually_unblocked_by_user_id);

        $this->actingAs($administrator)
            ->post(route('platform.ai-usage.accounts.reset', $account))
            ->assertRedirect();

        $this->assertTrue($firewall->admitTurn($account, $user, 'dashboard_chat', $setting)->allowed);
    }

    public function test_platform_usage_page_aggregates_safe_metadata_and_never_renders_raw_content(): void
    {
        $administrator = User::factory()->create([
            'system_role' => SystemRole::PlatformAdmin->value,
        ]);
        $account = Account::factory()->create(['name' => 'Safe Studio']);
        $user = User::factory()->create(['name' => 'Safe User']);
        $conversation = AiConversation::factory()->for($account)->for($user)->create([
            'channel' => 'dashboard_chat',
        ]);
        AiConversationMessage::factory()->for($account)->for($conversation, 'conversation')->create([
            'role' => AiConversationMessageRole::User->value,
            'content' => 'SECRET_PROMPT_CONTENT',
            'occurred_at' => now(),
        ]);
        AiConversationMessage::factory()->for($account)->for($conversation, 'conversation')->create([
            'role' => AiConversationMessageRole::Assistant->value,
            'content' => 'SECRET_RESPONSE_CONTENT',
            'metadata' => ['fallback_reason' => 'ai_rate_limited'],
            'occurred_at' => now(),
        ]);
        AiProviderRequest::factory()->for($account)->for($user)->create([
            'provider' => AiProvider::OpenAiApiKey->value,
            'model' => 'gpt-5.5',
            'total_tokens' => 321,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->actingAs($administrator)
            ->get(route('platform.ai-usage.index', [
                'period' => 'today',
                'account_id' => $account->id,
                'user_id' => $user->id,
                'provider' => AiProvider::OpenAiApiKey->value,
                'model' => 'gpt-5.5',
                'status' => AiProviderRequest::StatusSucceeded,
            ]))
            ->assertOk()
            ->assertSee(__('app.ai_limits_usage'))
            ->assertSee('Safe Studio')
            ->assertSee('Safe User')
            ->assertSee('gpt-5.5')
            ->assertSee('321')
            ->assertDontSee('SECRET_PROMPT_CONTENT')
            ->assertDontSee('SECRET_RESPONSE_CONTENT');

        $this->actingAs(User::factory()->create())
            ->get(route('platform.ai-usage.index'))
            ->assertForbidden();
    }

    public function test_stale_telegram_authorization_is_rejected_before_image_download_or_inference(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 990],
            ]),
        ]);
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        PlatformAiSetting::factory()->create([
            'owner_ai_assistant_enabled' => true,
            'active_provider' => AiProvider::OllamaCloud->value,
            'active_model' => 'gemma3:27b-cloud',
        ]);
        $installation = TelegramBotInstallation::factory()->platformOwner()->create();
        TelegramChatAuthorization::factory()->for($account)->create([
            'telegram_bot_installation_id' => $installation->id,
            'user_id' => $owner->id,
            'profile' => TelegramBotProfile::Owner->value,
            'telegram_chat_id' => '9001',
            'telegram_user_id' => '9002',
        ]);
        $account->memberships()->whereBelongsTo($owner)->delete();

        $this->postJson(route('api.v1.telegram.webhooks.handle', $installation->webhookKey()), [
            'update_id' => 9003,
            'message' => [
                'message_id' => 9004,
                'chat' => ['id' => 9001, 'type' => 'private'],
                'from' => ['id' => 9002],
                'caption' => 'Please inspect.',
                'photo' => [[
                    'file_id' => 'photo-file-id',
                    'file_unique_id' => 'photo-unique-id',
                    'file_size' => 100,
                    'width' => 100,
                    'height' => 100,
                ]],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/getFile')
            || str_contains($request->url(), '/file/bot')
            || str_contains($request->url(), '/api/chat'));
        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '9001',
            'direction' => 'outbound',
            'text' => __('app.telegram_ai_access_expired'),
        ]);
        $this->assertSame(0, AiProviderRequest::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function firewallSettingsPayload(array $overrides = []): array
    {
        return [
            'firewall_enabled' => true,
            'firewall_user_turns_per_minute' => 6,
            'firewall_user_turns_per_hour' => 30,
            'firewall_user_turns_per_day' => 100,
            'firewall_admin_turns_per_minute' => 20,
            'firewall_admin_turns_per_hour' => 100,
            'firewall_admin_turns_per_day' => 500,
            'firewall_account_turns_per_day' => 500,
            'firewall_user_provider_calls_per_hour' => 90,
            'firewall_user_provider_calls_per_day' => 300,
            'firewall_admin_provider_calls_per_hour' => 300,
            'firewall_admin_provider_calls_per_day' => 1500,
            'firewall_account_provider_calls_per_day' => 1500,
            'firewall_user_out_of_scope_streak' => 5,
            'firewall_admin_out_of_scope_streak' => 10,
            'firewall_cooldown_first_minutes' => 60,
            'firewall_cooldown_second_minutes' => 360,
            'firewall_cooldown_third_minutes' => 1440,
            'firewall_escalation_reset_days' => 7,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureOpenAi(array $overrides = []): PlatformAiSetting
    {
        PlatformAiSetting::query()->delete();
        PlatformAiProviderCredential::query()->delete();
        $setting = PlatformAiSetting::factory()->create([
            'owner_ai_assistant_enabled' => true,
            'active_provider' => AiProvider::OpenAiApiKey->value,
            'active_model' => 'gpt-5.5',
            ...$overrides,
        ]);
        PlatformAiProviderCredential::factory()->create([
            'provider' => AiProvider::OpenAiApiKey->value,
            'model' => 'gpt-5.5',
            'credentials' => ['api_key' => 'test-openai-key'],
            'is_configured' => true,
        ]);

        return $setting;
    }

    private function configureOllama(): void
    {
        PlatformAiSetting::query()->delete();
        PlatformAiProviderCredential::query()->delete();
        PlatformAiSetting::factory()->create([
            'owner_ai_assistant_enabled' => true,
            'active_provider' => AiProvider::OllamaCloud->value,
            'active_model' => 'gemma3:27b-cloud',
        ]);
        PlatformAiProviderCredential::factory()->create([
            'provider' => AiProvider::OllamaCloud->value,
            'model' => 'gemma3:27b-cloud',
            'credentials' => ['api_key' => 'test-ollama-key'],
            'is_configured' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $usage
     * @return array<string, mixed>
     */
    private function openAiResponse(string $answer, ?array $usage = null): array
    {
        return [
            'id' => 'resp_firewall_test',
            'status' => 'completed',
            'output' => [[
                'id' => 'message_firewall_test',
                'type' => 'message',
                'role' => 'assistant',
                'status' => 'completed',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'disposition' => 'answer',
                        'answer' => $answer,
                        'follow_up_actions' => [],
                        'action' => null,
                        'calendar_reference' => null,
                        'reason' => 'studio question',
                        'visual_context' => null,
                    ]),
                    'annotations' => [],
                ]],
            ]],
            ...($usage === null ? [] : ['usage' => $usage]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function openAiToolResponse(int $round): array
    {
        return [
            'id' => 'resp_tool_round_'.$round,
            'status' => 'completed',
            'output' => [[
                'type' => 'function_call',
                'call_id' => 'call_help_'.$round,
                'name' => 'search_owner_help',
                'arguments' => '{"query":"customers"}',
            ]],
        ];
    }
}
