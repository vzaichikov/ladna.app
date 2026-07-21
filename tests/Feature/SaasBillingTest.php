<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\AccountSignupStatus;
use App\Enums\AccountStatus;
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
use App\Support\DemoStudioFixture;
use App\Support\Payments\PaymentAmounts;
use App\Support\ReservedPublicSlugs;
use App\Support\SaasBilling\AccountSubscriptionAccess;
use App\Support\SaasBilling\MonopaySaasBilling;
use App\Support\SaasBilling\StartAccountSubscriptionPaymentCheckout;
use App\Support\SlugGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_demo_route_shows_prefilled_read_only_owner_login(): void
    {
        Account::factory()->demoReadonly()->create([
            'slug' => DemoStudioFixture::AccountSlug,
        ]);

        $this->get(route('demo.login'))
            ->assertOk()
            ->assertSee('value="'.config('demo-studio.owner.email').'"', false)
            ->assertSee('value="'.config('demo-studio.owner.password').'"', false)
            ->assertSee('name="remember" type="hidden" value="0"', false)
            ->assertDontSee('name="remember" type="checkbox"', false)
            ->assertSee(__('app.demo_readonly_title'));
    }

    public function test_paid_demo_signup_post_route_is_retired(): void
    {
        $this->post('/demo')->assertStatus(405);
    }

    public function test_landing_links_to_read_only_demo_without_tariff_prices(): void
    {
        Account::factory()->demoReadonly()->create([
            'slug' => DemoStudioFixture::AccountSlug,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('1 ₴')
            ->assertDontSee('999 ₴')
            ->assertSee('data-landing-header-auth', false)
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('href="'.route('demo.login', [], false).'"', false)
            ->assertDontSee('id="pricing"', false);
    }

    public function test_demo_signup_creates_account_owner_and_embedded_one_uah_payment(): void
    {
        $this->platformMonopayIntegration([
            'api_token' => 'mono-token',
            'payment_type' => 'hold',
            'submerchant_code' => 'legacy-submerchant',
        ]);
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
        $this->upsertPlan('standard-monthly', [
            'name' => 'Standard test',
            'price_cents' => 99900,
            'currency' => 'UAH',
            'plan_type' => SubscriptionPlanType::Standard,
            'access_days' => 30,
            'public_signup_enabled' => false,
            'requires_recurring_payment' => true,
            'sort_order' => 10,
        ]);

        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'pageUrl' => 'https://pay.example/demo',
                'invoiceId' => 'invoice-demo-1',
                'status' => 'created',
            ]),
        ]);

        $this->createLegacyDemoSignup([
            'studio_name' => 'Studio One',
            'owner_name' => 'Oksana Studio',
            'owner_email' => 'owner-demo@example.com',
            'owner_phone' => '+380501111111',
            'owner_password' => 'secret123',
            'owner_password_confirmation' => 'secret123',
        ], $demoPlan);

        $account = Account::where('slug', 'studio-one')->firstOrFail();
        $signup = AccountSignupRequest::where('owner_email', 'owner-demo@example.com')->firstOrFail();
        $payment = AccountSubscriptionPayment::whereBelongsTo($account)->firstOrFail();
        $owner = User::where('email', 'owner-demo@example.com')->firstOrFail();
        $subscription = $account->subscription()->firstOrFail();

        $this->assertSame($demoPlan->id, $signup->subscription_plan_id);
        $this->assertSame($account->id, $signup->account_id);
        $this->assertSame('studio-one', $signup->account_slug);
        $this->assertSame(100, $signup->amount_cents);
        $this->assertSame('invoice-demo-1', $signup->gateway_invoice_id);
        $this->assertSame($signup->order_id, $payment->order_id);
        $this->assertSame($account->id, $payment->account_id);
        $this->assertSame($subscription->id, $payment->account_subscription_id);
        $this->assertSame('payment_started', $payment->status->value);
        $this->assertSame(SubscriptionStatus::PendingPayment, $subscription->status);
        $this->assertTrue($account->isOwnedBy($owner));
        $this->assertAuthenticatedAs($owner);

        $this->get(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertOk()
            ->assertSee(__('app.legacy_demo_payment_retired'))
            ->assertDontSee('https://pay.example/demo', false);

        Http::assertSent(fn (HttpClientRequest $request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/invoice/create'
            && $request['paymentType'] === 'debit'
            && $request['displayType'] === 'iframe'
            && ! array_key_exists('code', $request->data()));
    }

    public function test_demo_signup_generates_slug_with_one_suffix_when_base_slug_exists(): void
    {
        $this->platformMonopayIntegration(['api_token' => 'mono-token']);
        Account::factory()->create(['slug' => 'studio-one']);
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
                'pageUrl' => 'https://pay.example/demo-conflict',
                'invoiceId' => 'invoice-demo-conflict',
                'status' => 'created',
            ]),
        ]);

        $this->createLegacyDemoSignup([
            'studio_name' => 'Studio One',
            'owner_name' => 'Oksana Studio',
            'owner_email' => 'owner-demo-conflict@example.com',
            'owner_phone' => '+380501111111',
            'owner_password' => 'secret123',
            'owner_password_confirmation' => 'secret123',
        ], $demoPlan);

        $signup = AccountSignupRequest::where('owner_email', 'owner-demo-conflict@example.com')->firstOrFail();
        $account = Account::where('slug', 'studio-one-1')->firstOrFail();

        $this->assertSame($demoPlan->id, $signup->subscription_plan_id);
        $this->assertSame('studio-one-1', $signup->account_slug);
        $this->assertSame($account->id, $signup->account_id);
    }

    public function test_demo_signup_avoids_reserved_public_slugs(): void
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
                'pageUrl' => 'https://pay.example/demo-reserved',
                'invoiceId' => 'invoice-demo-reserved',
                'status' => 'created',
            ]),
        ]);

        $this->createLegacyDemoSignup([
            'studio_name' => 'App',
            'owner_name' => 'Oksana Studio',
            'owner_email' => 'owner-demo-reserved@example.com',
            'owner_phone' => '+380501111112',
            'owner_password' => 'secret123',
            'owner_password_confirmation' => 'secret123',
        ], $demoPlan);

        $signup = AccountSignupRequest::where('owner_email', 'owner-demo-reserved@example.com')->firstOrFail();
        $account = Account::where('slug', 'app-1')->firstOrFail();

        $this->assertSame($demoPlan->id, $signup->subscription_plan_id);
        $this->assertSame('app-1', $signup->account_slug);
        $this->assertSame($account->id, $signup->account_id);
    }

    public function test_demo_payment_return_keeps_authenticated_owner_inside_tariff_page(): void
    {
        $owner = User::factory()->create(['email' => 'return-owner@example.com']);
        $account = Account::factory()->create(['slug' => 'return-studio']);
        $account->addOwner($owner);
        $demoPlan = SubscriptionPlan::factory()->create([
            'plan_type' => SubscriptionPlanType::Demo,
        ]);
        $signup = AccountSignupRequest::factory()->for($demoPlan, 'plan')->create([
            'account_id' => $account->id,
            'status' => 'payment_started',
            'account_slug' => $account->slug,
            'owner_email' => $owner->email,
        ]);

        $this->actingAs($owner)
            ->get(route('demo.return', $signup))
            ->assertRedirect(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertSessionHas('status', __('app.payment_processing'));
    }

    public function test_signed_monopay_demo_callback_activates_existing_account_trial_subscription(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00'));
        [$privateKey, $publicKeyBase64] = $this->ecdsaKeys();
        $this->platformMonopayIntegration([
            'api_token' => 'mono-token',
        ]);
        Http::fake([
            'https://api.monobank.ua/api/merchant/pubkey' => Http::response(['key' => $publicKeyBase64]),
        ]);
        $demoPlan = SubscriptionPlan::factory()->create([
            'name' => 'Demo callback',
            'price_cents' => 100,
            'currency' => 'UAH',
            'plan_type' => SubscriptionPlanType::Demo,
            'access_days' => 30,
            'renewal_lead_days' => 2,
        ]);
        $owner = User::factory()->create(['email' => 'callback-owner@example.com']);
        $account = Account::factory()->create(['slug' => 'callback-studio']);
        $account->addOwner($owner);
        $subscription = $account->subscription()->create([
            'subscription_plan_id' => $demoPlan->id,
            'status' => SubscriptionStatus::PendingPayment,
            'started_at' => now(),
            'ends_at' => null,
            'payment_provider' => IntegrationProvider::Monopay->value,
        ]);
        $signup = AccountSignupRequest::factory()->for($demoPlan, 'plan')->create([
            'account_id' => $account->id,
            'status' => 'payment_started',
            'studio_name' => 'Callback Studio',
            'account_slug' => 'callback-studio',
            'owner_email' => 'callback-owner@example.com',
            'amount_cents' => 100,
        ]);
        AccountSubscriptionPayment::factory()->for($account)->for($subscription, 'subscription')->for($demoPlan, 'plan')->for($signup, 'signupRequest')->create([
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

        $subscription = $account->subscription()->firstOrFail();

        $this->assertSame('payment_paid', AccountSubscriptionPayment::whereBelongsTo($account)->firstOrFail()->status->value);
        $this->assertSame('account_created', $signup->fresh()->status->value);
        $this->assertSame(1, User::where('email', 'callback-owner@example.com')->count());
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertTrue($subscription->ends_at->equalTo(Carbon::parse('2026-07-26 12:00:00')));
        $this->assertTrue($subscription->next_payment_at->equalTo(Carbon::parse('2026-07-24 12:00:00')));

        $files = Storage::disk('local')->allFiles('payment-callbacks/saas/accounts/'.$account->id.'/monopay/'.$signup->order_id);
        $this->assertNotEmpty($files);
        Carbon::setTestNow();
    }

    public function test_failed_demo_payment_keeps_account_and_allows_retry_from_owner_billing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 13:00:00'));
        [$privateKey, $publicKeyBase64] = $this->ecdsaKeys();
        $this->platformMonopayIntegration([
            'api_token' => 'mono-token',
        ]);
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
        $this->upsertPlan('standard-monthly', [
            'name' => 'Standard test',
            'price_cents' => 99900,
            'currency' => 'UAH',
            'plan_type' => SubscriptionPlanType::Standard,
            'access_days' => 30,
            'public_signup_enabled' => false,
            'requires_recurring_payment' => true,
            'sort_order' => 10,
        ]);
        $invoiceCalls = 0;

        Http::preventStrayRequests();
        Http::fake(function (HttpClientRequest $request) use (&$invoiceCalls, $publicKeyBase64) {
            if ($request->url() === 'https://api.monobank.ua/api/merchant/invoice/create') {
                $invoiceCalls++;

                return Http::response([
                    'pageUrl' => 'https://pay.example/demo-retry-'.$invoiceCalls,
                    'invoiceId' => 'invoice-demo-retry-'.$invoiceCalls,
                    'status' => 'created',
                ]);
            }

            if ($request->url() === 'https://api.monobank.ua/api/merchant/pubkey') {
                return Http::response(['key' => $publicKeyBase64]);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->createLegacyDemoSignup([
            'studio_name' => 'Retry Studio',
            'owner_name' => 'Retry Owner',
            'owner_email' => 'retry-owner@example.com',
            'owner_phone' => '+380501234500',
            'owner_password' => 'retry-secret',
            'owner_password_confirmation' => 'retry-secret',
        ], $demoPlan);

        $signup = AccountSignupRequest::where('owner_email', 'retry-owner@example.com')->firstOrFail();
        $account = Account::where('slug', 'retry-studio')->firstOrFail();
        $owner = User::where('email', 'retry-owner@example.com')->firstOrFail();
        $firstPayment = AccountSubscriptionPayment::whereBelongsTo($account)->firstOrFail();

        $this->postSignedMonopayCallback($privateKey, [
            'invoiceId' => 'invoice-demo-retry-1',
            'paymentId' => 'payment-demo-failed',
            'status' => 'failure',
            'amount' => 100,
            'finalAmount' => 100,
            'ccy' => PaymentAmounts::iso4217NumericCode('UAH'),
            'reference' => $signup->order_id,
            'modifiedDate' => now()->toIso8601String(),
        ])->assertOk();

        $this->assertModelExists($account);
        $this->assertModelExists($owner);
        $this->assertSame(AccountSubscriptionPaymentStatus::PaymentFailed, $firstPayment->fresh()->status);
        $this->assertSame('payment_failed', $signup->fresh()->status->value);
        $this->assertSame(SubscriptionStatus::PendingPayment, $account->subscription()->firstOrFail()->status);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.tariff-payments.pay-now', $account))
            ->assertRedirect(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertSessionHasErrors('billing');

        $this->assertSame(1, AccountSubscriptionPayment::whereBelongsTo($account)->count());
        $this->assertSame(1, $invoiceCalls);

        Carbon::setTestNow();
    }

    public function test_demo_callback_without_created_account_is_rejected(): void
    {
        [$privateKey, $publicKeyBase64] = $this->ecdsaKeys();
        $this->platformMonopayIntegration([
            'api_token' => 'mono-token',
        ]);
        Http::fake([
            'https://api.monobank.ua/api/merchant/pubkey' => Http::response(['key' => $publicKeyBase64]),
        ]);
        $demoPlan = SubscriptionPlan::factory()->create([
            'name' => 'Old invoice demo',
            'price_cents' => 100,
            'currency' => 'UAH',
            'plan_type' => SubscriptionPlanType::Demo,
            'access_days' => 30,
        ]);
        $signup = AccountSignupRequest::factory()->for($demoPlan, 'plan')->create([
            'account_id' => null,
            'status' => 'payment_started',
            'studio_name' => 'Old Invoice Studio',
            'account_slug' => 'old-invoice-studio',
            'owner_email' => 'old-invoice@example.com',
            'amount_cents' => 100,
        ]);
        AccountSubscriptionPayment::factory()->for($demoPlan, 'plan')->for($signup, 'signupRequest')->create([
            'payment_type' => AccountSubscriptionPaymentType::DemoInitial,
            'order_id' => $signup->order_id,
            'amount_cents' => 100,
            'currency' => 'UAH',
        ]);

        $this->postSignedMonopayCallback($privateKey, [
            'invoiceId' => 'invoice-old-demo',
            'status' => 'success',
            'amount' => 100,
            'finalAmount' => 100,
            'ccy' => PaymentAmounts::iso4217NumericCode('UAH'),
            'reference' => $signup->order_id,
            'modifiedDate' => now()->toIso8601String(),
        ])->assertBadRequest();

        $this->assertFalse(Account::where('slug', 'old-invoice-studio')->exists());
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

    public function test_existing_studio_can_receive_promo_access_without_payment_attempt(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $paymentsBefore = AccountSubscriptionPayment::whereBelongsTo($account)->count();
        $this->upsertPlan('standard-monthly', [
            'name' => 'Standard monthly',
            'price_cents' => 99900,
            'currency' => 'UAH',
            'plan_type' => SubscriptionPlanType::Standard,
            'access_days' => 30,
            'requires_recurring_payment' => true,
            'public_signup_enabled' => false,
        ]);
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
            'payment_type' => 'hold',
            'submerchant_code' => 'legacy-submerchant',
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
        Http::fake(function (HttpClientRequest $request) use (&$subscriptionCreateCalls, $publicKeyBase64) {
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

            if ($request->url() === 'https://api.monobank.ua/api/merchant/pubkey') {
                return Http::response(['key' => $publicKeyBase64]);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->createLegacyDemoSignup([
            'studio_name' => 'Flow Studio',
            'owner_name' => 'Flow Owner',
            'owner_email' => 'flow-owner@example.com',
            'owner_phone' => '+380501234567',
            'owner_password' => 'flow-secret',
            'owner_password_confirmation' => 'flow-secret',
        ], $demoPlan);

        $signup = AccountSignupRequest::where('owner_email', 'flow-owner@example.com')->firstOrFail();

        Http::assertSent(fn (HttpClientRequest $request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/invoice/create'
            && $request['amount'] === 100
            && $request['ccy'] === PaymentAmounts::iso4217NumericCode('UAH')
            && $request['paymentType'] === 'debit'
            && $request['displayType'] === 'iframe'
            && ($request['merchantPaymInfo']['reference'] ?? null) === $signup->order_id
            && ! array_key_exists('code', $request->data()));

        $account = Account::where('slug', 'flow-studio')->firstOrFail();
        $owner = User::where('email', 'flow-owner@example.com')->firstOrFail();
        $subscription = $account->subscription()->firstOrFail();

        $this->assertSame($demoPlan->id, $signup->subscription_plan_id);
        $this->assertSame($account->id, $signup->account_id);
        $this->assertSame(SubscriptionStatus::PendingPayment, $subscription->status);
        $this->assertAuthenticatedAs($owner);

        $this->get(route('customer.studio.login', $account->slug))
            ->assertStatus(402)
            ->assertSee(__('app.demo_payment_required_public_title'));

        $this->getJson("/api/v1/public/{$account->slug}/main-studio/price")
            ->assertStatus(402)
            ->assertJsonPath('code', 'demo_payment_required');

        $this->get(route('dashboard.accounts.show', $account))
            ->assertRedirect(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertSessionHasErrors('subscription');

        $this->get(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertOk()
            ->assertSee(__('app.legacy_demo_payment_retired'))
            ->assertDontSee('https://pay.example/demo-flow', false);

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

        $subscription = $account->subscription()->firstOrFail();

        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame($demoPlan->id, $subscription->subscription_plan_id);
        $this->assertTrue($subscription->ends_at->equalTo(Carbon::parse('2026-07-26 09:00:00')));

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
        ]);
        Http::fake([
            'https://api.monobank.ua/api/merchant/pubkey' => Http::response(['key' => $publicKeyBase64]),
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
        Http::fake(function (HttpClientRequest $request) use (&$subscriptionCreateCalls, $publicKeyBase64) {
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

            if ($request->url() === 'https://api.monobank.ua/api/merchant/pubkey') {
                return Http::response(['key' => $publicKeyBase64]);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->artisan('billing:reconcile')
            ->assertSuccessful()
            ->expectsOutputToContain('Checked legacy auto-renew subscriptions: 1')
            ->expectsOutputToContain('Marked legacy past due: 1');

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
     * Creates an old paid-demo account directly so callback and retry compatibility remains tested
     * after the public signup endpoint has been retired.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createLegacyDemoSignup(array $attributes, SubscriptionPlan $plan): void
    {
        [$signup, $payment, $owner] = DB::transaction(function () use ($attributes, $plan): array {
            $slugBase = SlugGenerator::base((string) $attributes['studio_name'], 'studio');
            $slug = $slugBase;
            $suffix = 1;

            while (
                ReservedPublicSlugs::isReserved($slug)
                || AccountSignupRequest::where('account_slug', $slug)->exists()
                || Account::where('slug', $slug)->exists()
            ) {
                $slug = $slugBase.'-'.$suffix;
                $suffix++;
            }

            $orderId = 'LEGACY-DEMO-'.now()->format('YmdHis').'-'.Str::upper(Str::random(10));
            $account = Account::create([
                'name' => $attributes['studio_name'],
                'slug' => $slug,
                'status' => AccountStatus::Active,
                'default_language' => 'uk',
                'country_code' => 'UA',
                'default_currency' => $plan->currency,
                'timezone' => 'Europe/Kyiv',
            ]);
            $account->ensureDefaultTrainerType();
            $owner = User::create([
                'name' => $attributes['owner_name'],
                'email' => $attributes['owner_email'],
                'phone' => $attributes['owner_phone'] ?? null,
                'password' => $attributes['owner_password'],
                'email_verified_at' => now(),
            ]);
            $account->users()->attach($owner, [
                'role' => AccountRole::Owner->value,
                'permissions' => null,
            ]);
            $subscription = $account->subscription()->create([
                'subscription_plan_id' => $plan->id,
                'status' => SubscriptionStatus::PendingPayment,
                'started_at' => now(),
                'payment_provider' => IntegrationProvider::Monopay->value,
                'auto_renew_enabled' => false,
            ]);
            $signup = AccountSignupRequest::create([
                'subscription_plan_id' => $plan->id,
                'account_id' => $account->id,
                'status' => AccountSignupStatus::PaymentStarted,
                'provider' => IntegrationProvider::Monopay->value,
                'order_id' => $orderId,
                'studio_name' => $attributes['studio_name'],
                'account_slug' => $slug,
                'owner_name' => $attributes['owner_name'],
                'owner_email' => $attributes['owner_email'],
                'owner_phone' => $attributes['owner_phone'] ?? null,
                'owner_password' => Hash::make((string) $attributes['owner_password']),
                'default_language' => 'uk',
                'timezone' => 'Europe/Kyiv',
                'amount_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'expires_at' => now()->addHour(),
            ]);
            $payment = AccountSubscriptionPayment::create([
                'account_id' => $account->id,
                'account_subscription_id' => $subscription->id,
                'subscription_plan_id' => $plan->id,
                'account_signup_request_id' => $signup->id,
                'provider' => IntegrationProvider::Monopay->value,
                'payment_type' => AccountSubscriptionPaymentType::DemoInitial,
                'order_id' => $orderId,
                'amount_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'started_at' => now(),
                'expires_at' => now()->addHour(),
            ]);

            return [$signup, $payment, $owner];
        });
        $setting = app(MonopaySaasBilling::class)->platformSetting();

        $this->assertNotNull($setting);

        app(StartAccountSubscriptionPaymentCheckout::class)->execute(
            $payment,
            $setting,
            route('demo.return', $signup),
        );

        Auth::login($owner);
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
