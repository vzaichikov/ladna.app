<?php

namespace Tests\Feature;

use App\Enums\AccountMode;
use App\Enums\AccountStatus;
use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailRecipientKind;
use App\Enums\EmailScenario;
use App\Enums\SmsSendingMode;
use App\Enums\SmsTopUpKind;
use App\Enums\SmsTopUpPaymentStatus;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSmsWallet;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPaymentMethod;
use App\Models\EmailDelivery;
use App\Models\SmsTopUpPayment;
use App\Models\SmsWalletLedgerEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsAutoTopUpRecoveryMigrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-10 10:00:00', 'UTC'));
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_exact_cap_notice_recovers_only_the_suspension_and_is_idempotent(): void
    {
        [$account, $wallet, $paymentMethod] = $this->suspendedWallet();
        $notice = $this->capNotice($account, $wallet->auto_top_up_suspended_at);
        $payment = SmsTopUpPayment::factory()->for($account)->for($wallet, 'wallet')->create([
            'account_subscription_payment_method_id' => $paymentMethod->id,
            'kind' => SmsTopUpKind::Automatic,
            'status' => SmsTopUpPaymentStatus::PaymentPaid,
            'amount_cents' => 23_000,
            'started_at' => now()->subDays(2),
            'paid_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        $ledgerEntry = SmsWalletLedgerEntry::factory()->for($account)->for($wallet, 'wallet')->create([
            'amount_cents' => 23_000,
            'balance_after_cents' => 23_896,
        ]);
        $expectedWallet = $wallet->refresh()->getRawOriginal();
        $expectedWallet['auto_top_up_suspended_at'] = null;
        $expectedNotice = $notice->refresh()->getRawOriginal();
        $expectedPayment = $payment->refresh()->getRawOriginal();
        $expectedLedgerEntry = $ledgerEntry->refresh()->getRawOriginal();
        $expectedPaymentMethod = $paymentMethod->refresh()->getRawOriginal();

        $this->recoveryMigration()->up();

        $this->assertSame($expectedWallet, $wallet->refresh()->getRawOriginal());
        $this->assertSame($expectedNotice, $notice->refresh()->getRawOriginal());
        $this->assertSame($expectedPayment, $payment->refresh()->getRawOriginal());
        $this->assertSame($expectedLedgerEntry, $ledgerEntry->refresh()->getRawOriginal());
        $this->assertSame($expectedPaymentMethod, $paymentMethod->refresh()->getRawOriginal());

        $this->recoveryMigration()->up();

        $this->assertSame($expectedWallet, $wallet->refresh()->getRawOriginal());
        $this->assertSame(1, $wallet->topUpPayments()->count());
        $this->assertSame(1, $wallet->ledgerEntries()->count());
        $this->assertSame(1, EmailDelivery::query()->whereBelongsTo($account)->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    #[DataProvider('unmatchedNoticeEvidence')]
    public function test_unproven_cap_notices_do_not_recover_a_suspension(string $evidence): void
    {
        [$account, $wallet] = $this->suspendedWallet();
        $suspendedAt = $wallet->auto_top_up_suspended_at;

        if ($evidence === 'warning_timestamp_mismatch') {
            $wallet->forceFill([
                'last_auto_top_up_failure_warning_at' => $suspendedAt->copy()->subSecond(),
            ])->save();
        } elseif ($evidence === 'missing_warning_timestamp') {
            $wallet->forceFill(['last_auto_top_up_failure_warning_at' => null])->save();
        }

        if ($evidence !== 'missing_notice') {
            $noticeAccount = $evidence === 'other_account' ? Account::factory()->create() : $account;
            $attributes = match ($evidence) {
                'stale_notice' => ['created_at' => $suspendedAt->copy()->subSecond()],
                'later_notice' => ['created_at' => $suspendedAt->copy()->addSecond()],
                'wrong_scenario' => ['scenario' => EmailScenario::SmsCreditLow],
                'non_cap_reason' => ['payload' => ['reason' => 'saved_card_or_payment_provider_unavailable']],
                'null_reason' => ['payload' => ['reason' => null]],
                'missing_reason' => ['payload' => []],
                default => [],
            };

            $this->capNotice($noticeAccount, $suspendedAt, $attributes);
        }

        $before = $wallet->refresh()->getRawOriginal();

        $this->recoveryMigration()->up();

        $this->assertSame($before, $wallet->refresh()->getRawOriginal());
    }

    /** @return array<string, array{string}> */
    public static function unmatchedNoticeEvidence(): array
    {
        return [
            'no email evidence' => ['missing_notice'],
            'different account' => ['other_account'],
            'earlier email timestamp' => ['stale_notice'],
            'later email timestamp' => ['later_notice'],
            'different email scenario' => ['wrong_scenario'],
            'different failure reason' => ['non_cap_reason'],
            'null failure reason' => ['null_reason'],
            'missing failure reason' => ['missing_reason'],
            'different warning timestamp' => ['warning_timestamp_mismatch'],
            'no warning timestamp' => ['missing_warning_timestamp'],
        ];
    }

    #[DataProvider('conflictingNoticeEvidence')]
    public function test_a_same_time_or_later_non_cap_notice_prevents_recovery(array $payload, int $secondsAfter): void
    {
        [$account, $wallet] = $this->suspendedWallet();
        $this->capNotice($account, $wallet->auto_top_up_suspended_at);
        $this->capNotice($account, $wallet->auto_top_up_suspended_at->copy()->addSeconds($secondsAfter), [
            'payload' => $payload,
        ]);
        $before = $wallet->refresh()->getRawOriginal();

        $this->recoveryMigration()->up();

        $this->assertSame($before, $wallet->refresh()->getRawOriginal());
    }

    /** @return array<string, array{array{reason?: string|null}, int}> */
    public static function conflictingNoticeEvidence(): array
    {
        return [
            'same-second card failure' => [['reason' => 'card_declined'], 0],
            'later card failure' => [['reason' => 'card_declined'], 1],
            'same-second unknown failure' => [['reason' => null], 0],
            'later missing reason' => [[], 1],
        ];
    }

    #[DataProvider('blockingPaymentEvidence')]
    public function test_automatic_payment_failure_or_in_flight_payment_prevents_recovery(
        SmsTopUpPaymentStatus $status,
        ?string $terminalTimestamp,
        int $secondsAfter,
    ): void {
        [$account, $wallet, $paymentMethod] = $this->suspendedWallet();
        $this->capNotice($account, $wallet->auto_top_up_suspended_at);
        $attributes = [
            'account_subscription_payment_method_id' => $paymentMethod->id,
            'kind' => SmsTopUpKind::Automatic,
            'status' => $status,
            'started_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'updated_at' => $terminalTimestamp === null
                ? now()->subDays(2)
                : $wallet->auto_top_up_suspended_at->copy()->addSeconds($secondsAfter),
        ];

        if ($terminalTimestamp !== null) {
            $attributes[$terminalTimestamp] = $wallet->auto_top_up_suspended_at->copy()->addSeconds($secondsAfter);
        }

        $payment = SmsTopUpPayment::factory()->for($account)->for($wallet, 'wallet')->create($attributes);
        $before = $wallet->refresh()->getRawOriginal();
        $paymentBefore = $payment->refresh()->getRawOriginal();

        $this->recoveryMigration()->up();

        $this->assertSame($before, $wallet->refresh()->getRawOriginal());
        $this->assertSame($paymentBefore, $payment->refresh()->getRawOriginal());
    }

    /** @return array<string, array{SmsTopUpPaymentStatus, string|null, int}> */
    public static function blockingPaymentEvidence(): array
    {
        return [
            'same-second automatic failure' => [SmsTopUpPaymentStatus::PaymentFailed, 'failed_at', 0],
            'later automatic failure' => [SmsTopUpPaymentStatus::PaymentFailed, 'failed_at', 1],
            'same-second automatic cancellation' => [SmsTopUpPaymentStatus::PaymentCancelled, 'cancelled_at', 0],
            'later automatic expiry' => [SmsTopUpPaymentStatus::PaymentExpired, 'expired_at', 1],
            'old payment still started' => [SmsTopUpPaymentStatus::PaymentStarted, null, 0],
            'old payment still pending' => [SmsTopUpPaymentStatus::PaymentPending, null, 0],
        ];
    }

    #[DataProvider('unsafePaymentMethodEvidence')]
    public function test_changed_or_inactive_payment_method_prevents_recovery(string $evidence): void
    {
        [$account, $wallet, $paymentMethod] = $this->suspendedWallet();
        $this->capNotice($account, $wallet->auto_top_up_suspended_at);
        $attributes = match ($evidence) {
            'changed_same_second' => ['updated_at' => $wallet->auto_top_up_suspended_at],
            'changed_later' => ['updated_at' => $wallet->auto_top_up_suspended_at->copy()->addSecond()],
            'pending_verification' => ['status' => SubscriptionPaymentMethodStatus::PendingVerification],
            'failed' => ['status' => SubscriptionPaymentMethodStatus::Failed],
            'revoked' => ['status' => SubscriptionPaymentMethodStatus::Revoked],
            'revoked_timestamp' => ['revoked_at' => now()->subDays(2)],
            'missing_token' => ['provider_card_token' => null],
            'missing_verification' => ['verified_at' => null],
        };
        $paymentMethod->timestamps = false;
        $paymentMethod->forceFill($attributes)->save();
        $before = $wallet->refresh()->getRawOriginal();
        $paymentMethodBefore = $paymentMethod->refresh()->getRawOriginal();

        $this->recoveryMigration()->up();

        $this->assertSame($before, $wallet->refresh()->getRawOriginal());
        $this->assertSame($paymentMethodBefore, $paymentMethod->refresh()->getRawOriginal());
    }

    /** @return array<string, array{string}> */
    public static function unsafePaymentMethodEvidence(): array
    {
        return [
            'card changed at suspension' => ['changed_same_second'],
            'card changed after suspension' => ['changed_later'],
            'pending verification' => ['pending_verification'],
            'failed verification' => ['failed'],
            'revoked status' => ['revoked'],
            'revoked timestamp' => ['revoked_timestamp'],
            'no saved token' => ['missing_token'],
            'no verification timestamp' => ['missing_verification'],
        ];
    }

    #[DataProvider('ineligibleWalletEvidence')]
    public function test_disabled_and_demo_wallets_remain_unchanged(string $evidence): void
    {
        [$account, $wallet] = $this->suspendedWallet();
        $this->capNotice($account, $wallet->auto_top_up_suspended_at);

        if ($evidence === 'disabled_auto_top_up') {
            $wallet->forceFill(['auto_top_up_enabled' => false])->save();
        } elseif ($evidence === 'demo_account') {
            $account->forceFill(['mode' => AccountMode::DemoReadonly])->save();
        } else {
            $account->forceFill(['status' => AccountStatus::Suspended])->save();
        }

        $before = $wallet->refresh()->getRawOriginal();

        $this->recoveryMigration()->up();

        $this->assertSame($before, $wallet->refresh()->getRawOriginal());
    }

    /** @return array<string, array{string}> */
    public static function ineligibleWalletEvidence(): array
    {
        return [
            'automatic top-up disabled' => ['disabled_auto_top_up'],
            'read-only demo' => ['demo_account'],
            'suspended account' => ['suspended_account'],
        ];
    }

    /** @return array{Account, AccountSmsWallet, AccountSubscriptionPaymentMethod} */
    private function suspendedWallet(): array
    {
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $account->customerAuthSetting()->create(['sms_sending_mode' => SmsSendingMode::LadnaService]);
        $subscription = AccountSubscription::factory()->for($account)->create([
            'status' => SubscriptionStatus::Active,
        ]);
        $paymentMethod = AccountSubscriptionPaymentMethod::factory()->for($subscription, 'subscription')->create([
            'account_id' => $account->id,
            'status' => SubscriptionPaymentMethodStatus::Active,
            'provider_card_token' => 'migration-test-card-token',
            'verified_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        $wallet = AccountSmsWallet::factory()->for($account)->create([
            'balance_cents' => 896,
            'reserved_cents' => 816,
            'auto_top_up_enabled' => true,
            'auto_top_up_threshold_cents' => 10_000,
            'auto_top_up_target_cents' => 20_000,
            'auto_top_up_monthly_cap_cents' => 30_000,
            'auto_top_up_monthly_spent_cents' => 23_000,
            'auto_top_up_monthly_period' => '2026-09-01',
            'auto_top_up_suspended_at' => now()->subDay(),
            'last_auto_top_up_failure_warning_at' => now()->subDay(),
            'last_low_balance_warning_at' => now()->subHours(12),
        ]);

        return [$account, $wallet, $paymentMethod];
    }

    /** @param array<string, mixed> $attributes */
    private function capNotice(Account $account, Carbon $suspendedAt, array $attributes = []): EmailDelivery
    {
        return EmailDelivery::factory()->for($account)->create([
            'scenario' => EmailScenario::SmsAutoTopUpFailed,
            'status' => EmailDeliveryStatus::Sent,
            'recipient_kind' => EmailRecipientKind::StudioOwner,
            'subject_key' => EmailScenario::SmsAutoTopUpFailed->subjectKey(),
            'content_view' => EmailScenario::SmsAutoTopUpFailed->contentView(),
            'payload' => ['reason' => 'monthly_cap_exceeded'],
            'created_at' => $suspendedAt,
            'queued_at' => $suspendedAt,
            'sent_at' => $suspendedAt->copy()->addMinutes(5),
            ...$attributes,
        ]);
    }

    private function recoveryMigration(): Migration
    {
        return require database_path('migrations/2026_09_10_070538_recover_monthly_limited_sms_wallets.php');
    }
}
