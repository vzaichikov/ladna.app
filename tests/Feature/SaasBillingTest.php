<?php

namespace Tests\Feature;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Enums\SubscriptionPlanType;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSignupRequest;
use App\Models\AccountSubscriptionPayment;
use App\Models\IntegrationSetting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Payments\PaymentAmounts;
use App\Support\SaasBilling\AccountSubscriptionAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SaasBillingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_demo_signup_starts_one_uah_monopay_payment_without_creating_account(): void
    {
        $this->platformMonopayIntegration(['api_token' => 'mono-token']);
        $demoPlan = $this->upsertPlan('demo-month', [
            'name' => 'Demo test',
            'price_cents' => 100,
            'currency' => 'UAH',
            'plan_type' => SubscriptionPlanType::Demo,
            'access_days' => 30,
            'public_signup_enabled' => true,
            'requires_recurring_payment' => false,
            'sort_order' => 0,
        ]);

        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'pageUrl' => 'https://pay.example/demo',
                'invoiceId' => 'invoice-demo-1',
                'status' => 'created',
            ]),
        ]);

        $this->post(route('demo.signup.store'), [
            'studio_name' => 'Studio One',
            'account_slug' => 'studio-one-demo',
            'owner_name' => 'Oksana Studio',
            'owner_email' => 'owner-demo@example.com',
            'owner_phone' => '+380501111111',
            'owner_password' => 'secret123',
            'owner_password_confirmation' => 'secret123',
        ])->assertRedirect('https://pay.example/demo');

        $signup = AccountSignupRequest::firstOrFail();
        $payment = AccountSubscriptionPayment::firstOrFail();

        $this->assertSame($demoPlan->id, $signup->subscription_plan_id);
        $this->assertSame('studio-one-demo', $signup->account_slug);
        $this->assertSame(100, $signup->amount_cents);
        $this->assertSame('invoice-demo-1', $signup->gateway_invoice_id);
        $this->assertSame($signup->order_id, $payment->order_id);
        $this->assertSame('payment_started', $payment->status->value);
        $this->assertFalse(Account::where('slug', 'studio-one-demo')->exists());
    }

    public function test_signed_monopay_demo_callback_creates_account_owner_and_trial_subscription(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00'));
        [$privateKey, $publicKeyBase64] = $this->ecdsaKeys();
        $this->platformMonopayIntegration([
            'api_token' => 'mono-token',
            'webhook_public_key' => $publicKeyBase64,
        ]);
        $demoPlan = SubscriptionPlan::factory()->create([
            'name' => 'Demo callback',
            'price_cents' => 100,
            'currency' => 'UAH',
            'plan_type' => SubscriptionPlanType::Demo,
            'access_days' => 30,
            'renewal_lead_days' => 2,
        ]);
        $signup = AccountSignupRequest::factory()->for($demoPlan, 'plan')->create([
            'status' => 'payment_started',
            'studio_name' => 'Callback Studio',
            'account_slug' => 'callback-studio',
            'owner_email' => 'callback-owner@example.com',
            'amount_cents' => 100,
        ]);
        AccountSubscriptionPayment::factory()->for($demoPlan, 'plan')->for($signup, 'signupRequest')->create([
            'payment_type' => AccountSubscriptionPaymentType::DemoInitial,
            'order_id' => $signup->order_id,
            'amount_cents' => 100,
            'currency' => 'UAH',
        ]);

        $body = (string) json_encode([
            'invoiceId' => 'invoice-demo-callback',
            'status' => 'success',
            'amount' => 100,
            'finalAmount' => 100,
            'ccy' => PaymentAmounts::iso4217NumericCode('UAH'),
            'reference' => $signup->order_id,
            'modifiedDate' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);
        openssl_sign($body, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $this->call(
            'POST',
            route('api.v1.saas.payments.callbacks', IntegrationProvider::Monopay->value),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGN' => base64_encode($signature)],
            $body,
        )->assertOk();

        $account = Account::where('slug', 'callback-studio')->firstOrFail();
        $subscription = $account->subscription()->firstOrFail();

        $this->assertSame('payment_paid', AccountSubscriptionPayment::firstOrFail()->status->value);
        $this->assertSame('account_created', $signup->fresh()->status->value);
        $this->assertSame('callback-owner@example.com', User::where('email', 'callback-owner@example.com')->firstOrFail()->email);
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertTrue($subscription->ends_at->equalTo(Carbon::parse('2026-07-26 12:00:00')));
        $this->assertTrue($subscription->next_payment_at->equalTo(Carbon::parse('2026-07-24 12:00:00')));

        $files = Storage::disk('local')->allFiles('payment-callbacks/saas/accounts/unknown/monopay/'.$signup->order_id);
        $this->assertNotEmpty($files);
        Carbon::setTestNow();
    }

    public function test_expired_subscription_blocks_public_content_and_studio_mutations_but_keeps_owner_billing_visible(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['slug' => 'expired-studio']);
        $account->addOwner($owner);
        $plan = SubscriptionPlan::factory()->create(['plan_type' => SubscriptionPlanType::Standard]);
        $account->subscription()->create([
            'subscription_plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'started_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);

        $this->get(route('public.price', [$account->slug, 'main-studio']))
            ->assertStatus(402)
            ->assertSee(__('app.subscription_expired_public_title'));

        $this->getJson("/api/v1/public/{$account->slug}/main-studio/price")
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_expired');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertOk()
            ->assertSee(__('app.subscription_expired_readonly'));

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.show', $account))
            ->post(route('dashboard.accounts.locations.store', $account), [
                'name' => 'Blocked location',
            ])
            ->assertRedirect(route('dashboard.accounts.show', $account))
            ->assertSessionHasErrors('subscription');
    }

    public function test_promo_plan_requires_no_owner_payment(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $promoPlan = SubscriptionPlan::factory()->create([
            'name' => 'Gifted access',
            'price_cents' => 0,
            'plan_type' => SubscriptionPlanType::Promo,
            'requires_recurring_payment' => false,
        ]);
        $account->subscription()->create([
            'subscription_plan_id' => $promoPlan->id,
            'status' => SubscriptionStatus::Active,
            'started_at' => now(),
            'ends_at' => null,
        ]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.tariff-payments.pay-now', $account))
            ->assertRedirect(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertSessionHas('status', __('app.subscription_promo_no_payment_required'));

        $this->assertSame(0, AccountSubscriptionPayment::whereBelongsTo($account)->count());
    }

    public function test_charmpole_account_one_can_receive_promo_access_without_payment_attempt(): void
    {
        $account = Account::query()->find(1);

        if (! $account) {
            $this->markTestSkipped('Local Charmpole account #1 is not available.');
        }

        $owner = $account->users()->wherePivot('role', 'owner')->first() ?? User::factory()->create();
        $account->addOwner($owner);
        $paymentsBefore = AccountSubscriptionPayment::whereBelongsTo($account)->count();
        $promoPlan = $this->upsertPlan('promo-access', [
            'name' => 'Gifted promo access',
            'price_cents' => 0,
            'plan_type' => SubscriptionPlanType::Promo,
            'access_days' => null,
            'requires_recurring_payment' => false,
            'public_signup_enabled' => false,
        ]);

        $account->subscription()->updateOrCreate(
            ['account_id' => $account->id],
            [
                'subscription_plan_id' => $promoPlan->id,
                'status' => SubscriptionStatus::Active,
                'started_at' => now(),
                'ends_at' => null,
                'next_payment_at' => null,
                'auto_renew_enabled' => false,
            ],
        );

        $this->assertTrue(app(AccountSubscriptionAccess::class)->canEditStudio($account->fresh('subscription.plan')));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertOk()
            ->assertSee(__('app.subscription_plan_type_promo'))
            ->assertSee(__('app.subscription_promo_copy'));

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.tariff-payments.pay-now', $account))
            ->assertRedirect(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertSessionHas('status', __('app.subscription_promo_no_payment_required'));

        $this->assertSame($paymentsBefore, AccountSubscriptionPayment::whereBelongsTo($account)->count());
    }

    public function test_demo_purchase_owner_login_expiry_and_standard_subscription_flow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 09:00:00'));
        [$privateKey, $publicKeyBase64] = $this->ecdsaKeys();
        $this->platformMonopayIntegration([
            'api_token' => 'mono-token',
            'webhook_public_key' => $publicKeyBase64,
        ]);
        $demoPlan = $this->upsertPlan('demo-month', [
            'name' => 'Demo month',
            'price_cents' => 100,
            'currency' => 'UAH',
            'plan_type' => SubscriptionPlanType::Demo,
            'access_days' => 30,
            'renewal_lead_days' => 2,
            'public_signup_enabled' => true,
            'requires_recurring_payment' => false,
            'sort_order' => 0,
        ]);
        $standardPlan = $this->upsertPlan('standard-monthly', [
            'name' => 'Standard monthly',
            'price_cents' => 99900,
            'currency' => 'UAH',
            'plan_type' => SubscriptionPlanType::Standard,
            'access_days' => 30,
            'renewal_lead_days' => 2,
            'public_signup_enabled' => false,
            'requires_recurring_payment' => true,
            'sort_order' => 10,
        ]);
        $subscriptionCreateCalls = 0;

        Http::preventStrayRequests();
        Http::fake(function (HttpClientRequest $request) use (&$subscriptionCreateCalls) {
            if ($request->url() === 'https://api.monobank.ua/api/merchant/invoice/create') {
                return Http::response([
                    'pageUrl' => 'https://pay.example/demo-flow',
                    'invoiceId' => 'invoice-demo-flow',
                    'status' => 'created',
                ]);
            }

            if ($request->url() === 'https://api.monobank.ua/api/merchant/subscription/create') {
                $subscriptionCreateCalls++;

                return Http::response([
                    'pageUrl' => 'https://pay.example/standard-flow-'.$subscriptionCreateCalls,
                    'subscriptionId' => 'sub-standard-flow-'.$subscriptionCreateCalls,
                    'status' => 'created',
                ]);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->post(route('demo.signup.store'), [
            'studio_name' => 'Flow Studio',
            'account_slug' => 'flow-studio',
            'owner_name' => 'Flow Owner',
            'owner_email' => 'flow-owner@example.com',
            'owner_phone' => '+380501234567',
            'owner_password' => 'flow-secret',
            'owner_password_confirmation' => 'flow-secret',
        ])->assertRedirect('https://pay.example/demo-flow');

        Http::assertSent(fn (HttpClientRequest $request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/invoice/create'
            && $request['amount'] === 100
            && $request['ccy'] === PaymentAmounts::iso4217NumericCode('UAH')
            && ($request['merchantPaymInfo']['reference'] ?? null) === AccountSignupRequest::firstOrFail()->order_id);

        $signup = AccountSignupRequest::firstOrFail();

        $this->assertSame($demoPlan->id, $signup->subscription_plan_id);
        $this->assertFalse(Account::where('slug', 'flow-studio')->exists());

        $this->postSignedMonopayCallback($privateKey, [
            'invoiceId' => 'invoice-demo-flow',
            'paymentId' => 'payment-demo-flow',
            'status' => 'success',
            'amount' => 100,
            'finalAmount' => 100,
            'ccy' => PaymentAmounts::iso4217NumericCode('UAH'),
            'reference' => $signup->order_id,
            'modifiedDate' => now()->toIso8601String(),
        ])->assertOk();

        $account = Account::where('slug', 'flow-studio')->firstOrFail();
        $owner = User::where('email', 'flow-owner@example.com')->firstOrFail();
        $subscription = $account->subscription()->firstOrFail();

        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame($demoPlan->id, $subscription->subscription_plan_id);
        $this->assertTrue($subscription->ends_at->equalTo(Carbon::parse('2026-07-26 09:00:00')));

        $this->post(route('login'), [
            'email' => 'flow-owner@example.com',
            'password' => 'flow-secret',
        ])->assertRedirect(route('dashboard.index', absolute: false));
        $this->assertAuthenticatedAs($owner);

        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->subMinute(),
        ])->save();

        $this->artisan('billing:reconcile')->assertSuccessful();
        $this->assertSame(SubscriptionStatus::Expired, $subscription->fresh()->status);

        $this->get(route('customer.studio.login', $account->slug))
            ->assertStatus(402)
            ->assertSee(__('app.subscription_expired_public_title'));

        $this->get(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertOk()
            ->assertSee(__('app.subscription_expired_readonly'));

        $this->from(route('dashboard.accounts.show', $account))
            ->post(route('dashboard.accounts.locations.store', $account), [
                'name' => 'Blocked after expiry',
            ])
            ->assertRedirect(route('dashboard.accounts.show', $account))
            ->assertSessionHasErrors('subscription');

        $this->post(route('dashboard.accounts.tariff-payments.pay-now', $account))
            ->assertRedirect('https://pay.example/standard-flow-1');

        $standardPayment = AccountSubscriptionPayment::whereBelongsTo($account)
            ->where('subscription_plan_id', $standardPlan->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(AccountSubscriptionPaymentType::FullSubscription, $standardPayment->payment_type);
        $this->assertSame('sub-standard-flow-1', $standardPayment->gateway_subscription_id);
        $this->assertSame(99900, $standardPayment->amount_cents);

        $this->postSignedMonopayCallback($privateKey, [
            'subscriptionId' => 'sub-standard-flow-1',
            'paymentId' => 'payment-standard-flow',
            'status' => 'success',
            'amount' => 99900,
            'finalAmount' => 99900,
            'ccy' => PaymentAmounts::iso4217NumericCode('UAH'),
            'modifiedDate' => now()->toIso8601String(),
        ])->assertOk();

        $subscription = $account->subscription()->firstOrFail();

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame($standardPlan->id, $subscription->subscription_plan_id);
        $this->assertSame('sub-standard-flow-1', $subscription->provider_subscription_id);
        $this->assertTrue($subscription->auto_renew_enabled);
        $this->assertTrue($subscription->ends_at->equalTo(Carbon::parse('2026-07-26 09:00:00')));
        $this->assertTrue($subscription->next_payment_at->equalTo(Carbon::parse('2026-07-24 09:00:00')));
        $this->assertSame(2, AccountSubscriptionPayment::whereBelongsTo($account)->where('status', AccountSubscriptionPaymentStatus::PaymentPaid)->count());

        $this->get(route('customer.studio.login', $account->slug))
            ->assertOk();
    }

    public function test_successful_auto_renew_callback_creates_payment_history_and_extends_subscription(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-24 08:00:00'));
        [$privateKey, $publicKeyBase64] = $this->ecdsaKeys();
        $this->platformMonopayIntegration([
            'api_token' => 'mono-token',
            'webhook_public_key' => $publicKeyBase64,
        ]);
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $plan = SubscriptionPlan::factory()->create([
            'price_cents' => 99900,
            'currency' => 'UAH',
            'plan_type' => SubscriptionPlanType::Standard,
            'access_days' => 30,
            'renewal_lead_days' => 2,
            'requires_recurring_payment' => true,
        ]);
        $subscription = $account->subscription()->create([
            'subscription_plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'started_at' => now()->subDays(28),
            'ends_at' => Carbon::parse('2026-07-26 08:00:00'),
            'next_payment_at' => now(),
            'payment_provider' => IntegrationProvider::Monopay->value,
            'provider_subscription_id' => 'sub-auto-success',
            'auto_renew_enabled' => true,
        ]);

        $this->postSignedMonopayCallback($privateKey, [
            'subscriptionId' => 'sub-auto-success',
            'paymentId' => 'payment-auto-success',
            'status' => 'success',
            'amount' => 99900,
            'finalAmount' => 99900,
            'ccy' => PaymentAmounts::iso4217NumericCode('UAH'),
            'modifiedDate' => now()->toIso8601String(),
        ])->assertOk();

        $payment = AccountSubscriptionPayment::whereBelongsTo($account)->firstOrFail();
        $subscription = $subscription->fresh();

        $this->assertSame(AccountSubscriptionPaymentType::AutoRenewal, $payment->payment_type);
        $this->assertSame(AccountSubscriptionPaymentStatus::PaymentPaid, $payment->status);
        $this->assertSame('payment-auto-success', $payment->gateway_payment_id);
        $this->assertTrue($payment->period_starts_at->equalTo(Carbon::parse('2026-07-26 08:00:00')));
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($subscription->ends_at->equalTo(Carbon::parse('2026-08-25 08:00:00')));
        $this->assertTrue($subscription->next_payment_at->equalTo(Carbon::parse('2026-08-23 08:00:00')));
        $this->assertTrue($subscription->auto_renew_enabled);
    }

    public function test_failed_auto_renew_reconcile_marks_past_due_and_retry_subscription_payment_recovers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-24 08:00:00'));
        [$privateKey, $publicKeyBase64] = $this->ecdsaKeys();
        $this->platformMonopayIntegration([
            'api_token' => 'mono-token',
            'webhook_public_key' => $publicKeyBase64,
        ]);
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $plan = $this->upsertPlan('standard-monthly', [
            'name' => 'Standard monthly',
            'price_cents' => 99900,
            'currency' => 'UAH',
            'plan_type' => SubscriptionPlanType::Standard,
            'access_days' => 30,
            'renewal_lead_days' => 2,
            'public_signup_enabled' => false,
            'requires_recurring_payment' => true,
            'sort_order' => 10,
        ]);
        $subscription = $account->subscription()->create([
            'subscription_plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'started_at' => now()->subDays(28),
            'ends_at' => Carbon::parse('2026-07-26 08:00:00'),
            'next_payment_at' => now()->subHour(),
            'payment_provider' => IntegrationProvider::Monopay->value,
            'provider_subscription_id' => 'sub-auto-failure',
            'auto_renew_enabled' => true,
        ]);
        $subscriptionCreateCalls = 0;

        Http::preventStrayRequests();
        Http::fake(function (HttpClientRequest $request) use (&$subscriptionCreateCalls) {
            if (str_starts_with($request->url(), 'https://api.monobank.ua/api/merchant/subscription/status')) {
                return Http::response(['status' => 'failure']);
            }

            if ($request->url() === 'https://api.monobank.ua/api/merchant/subscription/create') {
                $subscriptionCreateCalls++;

                return Http::response([
                    'pageUrl' => 'https://pay.example/retry-standard-'.$subscriptionCreateCalls,
                    'subscriptionId' => 'sub-retry-standard-'.$subscriptionCreateCalls,
                    'status' => 'created',
                ]);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->artisan('billing:reconcile')
            ->assertSuccessful()
            ->expectsOutputToContain('Checked auto-renew subscriptions: 1')
            ->expectsOutputToContain('Marked past due: 1');

        $subscription = $subscription->fresh();
        $failedPayment = AccountSubscriptionPayment::whereBelongsTo($account)->firstOrFail();

        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertFalse($subscription->auto_renew_enabled);
        $this->assertSame('failure', $subscription->provider_status);
        $this->assertSame(AccountSubscriptionPaymentType::AutoRenewal, $failedPayment->payment_type);
        $this->assertSame(AccountSubscriptionPaymentStatus::PaymentFailed, $failedPayment->status);
        $this->assertSame('sub-auto-failure', $failedPayment->gateway_subscription_id);
        $this->assertTrue(app(AccountSubscriptionAccess::class)->canEditStudio($account->fresh('subscription.plan')));
        $this->assertTrue(app(AccountSubscriptionAccess::class)->shouldShowWarning($account));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertOk()
            ->assertSee(__('app.past_due'))
            ->assertSee(__('app.subscription_past_due_warning'));

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.tariff-payments.pay-now', $account))
            ->assertRedirect('https://pay.example/retry-standard-1');

        $retryPayment = AccountSubscriptionPayment::whereBelongsTo($account)
            ->where('status', AccountSubscriptionPaymentStatus::PaymentStarted)
            ->latest('id')
            ->firstOrFail();

        $this->assertNotSame($failedPayment->order_id, $retryPayment->order_id);
        $this->assertSame(AccountSubscriptionPaymentType::FullSubscription, $retryPayment->payment_type);
        $this->assertSame('sub-retry-standard-1', $retryPayment->gateway_subscription_id);

        $this->postSignedMonopayCallback($privateKey, [
            'subscriptionId' => 'sub-retry-standard-1',
            'paymentId' => 'payment-retry-standard',
            'status' => 'success',
            'amount' => 99900,
            'finalAmount' => 99900,
            'ccy' => PaymentAmounts::iso4217NumericCode('UAH'),
            'modifiedDate' => now()->toIso8601String(),
        ])->assertOk();

        $subscription = $subscription->fresh();

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($subscription->auto_renew_enabled);
        $this->assertSame('sub-retry-standard-1', $subscription->provider_subscription_id);
        $this->assertTrue($subscription->ends_at->equalTo(Carbon::parse('2026-08-25 08:00:00')));
        $this->assertSame(AccountSubscriptionPaymentStatus::PaymentPaid, $retryPayment->fresh()->status);
        $this->assertSame(2, AccountSubscriptionPayment::whereBelongsTo($account)->count());
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function platformMonopayIntegration(array $credentials): IntegrationSetting
    {
        return IntegrationSetting::create([
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
            'provider' => IntegrationProvider::Monopay->value,
            'category' => IntegrationCategory::Payment->value,
            'is_enabled' => true,
            'credentials' => $credentials,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertPlan(string $slug, array $attributes): SubscriptionPlan
    {
        return SubscriptionPlan::query()->updateOrCreate(
            ['slug' => $slug],
            ['billing_interval' => 'monthly', 'is_active' => true] + $attributes,
        );
    }

    /**
     * @return array{0: mixed, 1: string}
     */
    private function ecdsaKeys(): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        openssl_pkey_export($privateKey, $privatePem);
        $details = openssl_pkey_get_details($privateKey);
        $publicKeyBase64 = base64_encode((string) ($details['key'] ?? ''));

        return [$privatePem, $publicKeyBase64];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSignedMonopayCallback(mixed $privateKey, array $payload): TestResponse
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        openssl_sign($body, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return $this->call(
            'POST',
            route('api.v1.saas.payments.callbacks', IntegrationProvider::Monopay->value),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGN' => base64_encode($signature)],
            $body,
        );
    }
}
