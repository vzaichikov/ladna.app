<?php

namespace Tests\Feature;

use App\Enums\AccountMode;
use App\Enums\AccountRole;
use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
use App\Enums\SmsTopUpPaymentStatus;
use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\AccountSmsWallet;
use App\Models\AccountSubscription;
use App\Models\SmsDelivery;
use App\Models\SmsTopUpPayment;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\TelegramAlert;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Support\Sms\SmsAccountNotifier;
use App\Support\Sms\SmsAccountStatus;
use App\Support\Sms\SmsServiceSettings;
use App\Support\Sms\SmsWalletService;
use App\Support\Telegram\Alerts\QueueSmsAccountTelegramAlert;
use App\Support\Telegram\Alerts\TelegramAlertSender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsAccountTelegramAlertTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 07:00:00', 'UTC'));
        Mail::fake();
        Http::preventStrayRequests();
        SystemSetting::setValue(SmsServiceSettings::EnabledKey, '1');
    }

    #[DataProvider('issues')]
    public function test_each_current_sms_issue_queues_an_owner_alert_with_the_account_link(string $issue): void
    {
        ['account' => $account, 'authorization' => $authorization] = $this->fixture($this->issueValues($issue));

        $this->assertSame($issue, app(SmsAccountStatus::class)->forAccount($account)['issue']);
        $this->notify($account, $issue);

        $alert = $account->telegramAlerts()->sole();
        $this->assertSame(TelegramAlertType::SmsAccountIssue, $alert->type);
        $this->assertSame(TelegramAlertRecipientKind::StudioOwner, $alert->recipient_kind);
        $this->assertSame(TelegramAlertStatus::Pending, $alert->status);
        $this->assertSame($authorization->id, $alert->telegram_chat_authorization_id);
        $this->assertStringContainsString($account->name, $alert->text);
        $this->assertStringContainsString(route('dashboard.accounts.sms-account.show', $account), $alert->text);
        Http::assertNothingSent();
    }

    public static function issues(): array
    {
        return [
            'low credit' => ['low_credit'],
            'outstanding amount' => ['outstanding'],
            'suspended automatic top-up' => ['auto_top_up_suspended'],
            'monthly cap' => ['monthly_cap'],
        ];
    }

    public function test_repeated_notices_and_queue_checks_do_not_duplicate_the_same_episode(): void
    {
        ['account' => $account] = $this->fixture();

        $this->notify($account, 'low_credit');
        $this->notify($account, 'low_credit');
        app(QueueSmsAccountTelegramAlert::class)->execute($account);
        app(QueueSmsAccountTelegramAlert::class)->execute($account);

        $this->assertSame(1, $account->telegramAlerts()->count());
        Mail::assertQueuedCount(1);
    }

    public function test_a_previously_emailed_issue_can_still_queue_telegram_without_resending_email(): void
    {
        ['account' => $account] = $this->fixture(['last_low_balance_warning_at' => now()->subDay()]);

        $this->notify($account, 'low_credit');
        $this->notify($account, 'low_credit');

        $this->assertSame(1, $account->telegramAlerts()->count());
        Mail::assertNothingQueued();
    }

    public function test_alerts_are_queued_only_for_current_connected_owners_of_this_account(): void
    {
        ['account' => $account, 'installation' => $installation, 'authorization' => $authorization] = $this->fixture();
        $secondOwner = User::factory()->create();
        $account->addOwner($secondOwner);
        $secondAuthorization = $this->authorizeOwner($account, $secondOwner, $installation);
        $manager = User::factory()->create();
        $account->users()->attach($manager, ['role' => AccountRole::Manager->value]);
        $managerAuthorization = $this->authorizeOwner($account, $manager, $installation);
        $revokedOwner = User::factory()->create();
        $account->addOwner($revokedOwner);
        $revokedAuthorization = $this->authorizeOwner($account, $revokedOwner, $installation);
        $revokedAuthorization->update(['status' => TelegramChatAuthorizationStatus::Revoked]);
        $otherAccount = Account::factory()->create();
        $otherOwner = User::factory()->create();
        $otherAccount->addOwner($otherOwner);
        $otherAuthorization = $this->authorizeOwner($otherAccount, $otherOwner, $installation);

        $this->notify($account, 'low_credit');

        $this->assertEqualsCanonicalizing(
            [$authorization->id, $secondAuthorization->id],
            $account->telegramAlerts()->pluck('telegram_chat_authorization_id')->all(),
        );
        foreach ([$managerAuthorization, $revokedAuthorization, $otherAuthorization] as $excluded) {
            $this->assertFalse(TelegramAlert::query()->where('telegram_chat_authorization_id', $excluded->id)->exists());
        }
    }

    #[DataProvider('queueBlockers')]
    public function test_ineligible_accounts_do_not_queue_external_alerts(string $blocker): void
    {
        $fixture = $this->fixture();
        $this->applyBlocker($fixture, $blocker);

        $this->notify($fixture['account']->fresh(), 'low_credit');

        $this->assertSame(0, $fixture['account']->telegramAlerts()->count());
        Http::assertNothingSent();
    }

    public static function queueBlockers(): array
    {
        return [
            'scenario disabled' => ['scenario_disabled'],
            'service disabled' => ['service_disabled'],
            'SMS disabled' => ['sms_disabled'],
            'own gateway' => ['own_gateway'],
            'free tariff' => ['free_tariff'],
            'tariff unavailable' => ['no_tariff'],
            'read-only demo' => ['demo'],
            'credit sufficient' => ['recovered'],
            'owner disconnected' => ['authorization_revoked'],
            'owner bot disabled' => ['bot_disabled'],
        ];
    }

    public function test_scheduled_checks_queue_an_existing_low_balance_only_once(): void
    {
        ['account' => $account] = $this->fixture();

        $this->artisan('sms-wallets:queue-alerts')->assertSuccessful();
        $this->artisan('sms-wallets:queue-alerts')->assertSuccessful();

        $this->assertSame(1, $account->telegramAlerts()->count());
        Http::assertNothingSent();
    }

    public function test_new_wallets_enable_owner_sms_telegram_alerts_by_default(): void
    {
        ['wallet' => $wallet] = $this->fixture();

        $this->assertTrue($wallet->refresh()->telegram_alerts_enabled);
    }

    public function test_only_a_studio_owner_can_toggle_sms_telegram_alerts_with_a_boolean(): void
    {
        ['account' => $account, 'wallet' => $wallet, 'owner' => $owner] = $this->fixture();
        $manager = User::factory()->create();
        $account->users()->attach($manager, ['role' => AccountRole::Manager->value]);
        $outsider = User::factory()->create();
        $platformAdmin = User::factory()->platformAdmin()->create();
        $url = route('dashboard.accounts.sms-account.telegram-alerts.update', $account);

        foreach ([$manager, $outsider, $platformAdmin] as $user) {
            $this->actingAs($user)->put($url, ['telegram_alerts_enabled' => false])->assertForbidden();
        }

        $this->actingAs($owner)->put($url, ['telegram_alerts_enabled' => 'invalid'])
            ->assertSessionHasErrors('telegram_alerts_enabled');
        $this->assertTrue($wallet->refresh()->telegram_alerts_enabled);
        $this->actingAs($owner)->put($url, ['telegram_alerts_enabled' => false])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertFalse($wallet->refresh()->telegram_alerts_enabled);
        $this->actingAs($owner)->put($url, ['telegram_alerts_enabled' => true])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($wallet->refresh()->telegram_alerts_enabled);
        Http::assertNothingSent();
    }

    #[DataProvider('deliveryBlockers')]
    public function test_delivery_rechecks_scenario_and_current_issue_before_contacting_telegram(string $blocker): void
    {
        $fixture = $this->fixture();
        $this->notify($fixture['account'], 'low_credit');
        $alert = $fixture['account']->telegramAlerts()->sole();
        $this->applyBlocker($fixture, $blocker);

        app(TelegramAlertSender::class)->sendAlertIds([$alert->id]);

        $this->assertSame(TelegramAlertStatus::Failed, $alert->refresh()->status);
        $this->assertSame(
            $blocker === 'scenario_disabled' ? 'sms_account_alerts_disabled' : 'sms_account_issue_no_longer_relevant',
            $alert->last_error,
        );
        Http::assertNothingSent();
    }

    public static function deliveryBlockers(): array
    {
        return [
            'scenario disabled after queueing' => ['scenario_disabled'],
            'credit recovered' => ['recovered'],
            'issue changed' => ['different_issue'],
            'new episode superseded old warning' => ['different_episode'],
            'service disabled after queueing' => ['service_disabled'],
            'SMS disabled after queueing' => ['sms_disabled'],
            'tariff changed to free' => ['free_tariff'],
        ];
    }

    #[DataProvider('restorableDeliveryGates')]
    public function test_alerts_resume_after_a_temporary_delivery_gate_is_restored_without_rewriting_failed_history(string $gate): void
    {
        $fixture = $this->fixture();
        $account = $fixture['account'];
        $this->notify($account, 'low_credit');
        $firstAlert = $account->telegramAlerts()->sole();
        $this->applyBlocker($fixture, $gate);
        app(TelegramAlertSender::class)->sendAlertIds([$firstAlert->id]);
        $this->assertSame(TelegramAlertStatus::Failed, $firstAlert->refresh()->status);

        if ($gate === 'scenario_disabled') {
            $fixture['wallet']->update(['telegram_alerts_enabled' => true]);
        } else {
            SystemSetting::setValue(SmsServiceSettings::EnabledKey, '1');
        }

        $this->notify($account->fresh(), 'low_credit');

        $this->assertSame(2, $account->telegramAlerts()->count());
        $newAlert = $account->telegramAlerts()->latest('id')->firstOrFail();
        $this->assertSame(TelegramAlertStatus::Pending, $newAlert->status);
        $this->assertSame(TelegramAlertStatus::Failed, $firstAlert->refresh()->status);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 814]])]);
        app(TelegramAlertSender::class)->sendAlertIds([$newAlert->id]);
        $this->assertSame(TelegramAlertStatus::Sent, $newAlert->refresh()->status);
        $this->notify($account->fresh(), 'low_credit');
        $this->assertSame(2, $account->telegramAlerts()->count());
        $this->assertSame(TelegramAlertStatus::Failed, $firstAlert->refresh()->status);
        Http::assertSentCount(1);
    }

    public static function restorableDeliveryGates(): array
    {
        return [
            'owner enables scenario again' => ['scenario_disabled'],
            'platform service is restored' => ['service_disabled'],
        ];
    }

    #[DataProvider('authorizationBlockers')]
    public function test_delivery_requires_the_original_authorized_current_owner(string $blocker): void
    {
        $fixture = $this->fixture();
        $this->notify($fixture['account'], 'low_credit');
        $alert = $fixture['account']->telegramAlerts()->sole();
        $this->applyBlocker($fixture, $blocker);

        app(TelegramAlertSender::class)->sendAlertIds([$alert->id]);

        $this->assertSame(
            $blocker === 'demo' ? TelegramAlertStatus::Pending : TelegramAlertStatus::Failed,
            $alert->refresh()->status,
        );
        Http::assertNothingSent();
    }

    public static function authorizationBlockers(): array
    {
        return [
            'authorization revoked' => ['authorization_revoked'],
            'owner membership removed' => ['owner_removed'],
            'authorization moved to another account' => ['authorization_wrong_account'],
            'bot disabled' => ['bot_disabled'],
            'studio became a read-only demo' => ['demo'],
        ];
    }

    public function test_delivery_retries_transient_errors_and_records_the_successful_outbound_message(): void
    {
        $allowSuccess = false;
        Http::fake([
            'api.telegram.org/*' => function () use (&$allowSuccess) {
                return $allowSuccess
                    ? Http::response(['ok' => true, 'result' => ['message_id' => 811]])
                    : Http::response(['ok' => false, 'description' => 'Too Many Requests'], 429);
            },
        ]);
        ['account' => $account, 'authorization' => $authorization] = $this->fixture();
        $this->notify($account, 'low_credit');
        $alert = $account->telegramAlerts()->sole();

        app(TelegramAlertSender::class)->sendAlertIds([$alert->id]);

        $this->assertSame(TelegramAlertStatus::Pending, $alert->refresh()->status);
        $this->assertSame(1, $alert->attempts);
        $this->assertNotNull($alert->next_attempt_at);
        $this->assertSame(0, TelegramMessage::query()->where('account_id', $account->id)->count());
        $allowSuccess = true;
        $this->travel(2)->minutes();
        app(TelegramAlertSender::class)->sendAlertIds([$alert->id]);

        $this->assertSame(TelegramAlertStatus::Sent, $alert->refresh()->status, $alert->last_error ?? '');
        $this->assertSame(2, $alert->attempts);
        $this->assertSame('811', $alert->telegram_message_id);
        $message = TelegramMessage::query()->where('account_id', $account->id)->sole();
        $this->assertSame($authorization->id, $message->telegram_chat_authorization_id);
        $this->assertSame($alert->text, $message->text);
        $this->assertSame($alert->id, $message->payload['telegram_alert_id']);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request): bool => $request['chat_id'] === $authorization->telegram_chat_id);
    }

    #[DataProvider('recoveryMethods')]
    public function test_wallet_recovery_allows_a_new_issue_episode_without_rewriting_sent_history(string $issue, string $method): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 812]])]);
        ['account' => $account, 'wallet' => $wallet, 'owner' => $owner] = $this->fixture($this->issueValues($issue));
        $this->notify($account, $issue);
        $firstAlert = $account->telegramAlerts()->sole();
        app(TelegramAlertSender::class)->sendAlertIds([$firstAlert->id]);
        $this->assertSame(TelegramAlertStatus::Sent, $firstAlert->refresh()->status);
        $service = app(SmsWalletService::class);

        if ($method === 'top_up') {
            $payment = SmsTopUpPayment::factory()->for($account)->for($wallet, 'wallet')->create([
                'amount_cents' => 10_000,
                'status' => SmsTopUpPaymentStatus::PaymentPaid,
            ]);
            $service->creditTopUp($payment);
        } else {
            $service->adjust($wallet, 10_000, $owner, 'Restore SMS funds');
        }

        $wallet->refresh();
        $this->assertNull(app(SmsAccountStatus::class)->forAccount($account->fresh())['issue']);
        $this->travel(2)->seconds();
        $debitCents = $issue === 'outstanding' ? $wallet->balance_cents + 200 : $wallet->balance_cents - 99;
        $service->adjust($wallet, -$debitCents, $owner, 'New SMS balance issue');
        $this->notify($account->fresh(), $issue);

        $this->assertSame(2, $account->telegramAlerts()->count());
        $this->assertSame(TelegramAlertStatus::Sent, $firstAlert->refresh()->status);
        $this->assertSame('812', $firstAlert->telegram_message_id);
        $this->assertNotSame($firstAlert->dedupe_key, $account->telegramAlerts()->latest('id')->first()->dedupe_key);
    }

    public static function recoveryMethods(): array
    {
        return [
            'low balance recurs after adjustment' => ['low_credit', 'adjust'],
            'low balance recurs after paid top-up' => ['low_credit', 'top_up'],
            'debt recurs after adjustment' => ['outstanding', 'adjust'],
            'debt recurs after paid top-up' => ['outstanding', 'top_up'],
        ];
    }

    public function test_released_reservation_recovers_the_warning_and_a_later_reservation_starts_a_new_episode(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 813]])]);
        ['account' => $account, 'wallet' => $wallet] = $this->fixture(['balance_cents' => 250]);
        $firstDelivery = SmsDelivery::factory()->for($account)->for($wallet, 'wallet')->create([
            'reserved_amount_cents' => 0,
            'sms_segment_price_cents' => 100,
        ]);
        $service = app(SmsWalletService::class);
        $this->assertTrue($service->reserve($firstDelivery, 200));
        $this->notify($account->fresh(), 'low_credit');
        $firstAlert = $account->telegramAlerts()->sole();
        app(TelegramAlertSender::class)->sendAlertIds([$firstAlert->id]);
        $this->assertSame(TelegramAlertStatus::Sent, $firstAlert->refresh()->status);

        $service->release($firstDelivery, SmsDeliveryStatus::Failed, 'provider_rejected', 'Provider rejected SMS.');

        $this->assertSame(250, $wallet->refresh()->spendableBalanceCents());
        $this->assertNull($wallet->last_low_balance_warning_at);
        $this->assertNull(app(SmsAccountStatus::class)->forAccount($account->fresh())['issue']);
        $this->travel(2)->seconds();
        $secondDelivery = SmsDelivery::factory()->for($account)->for($wallet, 'wallet')->create([
            'reserved_amount_cents' => 0,
            'sms_segment_price_cents' => 100,
        ]);
        $this->assertTrue($service->reserve($secondDelivery, 200));
        $this->notify($account->fresh(), 'low_credit');

        $this->assertSame(2, $account->telegramAlerts()->count());
        $this->assertSame(TelegramAlertStatus::Sent, $firstAlert->refresh()->status);
        $this->assertSame('813', $firstAlert->telegram_message_id);
        $this->assertNotSame($firstAlert->dedupe_key, $account->telegramAlerts()->latest('id')->first()->dedupe_key);
    }

    /**
     * @param  array<string, mixed>  $walletValues
     * @return array{account: Account, wallet: AccountSmsWallet, owner: User, installation: TelegramBotInstallation, authorization: TelegramChatAuthorization}
     */
    private function fixture(array $walletValues = []): array
    {
        $account = Account::factory()->create([
            'name' => 'Owner SMS Studio',
            'default_language' => 'en',
            'timezone' => 'Europe/Kyiv',
            'enable_telegram_alerts' => false,
        ]);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        AccountSubscription::factory()->for($account)->for(
            SubscriptionPlan::factory()->create(['sms_segment_price_cents' => 100]),
            'plan',
        )->create();
        $account->customerAuthSetting()->create(['sms_sending_mode' => SmsSendingMode::LadnaService]);
        $wallet = AccountSmsWallet::factory()->for($account)->create(['balance_cents' => 99, ...$walletValues]);
        $installation = TelegramBotInstallation::factory()->platformOwner()->create([
            'is_enabled' => true,
            'encrypted_token' => '123456:sms-owner-test-token',
        ]);
        $authorization = $this->authorizeOwner($account, $owner, $installation);

        return compact('account', 'wallet', 'owner', 'installation', 'authorization');
    }

    private function authorizeOwner(Account $account, User $user, TelegramBotInstallation $installation): TelegramChatAuthorization
    {
        return TelegramChatAuthorization::factory()->for($account)->for($user)->create([
            'telegram_bot_installation_id' => $installation->id,
            'telegram_chat_id' => 'sms-owner-chat-'.$user->id,
            'telegram_user_id' => 'sms-owner-user-'.$user->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function issueValues(string $issue): array
    {
        return match ($issue) {
            'outstanding' => ['outstanding_cents' => 200],
            'auto_top_up_suspended' => ['auto_top_up_enabled' => true, 'auto_top_up_suspended_at' => now()],
            'monthly_cap' => [
                'auto_top_up_enabled' => true,
                'auto_top_up_threshold_cents' => 1_000,
                'auto_top_up_target_cents' => 2_000,
                'auto_top_up_monthly_cap_cents' => 1_000,
                'auto_top_up_monthly_spent_cents' => 900,
                'auto_top_up_monthly_period' => now('Europe/Kyiv')->startOfMonth()->toDateString(),
            ],
            default => [],
        };
    }

    private function notify(Account $account, string $issue): void
    {
        $notifier = app(SmsAccountNotifier::class);

        match ($issue) {
            'outstanding' => $notifier->outstandingCredit($account),
            'auto_top_up_suspended' => $notifier->automaticTopUpFailed($account, 'saved_card_or_payment_provider_unavailable'),
            'monthly_cap' => $notifier->automaticTopUpFailed($account, 'monthly_cap_exceeded'),
            default => $notifier->lowCredit($account),
        };
    }

    /**
     * @param  array{account: Account, wallet: AccountSmsWallet, owner: User, installation: TelegramBotInstallation, authorization: TelegramChatAuthorization}  $fixture
     */
    private function applyBlocker(array $fixture, string $blocker): void
    {
        ['account' => $account, 'wallet' => $wallet, 'owner' => $owner, 'installation' => $installation, 'authorization' => $authorization] = $fixture;

        match ($blocker) {
            'scenario_disabled' => $wallet->update(['telegram_alerts_enabled' => false]),
            'service_disabled' => SystemSetting::setValue(SmsServiceSettings::EnabledKey, '0'),
            'sms_disabled' => $account->customerAuthSetting()->update(['sms_sending_mode' => SmsSendingMode::Disabled->value]),
            'own_gateway' => $account->customerAuthSetting()->update(['sms_sending_mode' => SmsSendingMode::OwnGateway->value]),
            'free_tariff' => $account->subscription->plan->update(['sms_segment_price_cents' => 0]),
            'no_tariff' => $account->subscription->plan->update(['sms_segment_price_cents' => null]),
            'demo' => $account->update(['mode' => AccountMode::DemoReadonly]),
            'recovered' => $wallet->update(['balance_cents' => 10_000]),
            'different_issue' => $wallet->update(['outstanding_cents' => 200]),
            'different_episode' => $wallet->update(['last_low_balance_warning_at' => now()->addMinute()]),
            'authorization_revoked' => $authorization->update(['status' => TelegramChatAuthorizationStatus::Revoked]),
            'owner_removed' => $account->users()->detach($owner),
            'authorization_wrong_account' => $authorization->update(['account_id' => Account::factory()->create()->id]),
            'bot_disabled' => $installation->update(['is_enabled' => false]),
        };
    }
}
