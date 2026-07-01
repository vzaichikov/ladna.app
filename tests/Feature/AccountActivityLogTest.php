<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AccountActivityLog;
use App\Models\AccountMembership;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\AiPendingAction;
use App\Models\McpToolInvocation;
use App\Models\TelegramAuthorizationSelection;
use App\Models\TelegramAuthorizationSelectionCandidate;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Support\AccountActivityLogSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountActivityLogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_successful_account_mutation_is_logged_without_payload(): void
    {
        $owner = User::factory()->create(['name' => 'Studio Owner']);
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.api-tokens.store', $account), [
                'name' => 'Website form',
            ])
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'api']));

        $activityLog = AccountActivityLog::whereBelongsTo($account)->firstOrFail();

        $this->assertSame('dashboard.accounts.api-tokens.store', $activityLog->action);
        $this->assertSame('POST', $activityLog->method);
        $this->assertSame(302, $activityLog->status_code);
        $this->assertSame($owner->id, $activityLog->actor_user_id);
        $this->assertSame('Studio Owner', $activityLog->actor_name);
        $this->assertSame('owner', $activityLog->actor_role);
        $this->assertSame(Account::class, $activityLog->subject_type);
        $this->assertSame($account->id, $activityLog->subject_id);
        $this->assertStringNotContainsString('Website form', $activityLog->url);
    }

    public function test_failed_validation_is_not_logged(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.api-tokens.store', $account), [
                'name' => '',
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, AccountActivityLog::whereBelongsTo($account)->count());
    }

    public function test_disabled_global_activity_log_setting_stops_recording(): void
    {
        AccountActivityLogSettings::setEnabled(false);
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.api-tokens.store', $account), [
                'name' => 'Website form',
            ])
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'api']));

        $this->assertSame(0, AccountActivityLog::whereBelongsTo($account)->count());
    }

    public function test_activity_log_filters_by_action_actor_and_date(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $otherAccount = Account::factory()->create();

        AccountActivityLog::factory()
            ->for($account)
            ->create([
                'action' => 'dashboard.accounts.customers.update',
                'actor_name' => 'Filtered Actor',
                'actor_email' => 'filtered@example.com',
                'occurred_at' => Carbon::parse('2026-06-20 11:00:00'),
                'subject_label' => 'Visible Customer',
            ]);
        AccountActivityLog::factory()
            ->for($account)
            ->create([
                'action' => 'dashboard.accounts.customers.update',
                'actor_name' => 'Old Actor',
                'actor_email' => 'old@example.com',
                'occurred_at' => Carbon::parse('2026-06-18 11:00:00'),
                'subject_label' => 'Old Customer',
            ]);
        AccountActivityLog::factory()
            ->for($account)
            ->create([
                'action' => 'dashboard.accounts.api-tokens.store',
                'actor_name' => 'Wrong Action Actor',
                'actor_email' => 'wrong-action@example.com',
                'occurred_at' => Carbon::parse('2026-06-20 12:00:00'),
                'subject_label' => 'Wrong Action Customer',
            ]);
        AccountActivityLog::factory()
            ->for($otherAccount)
            ->create([
                'action' => 'dashboard.accounts.customers.update',
                'actor_name' => 'Other Account Actor',
                'actor_email' => 'other-account@example.com',
                'occurred_at' => Carbon::parse('2026-06-20 11:00:00'),
                'subject_label' => 'Other Account Customer',
            ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.activity-logs.index', [
                'account' => $account,
                'action' => 'dashboard.accounts.customers.update',
                'actor' => 'filtered@example.com',
                'date_from' => '2026-06-20',
                'date_to' => '2026-06-20',
            ]))
            ->assertOk()
            ->assertSee('Filtered Actor')
            ->assertSee('Visible Customer')
            ->assertDontSee('Old Actor')
            ->assertDontSee('Wrong Action Actor')
            ->assertDontSee('Other Account Actor');
    }

    public function test_activity_log_displays_and_filters_dates_in_account_timezone(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'America/New_York']);
        $account->addOwner($owner);

        AccountActivityLog::factory()
            ->for($account)
            ->create([
                'actor_name' => 'Timezone Actor',
                'occurred_at' => Carbon::parse('2026-06-20 02:30:00', 'UTC'),
                'subject_label' => 'Timezone Customer',
            ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.activity-logs.index', [
                'account' => $account,
                'date_from' => '2026-06-19',
                'date_to' => '2026-06-19',
            ]))
            ->assertOk()
            ->assertSee('2026-06-19 22:30')
            ->assertSee('Timezone Actor')
            ->assertSee('Timezone Customer')
            ->assertDontSee('2026-06-20 02:30');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.activity-logs.index', [
                'account' => $account,
                'date_from' => '2026-06-20',
                'date_to' => '2026-06-20',
            ]))
            ->assertOk()
            ->assertDontSee('Timezone Actor');
    }

    public function test_trainer_needs_view_activity_log_permission(): void
    {
        $trainer = User::factory()->create();
        $account = Account::factory()->create();
        $membership = AccountMembership::factory()
            ->for($account)
            ->for($trainer, 'user')
            ->create([
                'role' => AccountRole::Trainer->value,
                'permissions' => [],
            ]);

        $this->actingAs($trainer)
            ->get(route('dashboard.accounts.activity-logs.index', $account))
            ->assertForbidden();

        $membership->update([
            'permissions' => [StudioPermission::ViewActivityLog->value],
        ]);

        $this->actingAs($trainer)
            ->get(route('dashboard.accounts.activity-logs.index', $account))
            ->assertOk();
    }

    public function test_activity_log_prune_command_deletes_entries_older_than_configured_retention(): void
    {
        AccountActivityLogSettings::setRetentionDays(45);
        Carbon::setTestNow(Carbon::parse('2026-06-27 10:00:00'));
        $account = Account::factory()->create();
        $user = User::factory()->create();
        $installation = TelegramBotInstallation::factory()->platformOwner()->create();
        $authorization = TelegramChatAuthorization::factory()->for($account)->create([
            'telegram_bot_installation_id' => $installation->id,
            'user_id' => $user->id,
            'telegram_chat_id' => 'retention-chat',
        ]);
        $oldActivityLog = AccountActivityLog::factory()
            ->for($account)
            ->create(['occurred_at' => now()->subDays(46)]);
        $recentActivityLog = AccountActivityLog::factory()
            ->for($account)
            ->create(['occurred_at' => now()->subDays(44)]);
        $oldUpdate = TelegramUpdate::factory()->for($account)->create([
            'telegram_bot_installation_id' => $installation->id,
            'received_at' => now()->subDays(46),
            'created_at' => now()->subDays(46),
        ]);
        $recentUpdate = TelegramUpdate::factory()->for($account)->create([
            'telegram_bot_installation_id' => $installation->id,
            'received_at' => now()->subDays(44),
            'created_at' => now()->subDays(44),
        ]);
        $oldMessage = TelegramMessage::factory()->for($account)->create([
            'telegram_bot_installation_id' => $installation->id,
            'telegram_chat_authorization_id' => $authorization->id,
            'telegram_update_id' => $oldUpdate->id,
            'sent_at' => now()->subDays(46),
            'created_at' => now()->subDays(46),
        ]);
        $recentMessage = TelegramMessage::factory()->for($account)->create([
            'telegram_bot_installation_id' => $installation->id,
            'telegram_chat_authorization_id' => $authorization->id,
            'telegram_update_id' => $recentUpdate->id,
            'sent_at' => now()->subDays(44),
            'created_at' => now()->subDays(44),
        ]);
        $telegramConversation = AiConversation::factory()->for($account)->create([
            'telegram_chat_authorization_id' => $authorization->id,
            'user_id' => $user->id,
            'channel' => 'telegram_owner',
            'last_message_at' => now(),
        ]);
        $staleTelegramConversation = AiConversation::factory()->for($account)->create([
            'telegram_chat_authorization_id' => $authorization->id,
            'user_id' => $user->id,
            'channel' => 'telegram_owner',
            'last_message_at' => now()->subDays(46),
            'created_at' => now()->subDays(46),
        ]);
        $dashboardConversation = AiConversation::factory()->for($account)->create([
            'channel' => 'dashboard_chat',
            'last_message_at' => now()->subDays(46),
            'created_at' => now()->subDays(46),
        ]);
        $oldAiMessage = AiConversationMessage::factory()->for($account)->for($telegramConversation, 'conversation')->create([
            'telegram_message_id' => $oldMessage->id,
            'occurred_at' => now()->subDays(46),
            'created_at' => now()->subDays(46),
        ]);
        $recentAiMessage = AiConversationMessage::factory()->for($account)->for($telegramConversation, 'conversation')->create([
            'telegram_message_id' => $recentMessage->id,
            'occurred_at' => now()->subDays(44),
            'created_at' => now()->subDays(44),
        ]);
        $dashboardAiMessage = AiConversationMessage::factory()->for($account)->for($dashboardConversation, 'conversation')->create([
            'occurred_at' => now()->subDays(46),
            'created_at' => now()->subDays(46),
        ]);
        $oldPendingAction = AiPendingAction::factory()->for($account)->for($telegramConversation, 'conversation')->create([
            'user_id' => $user->id,
            'expires_at' => now()->subDays(46),
            'created_at' => now()->subDays(46),
            'updated_at' => now()->subDays(46),
        ]);
        $recentPendingAction = AiPendingAction::factory()->for($account)->for($telegramConversation, 'conversation')->create([
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(20),
            'created_at' => now()->subDays(44),
            'updated_at' => now()->subDays(44),
        ]);
        $oldMcpInvocation = McpToolInvocation::factory()->for($account)->for($telegramConversation, 'conversation')->for($oldAiMessage, 'conversationMessage')->create([
            'account_api_token_id' => null,
            'started_at' => now()->subDays(46),
            'finished_at' => now()->subDays(46),
            'created_at' => now()->subDays(46),
        ]);
        $recentMcpInvocation = McpToolInvocation::factory()->for($account)->for($telegramConversation, 'conversation')->for($recentAiMessage, 'conversationMessage')->create([
            'account_api_token_id' => null,
            'started_at' => now()->subDays(44),
            'finished_at' => now()->subDays(44),
            'created_at' => now()->subDays(44),
        ]);
        $dashboardMcpInvocation = McpToolInvocation::factory()->for($account)->for($dashboardConversation, 'conversation')->for($dashboardAiMessage, 'conversationMessage')->create([
            'account_api_token_id' => null,
            'started_at' => now()->subDays(46),
            'finished_at' => now()->subDays(46),
            'created_at' => now()->subDays(46),
        ]);
        $oldSelection = TelegramAuthorizationSelection::factory()->for($installation, 'installation')->create([
            'expires_at' => now()->subDays(46),
            'created_at' => now()->subDays(46),
        ]);
        $oldSelectionCandidate = TelegramAuthorizationSelectionCandidate::factory()
            ->for($oldSelection, 'selection')
            ->for($account)
            ->for($user)
            ->create();
        $recentSelection = TelegramAuthorizationSelection::factory()->for($installation, 'installation')->create([
            'expires_at' => now()->subDays(44),
            'created_at' => now()->subDays(44),
        ]);

        $this->artisan('account-activity-logs:prune')
            ->expectsOutput(__('app.account_activity_logs_pruned', ['count' => 1]))
            ->expectsOutput(__('app.telegram_logs_pruned', [
                'messages' => 1,
                'updates' => 1,
                'conversation_messages' => 1,
                'conversations' => 1,
                'pending_actions' => 1,
                'tool_invocations' => 1,
                'authorization_selections' => 1,
            ]))
            ->assertSuccessful();

        $this->assertModelMissing($oldActivityLog);
        $this->assertModelExists($recentActivityLog);
        $this->assertModelMissing($oldUpdate);
        $this->assertModelExists($recentUpdate);
        $this->assertModelMissing($oldMessage);
        $this->assertModelExists($recentMessage);
        $this->assertModelMissing($oldAiMessage);
        $this->assertModelExists($recentAiMessage);
        $this->assertModelMissing($staleTelegramConversation);
        $this->assertModelExists($telegramConversation);
        $this->assertModelExists($dashboardConversation);
        $this->assertModelExists($dashboardAiMessage);
        $this->assertModelMissing($oldPendingAction);
        $this->assertModelExists($recentPendingAction);
        $this->assertModelMissing($oldMcpInvocation);
        $this->assertModelExists($recentMcpInvocation);
        $this->assertModelExists($dashboardMcpInvocation);
        $this->assertModelMissing($oldSelection);
        $this->assertModelMissing($oldSelectionCandidate);
        $this->assertModelExists($recentSelection);

        Carbon::setTestNow();
    }
}
