<?php

namespace Tests\Feature;

use App\Enums\AccountPaymentMethodVerificationPurpose;
use App\Enums\AccountRole;
use App\Enums\IntegrationProvider;
use App\Enums\SubscriptionBillingInterval;
use App\Enums\SubscriptionBillingMode;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSmsWallet;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPaymentMethod;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\Payments\PaymentGatewayException;
use App\Support\SaasBilling\CompletePaymentMethodVerification;
use App\Support\SaasBilling\ReplaceAccountPaymentMethod;
use App\Support\Sms\ResumeSmsPaymentAfterVerification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountPaymentMethodChangeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_replace_the_shared_card_from_sms_without_triggering_a_payment(): void
    {
        [$account, $owner, $subscription, $paymentMethod, $wallet] = $this->activePaymentMethodAccount(
            SubscriptionStatus::PastDue,
        );
        $setting = $this->monopaySetting();
        $oldWalletId = $paymentMethod->provider_wallet_id;
        $oldReference = $paymentMethod->verification_reference;
        $oldInvoiceId = $paymentMethod->verification_invoice_id;
        $nextPaymentAt = $subscription->next_payment_at;
        $endsAt = $subscription->ends_at;

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->method() === 'DELETE') {
                return Http::response(null, 204);
            }

            return Http::response([
                'invoiceId' => 'replacement-verification-invoice',
                'pageUrl' => 'https://pay.example/replacement-verification-invoice',
            ]);
        });

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payment-method.change', $account), [
                'return_to' => 'sms_account',
            ])
            ->assertRedirect('https://pay.example/replacement-verification-invoice');

        $paymentMethod->refresh();
        $subscription->refresh();
        $wallet->refresh();

        $this->assertSame(SubscriptionPaymentMethodStatus::PendingVerification, $paymentMethod->status);
        $this->assertSame(AccountPaymentMethodVerificationPurpose::PaymentMethodChange, $paymentMethod->verification_purpose);
        $this->assertSame('replacement-verification-invoice', $paymentMethod->verification_invoice_id);
        $this->assertNotSame($oldWalletId, $paymentMethod->provider_wallet_id);
        $this->assertNotSame($oldReference, $paymentMethod->verification_reference);
        $this->assertNull($paymentMethod->provider_card_token);
        $this->assertNull($paymentMethod->masked_pan);
        $this->assertNull($paymentMethod->card_brand);
        $this->assertNotNull($paymentMethod->revoked_at);
        $this->assertNull($subscription->next_retry_at);
        $this->assertTrue($subscription->next_payment_at->equalTo($nextPaymentAt));
        $this->assertTrue($subscription->ends_at->equalTo($endsAt));
        $this->assertSame(SubscriptionBillingInterval::Monthly, $subscription->billing_interval_v2);
        $this->assertTrue($subscription->auto_renew_enabled);
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertTrue($wallet->auto_top_up_enabled);
        $this->assertSame(2_500, $wallet->auto_top_up_threshold_cents);
        $this->assertSame(10_000, $wallet->auto_top_up_target_cents);
        $this->assertSame(30_000, $wallet->auto_top_up_monthly_cap_cents);
        $this->assertNotNull($wallet->auto_top_up_suspended_at);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), 'cardToken=old-card-token'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['amount'] === 0
            && $request['paymentType'] === 'verification'
            && $request['saveCardData']['walletId'] === $paymentMethod->provider_wallet_id
            && $request['redirectUrl'] === route('dashboard.accounts.sms-account.show', $account));

        $oldCallbackHandled = app(CompletePaymentMethodVerification::class)->execute(new PaymentCallbackResult(
            orderId: $oldReference,
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: 0,
            currency: 'UAH',
            gatewayInvoiceId: $oldInvoiceId,
            payload: [
                'walletData' => [
                    'walletId' => $oldWalletId,
                    'cardToken' => 'old-card-token-from-late-callback',
                ],
            ],
        ));

        $this->assertFalse($oldCallbackHandled);
        $this->assertSame(SubscriptionPaymentMethodStatus::PendingVerification, $paymentMethod->refresh()->status);

        $callback = new PaymentCallbackResult(
            orderId: $paymentMethod->verification_reference,
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: 0,
            currency: 'UAH',
            gatewayInvoiceId: 'replacement-verification-invoice',
            payload: [
                'walletData' => [
                    'walletId' => $paymentMethod->provider_wallet_id,
                    'cardToken' => 'new-card-token',
                ],
                'paymentInfo' => [
                    'maskedPan' => '555555******4444',
                    'paymentSystem' => 'mastercard',
                ],
            ],
        );

        $this->assertTrue(app(CompletePaymentMethodVerification::class)->execute($callback));
        $this->assertNull(app(ResumeSmsPaymentAfterVerification::class)->execute($callback, $setting));

        $paymentMethod->refresh();
        $this->assertTrue($paymentMethod->isActive());
        $this->assertSame('new-card-token', $paymentMethod->provider_card_token);
        $this->assertSame('555555******4444', $paymentMethod->masked_pan);
        $this->assertNull($paymentMethod->revoked_at);
        $this->assertNull($wallet->refresh()->auto_top_up_suspended_at);
        $this->assertSame(0, $account->subscriptionPayments()->count());
        $this->assertSame(0, $account->smsTopUpPayments()->count());
        $this->assertSame(0, DB::table('fiscal_receipts')->where('account_id', $account->id)->count());
    }

    #[DataProvider('idempotentRevocationResponses')]
    public function test_missing_provider_token_is_an_idempotent_replacement_success(int $status, array $body): void
    {
        [$account, , , $paymentMethod] = $this->activePaymentMethodAccount();
        $setting = $this->monopaySetting();

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($status, $body) {
            if ($request->method() === 'DELETE') {
                return Http::response($body, $status);
            }

            return Http::response([
                'invoiceId' => 'idempotent-replacement-invoice',
                'pageUrl' => 'https://pay.example/idempotent-replacement',
            ]);
        });

        $checkout = app(ReplaceAccountPaymentMethod::class)->execute(
            $account,
            $setting,
            route('dashboard.accounts.tariff-payments.show', $account),
        );

        $this->assertSame('https://pay.example/idempotent-replacement', $checkout->url);
        $this->assertSame(SubscriptionPaymentMethodStatus::PendingVerification, $paymentMethod->refresh()->status);
        $this->assertNull($paymentMethod->provider_card_token);
        Http::assertSentCount(2);
    }

    public static function idempotentRevocationResponses(): array
    {
        return [
            '404 missing card' => [404, ['errCode' => 'NOT_FOUND']],
            'Mono TOKEN_NOT_FOUND' => [400, ['errCode' => 'TOKEN_NOT_FOUND']],
        ];
    }

    #[DataProvider('unsafeRevocationResponses')]
    public function test_other_provider_revocation_error_preserves_the_current_local_card(
        int $status,
        array $body,
        int $expectedRequests,
    ): void {
        [$account, $owner, $subscription, $paymentMethod, $wallet] = $this->activePaymentMethodAccount(
            SubscriptionStatus::PastDue,
        );
        $this->monopaySetting();
        $oldWalletId = $paymentMethod->provider_wallet_id;
        $oldReference = $paymentMethod->verification_reference;
        $oldNextRetryAt = $subscription->next_retry_at;

        Http::preventStrayRequests();
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/card*' => Http::response($body, $status),
        ]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payment-method.change', $account), [
                'return_to' => 'tariff_payments',
            ])
            ->assertSessionHasErrors('payment_method');

        $paymentMethod->refresh();
        $this->assertTrue($paymentMethod->isActive());
        $this->assertSame('old-card-token', $paymentMethod->provider_card_token);
        $this->assertSame('444403******1902', $paymentMethod->masked_pan);
        $this->assertSame($oldWalletId, $paymentMethod->provider_wallet_id);
        $this->assertSame($oldReference, $paymentMethod->verification_reference);
        $this->assertTrue($subscription->refresh()->next_retry_at->equalTo($oldNextRetryAt));
        $this->assertNull($wallet->refresh()->auto_top_up_suspended_at);
        Http::assertSentCount($expectedRequests);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public static function unsafeRevocationResponses(): array
    {
        return [
            'unrelated 400' => [400, ['errCode' => 'INVALID_REQUEST'], 1],
            'forbidden 403' => [403, ['errCode' => 'FORBIDDEN'], 1],
            'server error 500' => [500, ['errCode' => 'INTERNAL_ERROR'], 3],
        ];
    }

    public function test_verification_creation_failure_leaves_a_safe_relinkable_state(): void
    {
        [$account, , , $paymentMethod, $wallet] = $this->activePaymentMethodAccount();
        $setting = $this->monopaySetting();
        $oldReference = $paymentMethod->verification_reference;

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->method() === 'DELETE') {
                return Http::response(null, 204);
            }

            return Http::response(['errCode' => 'TEMPORARY_FAILURE'], 500);
        });

        try {
            app(ReplaceAccountPaymentMethod::class)->execute(
                $account,
                $setting,
                route('dashboard.accounts.sms-account.show', $account),
            );
            $this->fail('A failed verification invoice must be reported.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame('Monopay card verification creation failed.', $exception->getMessage());
        }

        $paymentMethod->refresh();
        $this->assertSame(SubscriptionPaymentMethodStatus::Failed, $paymentMethod->status);
        $this->assertNotSame($oldReference, $paymentMethod->verification_reference);
        $this->assertNull($paymentMethod->provider_card_token);
        $this->assertNull($paymentMethod->masked_pan);
        $this->assertNotNull($paymentMethod->revoked_at);
        $this->assertNotNull($wallet->refresh()->auto_top_up_suspended_at);
        $this->assertSame(0, $account->subscriptionPayments()->count());
        $this->assertSame(0, $account->smsTopUpPayments()->count());
    }

    public function test_late_verification_creation_failure_does_not_overwrite_a_successful_callback(): void
    {
        [$account, , , $paymentMethod, $wallet] = $this->activePaymentMethodAccount();
        $setting = $this->monopaySetting();

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($paymentMethod) {
            if ($request->method() === 'DELETE') {
                return Http::response(null, 204);
            }

            $paymentMethod->refresh();
            app(CompletePaymentMethodVerification::class)->execute(new PaymentCallbackResult(
                orderId: $paymentMethod->verification_reference,
                status: PaymentCallbackStatus::Paid,
                gatewayStatus: 'success',
                amountCents: 0,
                currency: 'UAH',
                gatewayInvoiceId: 'racing-verification-invoice',
                payload: [
                    'walletData' => [
                        'walletId' => $paymentMethod->provider_wallet_id,
                        'cardToken' => 'racing-card-token',
                    ],
                ],
            ));

            return Http::response(['errCode' => 'TEMPORARY_FAILURE'], 500);
        });

        try {
            app(ReplaceAccountPaymentMethod::class)->execute(
                $account,
                $setting,
                route('dashboard.accounts.sms-account.show', $account),
            );
            $this->fail('A failed verification invoice response must be reported.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame('Monopay card verification creation failed.', $exception->getMessage());
        }

        $paymentMethod->refresh();
        $this->assertTrue($paymentMethod->isActive());
        $this->assertSame('racing-card-token', $paymentMethod->provider_card_token);
        $this->assertNull($wallet->refresh()->auto_top_up_suspended_at);
        $this->assertSame(0, $account->subscriptionPayments()->count());
        $this->assertSame(0, $account->smsTopUpPayments()->count());
    }

    public function test_recent_pending_verification_is_not_restarted_but_a_stale_one_can_be_retried(): void
    {
        [$account, $owner, , $paymentMethod] = $this->activePaymentMethodAccount();
        $this->monopaySetting();
        $paymentMethod->forceFill([
            'provider_card_token' => null,
            'masked_pan' => null,
            'status' => SubscriptionPaymentMethodStatus::PendingVerification,
            'verification_invoice_id' => 'pending-verification-invoice',
            'verification_purpose' => AccountPaymentMethodVerificationPurpose::PaymentMethodChange,
        ])->save();

        Http::preventStrayRequests();
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'retried-verification-invoice',
                'pageUrl' => 'https://pay.example/retried-verification',
            ]),
        ]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payment-method.change', $account), [
                'return_to' => 'sms_account',
            ])
            ->assertSessionHasErrors('payment_method');
        Http::assertNothingSent();

        DB::table('account_subscription_payment_methods')
            ->where('id', $paymentMethod->id)
            ->update(['updated_at' => now()->subHours(2)]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payment-method.change', $account), [
                'return_to' => 'sms_account',
            ])
            ->assertRedirect('https://pay.example/retried-verification');

        $this->assertSame('retried-verification-invoice', $paymentMethod->refresh()->verification_invoice_id);
        Http::assertSentCount(1);
    }

    public function test_only_the_owner_and_allowlisted_return_context_can_start_replacement(): void
    {
        [$account, $owner] = $this->activePaymentMethodAccount();
        $manager = User::factory()->create();
        $account->users()->attach($manager, ['role' => AccountRole::Manager->value]);
        $this->monopaySetting();

        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($manager)
            ->post(route('dashboard.accounts.payment-method.change', $account), [
                'return_to' => 'sms_account',
            ])
            ->assertForbidden();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payment-method.change', $account), [
                'return_to' => 'https://attacker.example',
            ])
            ->assertSessionHasErrors('return_to');

        Http::assertNothingSent();
    }

    public function test_both_owner_payment_interfaces_show_the_shared_control_but_platform_sms_does_not(): void
    {
        [$account, $owner] = $this->activePaymentMethodAccount();
        $admin = User::factory()->platformAdmin()->create();
        $action = route('dashboard.accounts.payment-method.change', $account);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.sms-account.show', $account))
            ->assertOk()
            ->assertSee($action, false)
            ->assertSee(__('app.change_payment_method'))
            ->assertSee(__('app.change_payment_method_confirm_body'));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertOk()
            ->assertSee($action, false)
            ->assertSee(__('app.change_payment_method'))
            ->assertSee(__('app.change_payment_method_confirm_body'));

        $this->actingAs($admin)
            ->get(route('platform.accounts.sms-account.show', $account))
            ->assertOk()
            ->assertDontSee($action, false)
            ->assertDontSee(__('app.change_payment_method'));
    }

    /**
     * @return array{Account, User, AccountSubscription, AccountSubscriptionPaymentMethod, AccountSmsWallet}
     */
    private function activePaymentMethodAccount(SubscriptionStatus $status = SubscriptionStatus::Active): array
    {
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $subscription = AccountSubscription::factory()->for($account)->create([
            'status' => $status,
            'billing_mode' => SubscriptionBillingMode::LocationV2,
            'billing_interval_v2' => SubscriptionBillingInterval::Monthly,
            'auto_renew_enabled' => true,
            'next_payment_at' => now()->addDays(10),
            'next_retry_at' => $status === SubscriptionStatus::PastDue ? now()->addMinutes(30) : null,
            'ends_at' => now()->addMonth(),
            'cancel_at_period_end' => true,
            'cancellation_requested_at' => now()->subDay(),
        ]);
        $paymentMethod = $subscription->paymentMethod()->create([
            'account_id' => $account->id,
            'provider' => IntegrationProvider::Monopay,
            'provider_wallet_id' => 'old-wallet-id',
            'provider_card_token' => 'old-card-token',
            'masked_pan' => '444403******1902',
            'card_brand' => 'visa',
            'status' => SubscriptionPaymentMethodStatus::Active,
            'verification_reference' => 'OLD-VERIFY-REFERENCE',
            'verification_invoice_id' => 'old-verification-invoice',
            'verified_at' => now()->subMonth(),
        ]);
        $wallet = AccountSmsWallet::factory()->for($account)->create([
            'auto_top_up_enabled' => true,
            'auto_top_up_threshold_cents' => 2_500,
            'auto_top_up_target_cents' => 10_000,
            'auto_top_up_monthly_cap_cents' => 30_000,
        ]);

        return [$account, $owner, $subscription, $paymentMethod, $wallet];
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
