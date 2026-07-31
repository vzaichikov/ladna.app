<?php

namespace Tests\Feature;

use App\Enums\AccountMode;
use App\Enums\AccountPaymentMethodVerificationPurpose;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Enums\SmsSendingMode;
use App\Enums\SmsTopUpKind;
use App\Enums\SmsTopUpPaymentStatus;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Models\Account;
use App\Models\AccountSmsWallet;
use App\Models\AccountSubscription;
use App\Models\IntegrationSetting;
use App\Models\SmsTopUpPayment;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\SaasBilling\CompletePaymentMethodVerification;
use App\Support\Sms\CompleteSmsTopUpPayment;
use App\Support\Sms\CreateSmsTopUpPayment;
use App\Support\Sms\ResolveSmsTopUpPayment;
use App\Support\Sms\ResumeSmsPaymentAfterVerification;
use App\Support\Sms\SmsAutoTopUpService;
use App\Support\Sms\StartSmsPaymentMethodVerification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SmsTopUpPaymentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_successful_callback_credits_exactly_once_and_reversal_creates_debt(): void
    {
        $wallet = AccountSmsWallet::factory()->create();
        $payment = SmsTopUpPayment::factory()->create([
            'account_id' => $wallet->account_id,
            'account_sms_wallet_id' => $wallet->id,
            'amount_cents' => 5_000,
            'gateway_invoice_id' => 'invoice-1',
        ]);
        $paid = new PaymentCallbackResult(
            orderId: 'invoice-1',
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: 5_000,
            currency: 'UAH',
            gatewayInvoiceId: 'invoice-1',
        );
        $complete = app(CompleteSmsTopUpPayment::class);

        $complete->execute($payment, $paid);
        $complete->execute($payment->refresh(), $paid);

        $this->assertSame(SmsTopUpPaymentStatus::PaymentPaid, $payment->refresh()->status);
        $this->assertSame(5_000, $wallet->refresh()->balance_cents);
        $this->assertSame(1, $wallet->ledgerEntries()->count());

        $wallet->forceFill(['balance_cents' => 1_000])->save();
        $complete->execute($payment->refresh(), new PaymentCallbackResult(
            orderId: 'invoice-1',
            status: PaymentCallbackStatus::Cancelled,
            gatewayStatus: 'reversed',
            amountCents: 5_000,
            currency: 'UAH',
            gatewayInvoiceId: 'invoice-1',
        ));

        $this->assertSame(SmsTopUpPaymentStatus::PaymentReversed, $payment->refresh()->status);
        $this->assertSame(0, $wallet->refresh()->balance_cents);
        $this->assertSame(4_000, $wallet->outstanding_cents);
        $this->assertSame(2, $wallet->ledgerEntries()->count());
    }

    public function test_sms_card_verification_snapshots_the_intent_and_amount(): void
    {
        $account = Account::factory()->create();
        AccountSubscription::factory()->for($account)->create();
        $setting = $this->platformMonopaySetting();
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'verification-invoice',
                'pageUrl' => 'https://pay.example/verify',
            ]),
        ]);

        $checkout = app(StartSmsPaymentMethodVerification::class)->execute(
            account: $account,
            topUpAmountCents: 10_000,
            setting: $setting,
            redirectUrl: route('dashboard.accounts.sms-account.show', $account),
        );
        $paymentMethod = $account->subscription->paymentMethod()->firstOrFail();

        $this->assertSame('https://pay.example/verify', $checkout->url);
        $this->assertSame(
            AccountPaymentMethodVerificationPurpose::SmsTopUp,
            $paymentMethod->verification_purpose,
        );
        $this->assertSame(10_000, $paymentMethod->verification_amount_cents);
        $this->assertSame('verification-invoice', $paymentMethod->verification_invoice_id);

        Http::assertSent(fn ($request): bool => $request['amount'] === 0
            && $request['paymentType'] === 'verification'
            && $request['saveCardData']['saveCard'] === true);
    }

    public function test_verified_sms_intent_resumes_one_top_up_without_duplicate_charge(): void
    {
        $account = Account::factory()->create();
        $subscription = AccountSubscription::factory()->for($account)->create();
        $paymentMethod = $subscription->paymentMethod()->create([
            'account_id' => $account->id,
            'provider' => IntegrationProvider::Monopay->value,
            'provider_wallet_id' => 'sms-wallet',
            'provider_card_token' => 'sms-card-token',
            'masked_pan' => '444403******1902',
            'status' => SubscriptionPaymentMethodStatus::Active->value,
            'verification_reference' => 'SMS-VERIFY-RESUME',
            'verification_invoice_id' => 'verify-invoice',
            'verification_purpose' => AccountPaymentMethodVerificationPurpose::SmsTopUp->value,
            'verification_amount_cents' => 5_000,
            'verified_at' => now(),
        ]);
        $setting = $this->platformMonopaySetting();
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => Http::response([
                'invoiceId' => 'top-up-invoice',
                'paymentId' => 'top-up-payment',
                'status' => 'success',
                'finalAmount' => 5_000,
                'ccy' => 980,
            ]),
        ]);
        $callback = new PaymentCallbackResult(
            orderId: $paymentMethod->verification_reference,
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: 0,
            currency: 'UAH',
            gatewayInvoiceId: $paymentMethod->verification_invoice_id,
        );
        $resume = app(ResumeSmsPaymentAfterVerification::class);

        $first = $resume->execute($callback, $setting);
        $second = $resume->execute($callback, $setting);

        $this->assertInstanceOf(SmsTopUpPayment::class, $first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(SmsTopUpPaymentStatus::PaymentPaid, $first->refresh()->status);
        $this->assertSame(5_000, $account->smsWallet()->firstOrFail()->balance_cents);
        $this->assertSame(1, SmsTopUpPayment::whereBelongsTo($account)->count());
        Http::assertSentCount(1);
    }

    public function test_auto_top_up_restores_the_target_and_counts_only_successful_automatic_topups(): void
    {
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $subscription = AccountSubscription::factory()->for($account)->create();
        $subscription->paymentMethod()->create([
            'account_id' => $account->id,
            'provider' => IntegrationProvider::Monopay->value,
            'provider_wallet_id' => 'auto-wallet',
            'provider_card_token' => 'auto-card-token',
            'masked_pan' => '444403******1902',
            'status' => SubscriptionPaymentMethodStatus::Active->value,
            'verification_reference' => 'AUTO-VERIFY',
            'verified_at' => now(),
        ]);
        $account->customerAuthSetting()->create([
            'sms_sending_mode' => SmsSendingMode::LadnaService->value,
        ]);
        $wallet = AccountSmsWallet::factory()->for($account)->create([
            'balance_cents' => 100,
            'auto_top_up_enabled' => true,
            'auto_top_up_threshold_cents' => 500,
            'auto_top_up_target_cents' => 1_000,
            'auto_top_up_monthly_cap_cents' => 2_000,
        ]);
        $this->platformMonopaySetting();
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => Http::response([
                'invoiceId' => 'auto-top-up-invoice',
                'status' => 'processing',
            ]),
        ]);

        $payment = app(SmsAutoTopUpService::class)->attempt($account);
        $samePayment = app(SmsAutoTopUpService::class)->attempt($account);

        $this->assertNotNull($payment);
        $this->assertSame($payment?->id, $samePayment?->id);
        $this->assertSame(900, $payment?->amount_cents);
        $this->assertSame(SmsTopUpKind::Automatic, $payment?->kind);
        $this->assertSame(1, SmsTopUpPayment::whereBelongsTo($account)->count());

        app(CompleteSmsTopUpPayment::class)->execute($payment, new PaymentCallbackResult(
            orderId: 'auto-top-up-invoice',
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: 900,
            currency: 'UAH',
            gatewayInvoiceId: 'auto-top-up-invoice',
        ));

        $this->assertSame(1_000, $wallet->refresh()->balance_cents);
        $this->assertSame(900, $wallet->auto_top_up_monthly_spent_cents);

        $manual = app(CreateSmsTopUpPayment::class)->execute(
            account: $account,
            amountCents: 5_000,
            kind: SmsTopUpKind::Manual,
            idempotencyKey: 'manual-does-not-use-auto-cap',
        );
        app(CompleteSmsTopUpPayment::class)->execute($manual, new PaymentCallbackResult(
            orderId: $manual->order_id,
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: 5_000,
            currency: 'UAH',
        ));

        $this->assertSame(900, $wallet->refresh()->auto_top_up_monthly_spent_cents);
    }

    public function test_auto_top_up_never_partially_charges_when_monthly_cap_is_insufficient(): void
    {
        $account = Account::factory()->create();
        $account->customerAuthSetting()->create([
            'sms_sending_mode' => SmsSendingMode::LadnaService->value,
        ]);
        $wallet = AccountSmsWallet::factory()->for($account)->create([
            'balance_cents' => 100,
            'auto_top_up_enabled' => true,
            'auto_top_up_threshold_cents' => 500,
            'auto_top_up_target_cents' => 1_000,
            'auto_top_up_monthly_cap_cents' => 800,
        ]);

        $payment = app(SmsAutoTopUpService::class)->attempt($account);

        $this->assertNull($payment);
        $this->assertNotNull($wallet->refresh()->auto_top_up_suspended_at);
        $this->assertDatabaseCount('sms_top_up_payments', 0);
    }

    public function test_read_only_demo_cannot_resolve_a_top_up_callback(): void
    {
        $account = Account::factory()->create(['mode' => AccountMode::DemoReadonly->value]);
        $wallet = AccountSmsWallet::factory()->for($account)->create();
        $payment = SmsTopUpPayment::factory()->for($account)->for($wallet, 'wallet')->create([
            'gateway_invoice_id' => 'demo-top-up-invoice',
        ]);

        try {
            app(ResolveSmsTopUpPayment::class)->execute(
                IntegrationProvider::Monopay->value,
                new PaymentCallbackResult(
                    orderId: $payment->order_id,
                    status: PaymentCallbackStatus::Paid,
                    gatewayStatus: 'success',
                    amountCents: $payment->amount_cents,
                    currency: 'UAH',
                    gatewayInvoiceId: $payment->gateway_invoice_id,
                ),
            );
            $this->fail('Expected a read-only demo callback to be blocked.');
        } catch (HttpException $exception) {
            $this->assertSame(Response::HTTP_LOCKED, $exception->getStatusCode());
        }
    }

    public function test_successful_card_update_recovers_a_suspended_auto_top_up_episode(): void
    {
        $account = Account::factory()->create();
        $subscription = AccountSubscription::factory()->for($account)->create();
        $wallet = AccountSmsWallet::factory()->for($account)->create([
            'auto_top_up_enabled' => true,
            'auto_top_up_suspended_at' => now(),
            'last_auto_top_up_failure_warning_at' => now(),
        ]);
        $paymentMethod = $subscription->paymentMethod()->create([
            'account_id' => $account->id,
            'provider' => IntegrationProvider::Monopay->value,
            'provider_wallet_id' => 'replacement-wallet',
            'status' => SubscriptionPaymentMethodStatus::PendingVerification->value,
            'verification_reference' => 'SMS-VERIFY-REPLACEMENT',
            'verification_purpose' => AccountPaymentMethodVerificationPurpose::SmsTopUp->value,
            'verification_amount_cents' => 5_000,
        ]);

        app(CompletePaymentMethodVerification::class)->execute(new PaymentCallbackResult(
            orderId: $paymentMethod->verification_reference,
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: 0,
            currency: 'UAH',
            payload: [
                'walletData' => [
                    'walletId' => 'replacement-wallet',
                    'cardToken' => 'replacement-token',
                ],
                'paymentInfo' => [
                    'maskedPan' => '444403******1902',
                    'paymentSystem' => 'visa',
                ],
            ],
        ));

        $this->assertNull($wallet->refresh()->auto_top_up_suspended_at);
        $this->assertNull($wallet->last_auto_top_up_failure_warning_at);
        $this->assertTrue($paymentMethod->refresh()->isActive());
    }

    private function platformMonopaySetting(): IntegrationSetting
    {
        return IntegrationSetting::create([
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
            'provider' => IntegrationProvider::Monopay->value,
            'category' => IntegrationCategory::Payment->value,
            'is_enabled' => true,
            'credentials' => ['api_token' => 'mono-token'],
        ]);
    }
}
