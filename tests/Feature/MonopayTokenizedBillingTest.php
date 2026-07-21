<?php

namespace Tests\Feature;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\SubscriptionBillingInterval;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceVersion;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\SaasBilling\ChargeAccountSubscription;
use App\Support\SaasBilling\ChargeSubscriptionAfterVerification;
use App\Support\SaasBilling\CompletePaymentMethodVerification;
use App\Support\SaasBilling\CreateBillingV2Payment;
use App\Support\SaasBilling\StartAccountTrial;
use App\Support\SaasBilling\StartPaymentMethodVerification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MonopayTokenizedBillingTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_zero_value_verification_saves_only_encrypted_provider_identifiers_and_creates_no_payment(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'verify-invoice-1',
                'pageUrl' => 'https://pay.example/verify-invoice-1',
            ]),
        ]);
        $setting = $this->monopaySetting();
        $subscription = $this->trialSubscription();

        $checkout = app(StartPaymentMethodVerification::class)->execute(
            $subscription,
            SubscriptionBillingInterval::Annual,
            $setting,
            'https://ladna.local/return',
        );

        $this->assertSame('https://pay.example/verify-invoice-1', $checkout->url);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/invoice/create'
            && $request['amount'] === 0
            && $request['paymentType'] === 'verification'
            && $request['saveCardData']['saveCard'] === true
            && is_string($request['saveCardData']['walletId']));

        $paymentMethod = $subscription->paymentMethod()->firstOrFail();
        $walletId = $paymentMethod->provider_wallet_id;
        $this->assertSame(SubscriptionPaymentMethodStatus::PendingVerification, $paymentMethod->status);
        $this->assertDatabaseCount('account_subscription_payments', 0);
        $this->assertNotSame($walletId, DB::table('account_subscription_payment_methods')->where('id', $paymentMethod->id)->value('provider_wallet_id'));

        $handled = app(CompletePaymentMethodVerification::class)->execute(new PaymentCallbackResult(
            orderId: $paymentMethod->verification_reference,
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: 0,
            currency: 'UAH',
            gatewayInvoiceId: 'verify-invoice-1',
            payload: [
                'walletData' => [
                    'walletId' => $walletId,
                    'cardToken' => 'secret-card-token',
                    'status' => 'created',
                ],
                'paymentInfo' => [
                    'maskedPan' => '444403******1902',
                    'paymentSystem' => 'visa',
                ],
            ],
        ));

        $paymentMethod->refresh();
        $this->assertTrue($handled);
        $this->assertTrue($paymentMethod->isActive());
        $this->assertSame('secret-card-token', $paymentMethod->provider_card_token);
        $this->assertSame('444403******1902', $paymentMethod->masked_pan);
        $this->assertNotSame('secret-card-token', DB::table('account_subscription_payment_methods')->where('id', $paymentMethod->id)->value('provider_card_token'));
        $this->assertDatabaseCount('account_subscription_payments', 0);
    }

    public function test_repeated_verification_start_does_not_create_a_second_mono_invoice(): void
    {
        config()->set('ladna.saas_billing_v2_enabled', true);
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'verify-invoice-once',
                'pageUrl' => 'https://pay.example/verify-invoice-once',
            ]),
        ]);
        $setting = $this->monopaySetting();
        $subscription = $this->trialSubscription();

        app(StartPaymentMethodVerification::class)->execute(
            $subscription,
            SubscriptionBillingInterval::Monthly,
            $setting,
            'https://ladna.local/return',
        );

        try {
            app(StartPaymentMethodVerification::class)->execute(
                $subscription->refresh(),
                SubscriptionBillingInterval::Monthly,
                $setting,
                'https://ladna.local/return',
            );
            $this->fail('A pending verification must not create a second Mono invoice.');
        } catch (\LogicException $exception) {
            $this->assertSame('Card verification is already in progress.', $exception->getMessage());
        }

        $this->assertDatabaseCount('account_subscription_payment_methods', 1);
        $this->assertDatabaseCount('account_subscription_payments', 0);
        Http::assertSentCount(1);
    }

    public function test_verification_accepts_card_display_metadata_inside_mono_wallet_data(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'verify-wallet-metadata-invoice',
                'pageUrl' => 'https://pay.example/verify-wallet-metadata-invoice',
            ]),
        ]);
        $setting = $this->monopaySetting();
        $subscription = $this->trialSubscription();

        app(StartPaymentMethodVerification::class)->execute(
            $subscription,
            SubscriptionBillingInterval::Monthly,
            $setting,
            'https://ladna.local/return',
        );

        $paymentMethod = $subscription->paymentMethod()->firstOrFail();
        $handled = app(CompletePaymentMethodVerification::class)->execute(new PaymentCallbackResult(
            orderId: $paymentMethod->verification_reference,
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: 0,
            currency: 'UAH',
            gatewayInvoiceId: 'verify-wallet-metadata-invoice',
            payload: [
                'walletData' => [
                    'walletId' => $paymentMethod->provider_wallet_id,
                    'cardToken' => 'wallet-metadata-card-token',
                    'status' => 'created',
                    'maskedPan' => '44411114******55',
                    'paymentSystem' => 'visa',
                ],
            ],
        ));

        $paymentMethod->refresh();
        $this->assertTrue($handled);
        $this->assertTrue($paymentMethod->isActive());
        $this->assertSame('44411114******55', $paymentMethod->masked_pan);
        $this->assertSame('visa', $paymentMethod->card_brand);
        $this->assertNotSame('wallet-metadata-card-token', DB::table('account_subscription_payment_methods')->where('id', $paymentMethod->id)->value('provider_card_token'));
        $this->assertDatabaseCount('account_subscription_payments', 0);
        $this->assertDatabaseCount('fiscal_receipts', 0);
    }

    public function test_token_charge_uses_explicit_snapshot_amount_and_callback_activation_is_idempotent(): void
    {
        Mail::fake();
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => Http::response([
                'invoiceId' => 'charge-invoice-1',
                'paymentId' => 'charge-payment-1',
                'status' => 'success',
                'finalAmount' => 90_000,
                'ccy' => 980,
                'modifiedDate' => now()->toIso8601String(),
            ]),
        ]);
        $setting = $this->monopaySetting();
        $subscription = $this->trialSubscription();
        $subscription->forceFill([
            'status' => SubscriptionStatus::Expired,
            'billing_interval_v2' => SubscriptionBillingInterval::Monthly,
            'ends_at' => now()->subDay(),
        ])->save();
        $paymentMethod = $subscription->paymentMethod()->create([
            'account_id' => $subscription->account_id,
            'provider' => 'monopay',
            'provider_wallet_id' => 'wallet-1',
            'provider_card_token' => 'token-1',
            'masked_pan' => '444403******1902',
            'status' => SubscriptionPaymentMethodStatus::Active,
            'verification_reference' => 'verify-reference-1',
            'verified_at' => now(),
        ]);
        $payment = app(CreateBillingV2Payment::class)->execute(
            $subscription->refresh(),
            AccountSubscriptionPaymentType::FullSubscription,
            renewalAttempt: 1,
        );

        app(ChargeAccountSubscription::class)->execute($payment, $setting, 'https://ladna.local/return', true);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/wallet/payment'
            && $request['cardToken'] === 'token-1'
            && $request['amount'] === 90_000
            && $request['initiationKind'] === 'client'
            && $request['merchantPaymInfo']['reference'] === $payment->order_id);
        $payment->refresh();
        $subscription->refresh();
        $this->assertSame(AccountSubscriptionPaymentStatus::PaymentPaid, $payment->status);
        $this->assertSame('[REDACTED]', $payment->gateway_checkout_payload['request']['cardToken']);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(90_000, $payment->amount_cents);
        $this->assertTrue($subscription->ends_at->equalTo($payment->period_ends_at));
        $this->assertTrue($paymentMethod->refresh()->isActive());
    }

    public function test_expired_trial_is_charged_immediately_after_card_verification(): void
    {
        Mail::fake();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://api.monobank.ua/api/merchant/invoice/create') {
                return Http::response([
                    'invoiceId' => 'verify-expired-invoice',
                    'pageUrl' => 'https://pay.example/verify-expired',
                ]);
            }

            return Http::response([
                'invoiceId' => 'charge-expired-invoice',
                'paymentId' => 'charge-expired-payment',
                'status' => 'success',
                'finalAmount' => 90_000,
                'ccy' => 980,
                'modifiedDate' => now()->toIso8601String(),
            ]);
        });
        $setting = $this->monopaySetting();
        $subscription = $this->trialSubscription();
        $subscription->forceFill([
            'status' => SubscriptionStatus::Expired,
            'trial_ends_at' => now()->subDay(),
            'ends_at' => now()->subDay(),
        ])->save();
        app(StartPaymentMethodVerification::class)->execute(
            $subscription,
            SubscriptionBillingInterval::Monthly,
            $setting,
            'https://ladna.local/return',
        );
        $paymentMethod = $subscription->paymentMethod()->firstOrFail();
        $callback = new PaymentCallbackResult(
            orderId: $paymentMethod->verification_reference,
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: 0,
            currency: 'UAH',
            gatewayInvoiceId: 'verify-expired-invoice',
            payload: [
                'walletData' => [
                    'walletId' => $paymentMethod->provider_wallet_id,
                    'cardToken' => 'expired-card-token',
                ],
                'paymentInfo' => ['maskedPan' => '444403******1902'],
            ],
        );

        app(CompletePaymentMethodVerification::class)->execute($callback);
        $payment = app(ChargeSubscriptionAfterVerification::class)->execute($callback, $setting);

        $this->assertNotNull($payment);
        $this->assertSame(AccountSubscriptionPaymentType::FullSubscription, $payment->payment_type);
        $this->assertSame(AccountSubscriptionPaymentStatus::PaymentPaid, $payment->status);
        $this->assertTrue($payment->period_starts_at->equalTo(now()->startOfSecond()));
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/wallet/payment'
            && $request['amount'] === 90_000
            && $request['initiationKind'] === 'client');
    }

    public function test_three_ds_required_renewal_stops_automatic_retries_and_keeps_grace_access(): void
    {
        Mail::fake();
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => Http::response([
                'invoiceId' => 'renewal-3ds-invoice',
                'status' => 'failure',
                'finalAmount' => 90_000,
                'ccy' => 980,
                'failureReason' => '3ds_required',
            ]),
        ]);
        $setting = $this->monopaySetting();
        $subscription = $this->trialSubscription();
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'billing_interval_v2' => SubscriptionBillingInterval::Monthly,
            'billable_location_count' => 1,
            'started_at' => now()->subMonth(),
            'ends_at' => now(),
            'next_payment_at' => now(),
            'auto_renew_enabled' => true,
        ])->save();
        $subscription->paymentMethod()->create([
            'account_id' => $subscription->account_id,
            'provider' => 'monopay',
            'provider_wallet_id' => '3ds-wallet',
            'provider_card_token' => '3ds-token',
            'status' => SubscriptionPaymentMethodStatus::Active,
            'verification_reference' => '3ds-verification',
            'verified_at' => now(),
        ]);
        $payment = app(CreateBillingV2Payment::class)->execute(
            $subscription->refresh(),
            AccountSubscriptionPaymentType::AutoRenewal,
            renewalAttempt: 1,
        );

        app(ChargeAccountSubscription::class)->execute($payment, $setting, 'https://ladna.local/return');

        $this->assertSame(AccountSubscriptionPaymentStatus::PaymentFailed, $payment->refresh()->status);
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status);
        $this->assertSame('owner_interaction_required', $subscription->provider_status);
        $this->assertNull($subscription->next_retry_at);
        $this->assertTrue($subscription->grace_ends_at->isFuture());
    }

    public function test_repeated_initial_charge_reuses_the_in_flight_payment_and_calls_mono_once(): void
    {
        config()->set('ladna.saas_billing_v2_enabled', true);
        Carbon::setTestNow('2026-07-21 10:00:00');
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => Http::response([
                'invoiceId' => 'charge-processing-invoice',
                'status' => 'processing',
                'pageUrl' => 'https://pay.example/charge-processing-invoice',
            ]),
        ]);
        $setting = $this->monopaySetting();
        $subscription = $this->trialSubscription();
        $subscription->forceFill([
            'status' => SubscriptionStatus::Expired,
            'billing_interval_v2' => SubscriptionBillingInterval::Monthly,
            'trial_ends_at' => now()->subDay(),
            'ends_at' => now()->subDay(),
        ])->save();
        $subscription->paymentMethod()->create([
            'account_id' => $subscription->account_id,
            'provider' => 'monopay',
            'provider_wallet_id' => 'deduplicated-wallet',
            'provider_card_token' => 'deduplicated-token',
            'status' => SubscriptionPaymentMethodStatus::Active,
            'verification_reference' => 'deduplicated-verification',
            'verified_at' => now(),
        ]);

        $firstPayment = app(CreateBillingV2Payment::class)->execute(
            $subscription->refresh(),
            AccountSubscriptionPaymentType::FullSubscription,
        );
        app(ChargeAccountSubscription::class)->execute(
            $firstPayment,
            $setting,
            'https://ladna.local/return',
            true,
        );

        Carbon::setTestNow(now()->addSeconds(2));
        $repeatedPayment = app(CreateBillingV2Payment::class)->execute(
            $subscription->refresh(),
            AccountSubscriptionPaymentType::FullSubscription,
        );
        app(ChargeAccountSubscription::class)->execute(
            $repeatedPayment,
            $setting,
            'https://ladna.local/return',
            true,
        );

        $this->assertTrue($firstPayment->is($repeatedPayment));
        $this->assertSame(AccountSubscriptionPaymentStatus::PaymentPending, $firstPayment->refresh()->status);
        $this->assertDatabaseCount('account_subscription_payments', 1);
        Http::assertSentCount(1);
    }

    private function trialSubscription(): AccountSubscription
    {
        $plan = SubscriptionPlan::factory()->create(['name' => 'Ladna']);
        $priceVersion = SubscriptionPriceVersion::factory()->for($plan, 'plan')->published()->create(['version' => 1]);
        $account = Account::factory()->create();
        Location::factory()->for($account)->create(['is_active' => true]);

        return app(StartAccountTrial::class)->execute($account, $priceVersion);
    }

    private function monopaySetting(): IntegrationSetting
    {
        return IntegrationSetting::factory()->create([
            'is_enabled' => true,
            'credentials' => [
                'api_token' => 'test-token',
                'invoice_validity_seconds' => 3600,
            ],
        ]);
    }
}
