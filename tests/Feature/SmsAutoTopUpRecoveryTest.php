<?php

namespace Tests\Feature;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Enums\SmsSendingMode;
use App\Enums\SmsTopUpKind;
use App\Enums\SmsTopUpPaymentStatus;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Mail\TransactionalMail;
use App\Models\Account;
use App\Models\AccountSmsWallet;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPaymentMethod;
use App\Models\EmailDelivery;
use App\Models\IntegrationSetting;
use App\Models\SmsTopUpPayment;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Sms\SmsAutoTopUpService;
use App\Support\Sms\SmsServiceSettings;
use App\Support\Sms\SmsWalletService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsAutoTopUpRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string, mixed> */
    private array $gatewayResponse = [
        'invoiceId' => 'monthly-recovery-invoice',
        'status' => 'processing',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-31 20:59:00', 'UTC'));
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => fn () => Http::response($this->gatewayResponse),
        ]);
        SystemSetting::setValue(SmsServiceSettings::EnabledKey, '1');
        IntegrationSetting::factory()->create([
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
            'provider' => IntegrationProvider::Monopay->value,
            'category' => IntegrationCategory::Payment->value,
            'is_enabled' => true,
            'credentials' => ['api_token' => 'test-token'],
        ]);
    }

    public function test_monthly_limit_waits_without_partial_charges_or_repeated_failure_notices(): void
    {
        $wallet = $this->automaticWallet();

        $this->artisan('sms-wallets:auto-top-up')->assertSuccessful();
        $this->artisan('sms-wallets:auto-top-up')->assertSuccessful();
        app(SmsAutoTopUpService::class)->attempt($wallet->account);

        $this->assertNull($wallet->refresh()->auto_top_up_suspended_at);
        $this->assertSame(46_960, $wallet->auto_top_up_monthly_spent_cents);
        $this->assertNotNull($wallet->last_auto_top_up_failure_warning_at);
        $this->assertSame(0, $wallet->topUpPayments()->count());
        $notice = EmailDelivery::whereBelongsTo($wallet->account)->sole();
        $this->assertSame('monthly_cap_exceeded', $notice->payload['reason']);
        $this->assertSame('app.mail_subject_sms_auto_top_up_monthly_cap', $notice->subject_key);
        Mail::assertQueuedCount(1);
        Http::assertNothingSent();
    }

    public function test_scheduler_resumes_at_the_studios_month_boundary_and_starts_only_one_full_charge(): void
    {
        $wallet = $this->automaticWallet();
        $this->artisan('sms-wallets:auto-top-up')->assertSuccessful();
        Http::assertNothingSent();

        $this->travelTo(Carbon::parse('2026-08-31 21:00:00', 'UTC'));
        $this->artisan('sms-wallets:auto-top-up')->assertSuccessful();
        $this->artisan('sms-wallets:auto-top-up')->assertSuccessful();

        $payment = $wallet->topUpPayments()->sole();
        $this->assertSame(19_920, $payment->amount_cents);
        $this->assertSame(SmsTopUpPaymentStatus::PaymentPending, $payment->status);
        $this->assertSame('2026-09-01', $wallet->refresh()->auto_top_up_monthly_period->toDateString());
        $this->assertSame(0, $wallet->auto_top_up_monthly_spent_cents);
        $this->assertNull($wallet->auto_top_up_suspended_at);
        $this->assertNull($wallet->last_auto_top_up_failure_warning_at);
        $this->assertSame(896, $wallet->balance_cents);
        $this->assertSame(816, $wallet->reserved_cents);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['amount'] === 19_920 && $request['initiationKind'] === 'merchant');
    }

    public function test_a_studio_west_of_utc_waits_until_its_own_month_begins(): void
    {
        $wallet = $this->automaticWallet(timezone: 'America/New_York');
        $this->travelTo(Carbon::parse('2026-09-01 00:00:00', 'UTC'));
        $this->artisan('sms-wallets:auto-top-up')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame('2026-08-01', $wallet->refresh()->auto_top_up_monthly_period->toDateString());

        $this->travelTo(Carbon::parse('2026-09-01 04:00:00', 'UTC'));
        $this->artisan('sms-wallets:auto-top-up')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame('2026-09-01', $wallet->refresh()->auto_top_up_monthly_period->toDateString());
    }

    public function test_manual_top_up_does_not_reset_monthly_spend_or_permanently_suspend_the_wallet(): void
    {
        $wallet = $this->automaticWallet(['balance_cents' => 144, 'reserved_cents' => 408]);
        app(SmsAutoTopUpService::class)->attempt($wallet->account);
        $manual = SmsTopUpPayment::factory()->for($wallet->account)->for($wallet, 'wallet')->create([
            'kind' => SmsTopUpKind::Manual,
            'status' => SmsTopUpPaymentStatus::PaymentPaid,
            'amount_cents' => 10_000,
        ]);

        app(SmsWalletService::class)->creditTopUp($manual);
        app(SmsAutoTopUpService::class)->attempt($wallet->account);

        $this->assertSame(10_144, $wallet->refresh()->balance_cents);
        $this->assertSame(46_960, $wallet->auto_top_up_monthly_spent_cents);
        $this->assertNull($wallet->auto_top_up_suspended_at);
        $this->assertSame(1, $wallet->topUpPayments()->count());
        $this->assertSame(1, $wallet->ledgerEntries()->count());
        Http::assertNothingSent();
    }

    public function test_a_real_decline_after_monthly_recovery_sends_a_new_notice_and_stays_suspended(): void
    {
        $wallet = $this->automaticWallet();
        app(SmsAutoTopUpService::class)->attempt($wallet->account);
        $this->travelTo(Carbon::parse('2026-08-31 21:00:00', 'UTC'));
        $this->gatewayResponse = [
            'invoiceId' => 'declined-recovery-invoice',
            'status' => 'failure',
            'amount' => 19_920,
            'ccy' => 980,
            'failureReason' => 'insufficient_funds',
        ];

        $this->artisan('sms-wallets:auto-top-up')->assertSuccessful();
        $suspendedAt = $wallet->refresh()->auto_top_up_suspended_at;
        $this->assertNotNull($suspendedAt);
        $this->assertSame(SmsTopUpPaymentStatus::PaymentFailed, $wallet->topUpPayments()->sole()->status);
        $notices = EmailDelivery::whereBelongsTo($wallet->account)->orderBy('id')->get();
        $this->assertCount(2, $notices);
        $this->assertSame('insufficient_funds', $notices->last()->payload['reason']);
        $this->assertSame('app.mail_subject_sms_auto_top_up_failed', $notices->last()->subject_key);

        $this->travelTo(Carbon::parse('2026-10-01 00:00:00', 'UTC'));
        $this->artisan('sms-wallets:auto-top-up')->assertSuccessful();
        $this->assertNull(app(SmsAutoTopUpService::class)->attempt($wallet->account));
        $this->assertTrue($suspendedAt->equalTo($wallet->refresh()->auto_top_up_suspended_at));
        Http::assertSentCount(1);
        Mail::assertQueuedCount(2);
    }

    public function test_previously_notified_capped_wallets_do_not_hide_later_wallets_from_a_limited_batch(): void
    {
        $capped = $this->automaticWallet(['last_auto_top_up_failure_warning_at' => now()]);
        $eligible = $this->automaticWallet(['auto_top_up_monthly_spent_cents' => 0]);

        Exceptions::fake();
        $exitCode = $this->artisan('sms-wallets:auto-top-up --limit=1')->run();
        Exceptions::assertNothingReported();
        $this->assertSame(0, $exitCode);

        $this->assertSame(0, $capped->topUpPayments()->count());
        $this->assertSame(1, $eligible->topUpPayments()->count());
        Http::assertSentCount(1);
    }

    public function test_same_month_allowance_recovery_does_not_suppress_a_new_card_failure_notice(): void
    {
        $wallet = $this->automaticWallet([
            'balance_cents' => 9_900,
            'reserved_cents' => 300,
            'auto_top_up_monthly_spent_cents' => 39_700,
        ]);
        app(SmsAutoTopUpService::class)->attempt($wallet->account);
        $this->assertNotNull($wallet->refresh()->last_auto_top_up_failure_warning_at);
        Http::assertNothingSent();

        $wallet->update(['reserved_cents' => 0]);
        $this->gatewayResponse = [
            'invoiceId' => 'same-month-decline',
            'status' => 'failure',
            'amount' => 10_100,
            'failureReason' => 'insufficient_funds',
        ];
        app(SmsAutoTopUpService::class)->attempt($wallet->account);

        $notices = EmailDelivery::whereBelongsTo($wallet->account)->orderBy('id')->get();
        $this->assertCount(2, $notices);
        $this->assertSame('insufficient_funds', $notices->last()->payload['reason']);
        $this->assertNotNull($wallet->refresh()->auto_top_up_suspended_at);
        $this->assertSame(39_700, $wallet->auto_top_up_monthly_spent_cents);
        Http::assertSentCount(1);
    }

    public function test_a_full_top_up_equal_to_the_remaining_allowance_is_allowed(): void
    {
        $wallet = $this->automaticWallet(['auto_top_up_monthly_spent_cents' => 30_080]);

        $this->artisan('sms-wallets:auto-top-up')->assertSuccessful();

        $this->assertSame(19_920, $wallet->topUpPayments()->sole()->amount_cents);
        Http::assertSentCount(1);
    }

    #[DataProvider('locales')]
    public function test_the_owner_page_and_email_explain_the_monthly_limit_without_blaming_the_card(string $locale): void
    {
        app()->setLocale($locale);
        $wallet = $this->automaticWallet();
        $account = $wallet->account;
        $account->update(['default_language' => $locale]);
        $owner = $account->users()->firstOrFail();
        app(SmsAutoTopUpService::class)->attempt($account);

        $this->actingAs($owner)->get(route('dashboard.accounts.sms-account.show', $account))
            ->assertOk()
            ->assertSee(__('app.sms_auto_top_up_monthly_cap_warning'))
            ->assertDontSee(__('app.sms_auto_top_up_suspended_warning'));
        Mail::assertQueued(TransactionalMail::class, function (TransactionalMail $mail) use ($locale): bool {
            $html = $mail->locale($locale)->render();
            $this->assertStringContainsString(e(__('app.sms_auto_top_up_monthly_cap_warning')), $html);
            $this->assertStringNotContainsString('monthly_cap_exceeded', $html);
            $this->assertStringNotContainsString(__('app.mail_sms_account_notice_sms_auto_top_up_failed', ['studio' => $mail->data['account_name']]), $html);

            return true;
        });

        $wallet->update(['auto_top_up_suspended_at' => now()]);
        $this->actingAs($owner)->get(route('dashboard.accounts.sms-account.show', $account))
            ->assertOk()
            ->assertSee(__('app.sms_auto_top_up_suspended_warning'))
            ->assertDontSee(__('app.sms_auto_top_up_monthly_cap_warning'));
    }

    public function test_the_page_does_not_show_last_months_limit_before_the_scheduler_runs(): void
    {
        $wallet = $this->automaticWallet();
        $this->travelTo(Carbon::parse('2026-08-31 21:00:00', 'UTC'));

        $this->actingAs($wallet->account->users()->firstOrFail())
            ->get(route('dashboard.accounts.sms-account.show', $wallet->account))
            ->assertOk()
            ->assertDontSee(__('app.sms_auto_top_up_monthly_cap_warning'));
        $this->assertSame('2026-08-01', $wallet->refresh()->auto_top_up_monthly_period->toDateString());
        Http::assertNothingSent();
    }

    /**
     * @return array<int, array{string}>
     */
    public static function locales(): array
    {
        return [['en'], ['uk']];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function automaticWallet(array $attributes = [], string $timezone = 'Europe/Kyiv'): AccountSmsWallet
    {
        $account = Account::factory()->create(['timezone' => $timezone, 'default_language' => 'en']);
        $account->addOwner(User::factory()->create());
        $subscription = AccountSubscription::factory()->for($account)->for(
            SubscriptionPlan::factory()->create(['sms_segment_price_cents' => 136]),
            'plan',
        )->create();
        AccountSubscriptionPaymentMethod::factory()->for($account)->for($subscription, 'subscription')->create([
            'status' => SubscriptionPaymentMethodStatus::Active,
            'provider_card_token' => 'test-card-token',
            'verified_at' => now()->subMonth(),
        ]);
        $account->customerAuthSetting()->create(['sms_sending_mode' => SmsSendingMode::LadnaService]);

        return AccountSmsWallet::factory()->for($account)->create([
            'balance_cents' => 896,
            'reserved_cents' => 816,
            'auto_top_up_enabled' => true,
            'auto_top_up_threshold_cents' => 10_000,
            'auto_top_up_target_cents' => 20_000,
            'auto_top_up_monthly_cap_cents' => 50_000,
            'auto_top_up_monthly_spent_cents' => 46_960,
            'auto_top_up_monthly_period' => '2026-08-01',
            ...$attributes,
        ]);
    }
}
