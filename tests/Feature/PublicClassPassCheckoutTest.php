<?php

namespace Tests\Feature;

use App\Enums\AccountMode;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\ScheduledClass;
use App\Support\PublicClassPassCheckoutContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicClassPassCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_stays_on_checkout_for_login_and_returns_for_profile_and_payment(): void
    {
        [$account, $location, $plan] = $this->checkoutContext();
        $checkoutUrl = $this->checkoutUrl($account, $location, $plan);

        $this->get($checkoutUrl)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertSee($plan->name)
            ->assertSee(__('app.class_pass_checkout_login_title'))
            ->assertSee(route('customer.email.login', $account->slug), false)
            ->assertSessionHas('url.intended', $checkoutUrl);

        $this->post(route('customer.email.login', $account->slug), [
            'customer_auth_method' => 'email',
            'email' => 'external-checkout@example.com',
            'password' => 'secret-password',
        ])->assertRedirect($checkoutUrl);

        $this->get($checkoutUrl)
            ->assertOk()
            ->assertSee(__('app.class_pass_checkout_profile_title'))
            ->assertSee(route('customer.profile.update', $account->slug), false);

        $this->put(route('customer.profile.update', $account->slug), [
            'name' => 'External Checkout Client',
            'phone' => '+380501112244',
            'email' => 'external-checkout@example.com',
        ])->assertRedirect($checkoutUrl);

        $this->get($checkoutUrl)
            ->assertOk()
            ->assertSee(__('app.class_pass_checkout_payment_title'));
    }

    public function test_checkout_shows_only_studio_configured_payment_methods_and_uses_one_rules_agreement(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $this->accountIntegration($account, IntegrationProvider::Monopay, ['api_token' => 'mono-token']);
        $this->accountIntegration($account, IntegrationProvider::Liqpay, [
            'public_key' => 'public-key',
            'private_key' => 'private-key',
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->get($this->checkoutUrl($account, $location, $plan))
            ->assertOk()
            ->assertSee(__('app.class_pass_checkout_payment_title'))
            ->assertSee('value="monopay"', false)
            ->assertSee('value="liqpay"', false)
            ->assertDontSee('value="wayforpay"', false)
            ->assertSee(route('public.class-pass-plans.checkout.store', [$account->slug, $location->slug, $plan->slug]), false);

        $this->assertSame(1, substr_count($response->getContent(), 'name="studio_rules_accepted"'));
    }

    public function test_monopay_checkout_uses_a_signed_return_to_the_same_checkout(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $this->accountIntegration($account, IntegrationProvider::Monopay, ['api_token' => 'mono-token']);
        $checkoutUrl = $this->checkoutUrl($account, $location, $plan);
        $returnUrl = null;

        Http::preventStrayRequests();
        Http::fake([
            'api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'external-checkout-invoice',
                'pageUrl' => 'https://pay.monobank.ua/invoice/external-checkout-invoice',
                'status' => 'created',
            ]),
        ]);

        $this->actingAs($customer, 'customer')
            ->get($checkoutUrl)
            ->assertOk();

        $this->post(route('public.class-pass-plans.checkout.store', [$account->slug, $location->slug, $plan->slug]), [
            'studio_rules_accepted' => '1',
            'provider' => IntegrationProvider::Monopay->value,
        ])->assertRedirect('https://pay.monobank.ua/invoice/external-checkout-invoice');

        Http::assertSent(function ($request) use (&$returnUrl): bool {
            $returnUrl = $request->data()['redirectUrl'] ?? null;

            return is_string($returnUrl);
        });

        $this->assertIsString($returnUrl);
        $this->assertTrue(URL::hasValidSignature(Request::create($returnUrl)));
        $this->assertStringContainsString('/checkout/class-passes/'.$plan->slug.'/purchases/', $returnUrl);

        $purchase = $customer->purchases()->sole();
        $this->assertSame($location->id, $purchase->location_id);
        $this->assertSame(CustomerPurchaseStatus::PaymentStarted, $purchase->status);

        $this->post(route('public.class-pass-plans.checkout.store', [$account->slug, $location->slug, $plan->slug]), [
            'studio_rules_accepted' => '1',
            'provider' => IntegrationProvider::Monopay->value,
        ])->assertRedirect($checkoutUrl);

        $this->assertSame(1, $customer->purchases()->count());

        $this->get($checkoutUrl)
            ->assertOk()
            ->assertSee(__('app.class_pass_checkout_confirmation_title'))
            ->assertSee('data-class-pass-checkout-poll', false);
    }

    public function test_free_class_pass_is_completed_without_a_gateway(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext(['price_cents' => 0]);
        $checkoutUrl = $this->checkoutUrl($account, $location, $plan);

        $this->actingAs($customer, 'customer')
            ->get($checkoutUrl)
            ->assertOk()
            ->assertSee(__('app.class_pass_checkout_free_action'));

        $this->post(route('public.class-pass-plans.checkout.store', [$account->slug, $location->slug, $plan->slug]), [
            'studio_rules_accepted' => '1',
        ])->assertRedirect($checkoutUrl);

        $purchase = $customer->purchases()->with('customerClassPass')->sole();
        $this->assertSame(CustomerPurchase::ProviderFree, $purchase->provider);
        $this->assertTrue($purchase->isPaid());
        $this->assertNotNull($purchase->customerClassPass);
        $this->assertSame($location->id, $purchase->customerClassPass->issued_location_id);

        $this->get($checkoutUrl)
            ->assertOk()
            ->assertSee(__('app.class_pass_checkout_status_payment_paid'))
            ->assertSee($purchase->customerClassPass->code);
    }

    public function test_online_trial_checkout_accepts_one_future_booking_without_a_pass_reservation(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext(['is_trial' => true]);
        $class = ScheduledClass::factory()->recycle([$account, $location])->for($account)->for($location)
            ->for($plan->classTypes()->firstOrFail(), 'classType')->create([
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addHour(),
            ]);
        ClassBooking::factory()->for($account)->for($customer)->for($class, 'scheduledClass')->create();
        $this->accountIntegration($account, IntegrationProvider::Monopay, ['api_token' => 'mono-token']);
        Http::preventStrayRequests();
        Http::fake([
            'api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'future-booking-trial-invoice',
                'pageUrl' => 'https://pay.monobank.ua/invoice/future-booking-trial-invoice',
                'status' => 'created',
            ]),
        ]);
        $checkoutUrl = $this->checkoutUrl($account, $location, $plan);
        $this->actingAs($customer, 'customer')->get($checkoutUrl)
            ->assertOk()->assertViewHas('trialIsAvailable', true)->assertDontSee(__('app.trial_class_pass_not_available'));

        $this->post(route('public.class-pass-plans.checkout.store', [$account->slug, $location->slug, $plan->slug]), [
            'studio_rules_accepted' => '1',
            'provider' => IntegrationProvider::Monopay->value,
        ])->assertRedirect('https://pay.monobank.ua/invoice/future-booking-trial-invoice');

        $this->assertNotNull($customer->purchases()->sole()->trial_eligibility_validated_at);
    }

    public function test_ineligible_trial_is_rejected_before_a_purchase_is_created(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext(['is_trial' => true]);
        $pastClass = ScheduledClass::factory()->for($account)->create([
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ]);
        ClassBooking::factory()->create([
            'account_id' => $account->id,
            'customer_id' => $customer->id,
            'scheduled_class_id' => $pastClass->id,
        ]);
        $this->accountIntegration($account, IntegrationProvider::Liqpay, [
            'public_key' => 'public-key',
            'private_key' => 'private-key',
        ]);
        $checkoutUrl = $this->checkoutUrl($account, $location, $plan);

        $this->actingAs($customer, 'customer')
            ->get($checkoutUrl)
            ->assertOk()
            ->assertSee(__('app.trial_class_pass_not_available'));

        $this->from($checkoutUrl)
            ->post(route('public.class-pass-plans.checkout.store', [$account->slug, $location->slug, $plan->slug]), [
                'studio_rules_accepted' => '1',
                'provider' => IntegrationProvider::Liqpay->value,
            ])
            ->assertRedirect($checkoutUrl)
            ->assertSessionHasErrors('class_pass_plan_id');

        $this->assertSame(0, $customer->purchases()->count());
    }

    public function test_status_and_retry_are_scoped_to_the_authenticated_customer(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->purchase($account, $location, $plan, $customer, CustomerPurchaseStatus::PaymentFailed);
        $checkoutUrl = $this->checkoutUrl($account, $location, $plan);

        $this->actingAs($customer, 'customer')->get($checkoutUrl)->assertOk();
        app(PublicClassPassCheckoutContext::class)->rememberPurchase($account, $location, $plan, $purchase);

        $statusUrl = route('public.class-pass-plans.checkout.status', [
            $account->slug,
            $location->slug,
            $plan->slug,
            $purchase,
        ]);

        $this->getJson($statusUrl)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('status', CustomerPurchaseStatus::PaymentFailed->value)
            ->assertJsonPath('terminal', true)
            ->assertJsonPath('paid', false);

        $otherCustomer = Customer::factory()->for($account)->create();
        $this->actingAs($otherCustomer, 'customer')->getJson($statusUrl)->assertNotFound();

        $this->actingAs($customer, 'customer')
            ->post(route('public.class-pass-plans.checkout.retry', [$account->slug, $location->slug, $plan->slug]))
            ->assertRedirect($checkoutUrl);

        $this->get($checkoutUrl)
            ->assertOk()
            ->assertSee(__('app.class_pass_checkout_payment_title'));
    }

    public function test_payment_return_requires_a_valid_signature(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->purchase($account, $location, $plan, $customer);
        $parameters = [$account->slug, $location->slug, $plan->slug, $purchase];

        $this->actingAs($customer, 'customer')
            ->get(route('public.class-pass-plans.checkout.return', $parameters))
            ->assertForbidden();

        $signedUrl = URL::temporarySignedRoute(
            'public.class-pass-plans.checkout.return',
            now()->addHour(),
            $parameters,
        );

        $this->get($signedUrl)
            ->assertRedirect($this->checkoutUrl($account, $location, $plan));

        $this->get($this->checkoutUrl($account, $location, $plan))
            ->assertOk()
            ->assertSee(__('app.class_pass_checkout_confirmation_title'));
    }

    public function test_checkout_does_not_expose_another_studio_customer_and_rejects_hidden_plan(): void
    {
        [$account, $location, $plan] = $this->checkoutContext();
        $otherAccount = Account::factory()->create();
        $otherCustomer = Customer::factory()->for($otherAccount)->create(['name' => 'Other Studio Customer']);

        $this->actingAs($otherCustomer, 'customer')
            ->get($this->checkoutUrl($account, $location, $plan))
            ->assertOk()
            ->assertSee(__('app.class_pass_checkout_login_title'))
            ->assertDontSee('Other Studio Customer');

        $plan->update(['is_active' => false]);

        $this->get($this->checkoutUrl($account, $location, $plan))->assertNotFound();
    }

    public function test_expired_monopay_checkout_shows_retry_and_preserves_the_original_purchase(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->monopayPurchase($account, $location, $plan, $customer, [
            'expires_at' => now()->subMinute(),
        ]);
        $this->fakeMonopayStatus($purchase, 'expired');
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);

        $this->get($this->checkoutUrl($account, $location, $plan))
            ->assertOk()
            ->assertSee(__('app.class_pass_checkout_status_payment_expired'))
            ->assertSee(__('app.class_pass_checkout_retry'))
            ->assertDontSee('data-class-pass-checkout-poll', false);

        $this->assertSame(CustomerPurchaseStatus::PaymentExpired, $purchase->fresh()->status);
        $this->assertSame(0, $customer->customerClassPasses()->count());

        $this->post(route('public.class-pass-plans.checkout.retry', [$account->slug, $location->slug, $plan->slug]))
            ->assertRedirect($this->checkoutUrl($account, $location, $plan));
        $this->get($this->checkoutUrl($account, $location, $plan))
            ->assertOk()
            ->assertSee(__('app.class_pass_checkout_payment_title'));

        Http::fake([
            'api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'replacement-invoice',
                'pageUrl' => 'https://pay.monobank.ua/invoice/replacement-invoice',
            ]),
        ]);
        $this->post(route('public.class-pass-plans.checkout.store', [$account->slug, $location->slug, $plan->slug]), [
            'studio_rules_accepted' => '1',
            'provider' => 'monopay',
        ])->assertRedirect('https://pay.monobank.ua/invoice/replacement-invoice');

        $this->assertSame(2, $customer->purchases()->count());
        $this->assertSame(CustomerPurchaseStatus::PaymentExpired, $purchase->fresh()->status);
        $this->assertNull($purchase->fresh()->customer_class_pass_id);
    }

    public function test_status_recovers_a_paid_monopay_invoice_without_a_webhook_and_issues_one_pass(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->monopayPurchase($account, $location, $plan, $customer, [
            'expires_at' => now()->subMinute(),
        ]);
        $this->fakeMonopayStatus($purchase, 'success');
        $this->actingAs($customer, 'customer');
        $statusUrl = $this->statusUrl($account, $location, $plan, $purchase);

        $this->getJson($statusUrl)
            ->assertOk()
            ->assertJsonPath('status', CustomerPurchaseStatus::PaymentPaid->value)
            ->assertJsonPath('terminal', true)
            ->assertJsonPath('paid', true)
            ->assertJsonPath('class_pass_ready', true);
        $this->getJson($statusUrl)->assertOk()->assertJsonPath('paid', true);

        $pass = $customer->customerClassPasses()->sole();
        $this->assertSame($pass->id, $purchase->fresh()->customer_class_pass_id);
        $this->assertSame($location->id, $pass->issued_location_id);
        $this->assertTrue($pass->is_paid);
        Http::assertSentCount(1);
    }

    public function test_pending_checkout_limits_bank_checks_and_recovers_on_a_later_poll(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->monopayPurchase($account, $location, $plan, $customer);
        Http::preventStrayRequests();
        Http::fake([
            'api.monobank.ua/api/merchant/invoice/status*' => Http::sequence()
                ->push($this->monopayStatusPayload($purchase, 'created'))
                ->push($this->monopayStatusPayload($purchase, 'success')),
        ]);
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);

        $this->get($this->checkoutUrl($account, $location, $plan))
            ->assertOk()->assertSee('data-class-pass-checkout-poll', false);
        $statusUrl = $this->statusUrl($account, $location, $plan, $purchase);
        $this->getJson($statusUrl)->assertOk()->assertJsonPath('paid', false);
        $this->getJson($statusUrl)->assertOk()->assertJsonPath('paid', false);
        Http::assertSentCount(1);

        $this->travel(16)->seconds();
        $this->getJson($statusUrl)->assertOk()->assertJsonPath('paid', true);
        Http::assertSentCount(2);
    }

    public function test_bank_outage_keeps_an_expired_invoice_pending_and_prevents_a_second_charge(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->monopayPurchase($account, $location, $plan, $customer, [
            'expires_at' => now()->subMinute(),
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.monobank.ua/api/merchant/invoice/status*' => Http::failedConnection()]);
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);

        $this->getJson($this->statusUrl($account, $location, $plan, $purchase))
            ->assertOk()
            ->assertJsonPath('status', CustomerPurchaseStatus::PaymentPending->value)
            ->assertJsonPath('terminal', false);
        $this->post(route('public.class-pass-plans.checkout.retry', [$account->slug, $location->slug, $plan->slug]))
            ->assertRedirect($this->checkoutUrl($account, $location, $plan))
            ->assertSessionHasErrors('payment_status');

        $this->assertSame(CustomerPurchaseStatus::PaymentPending, $purchase->fresh()->status);
        $this->assertSame(1, $customer->purchases()->count());
        $this->assertSame(0, $customer->customerClassPasses()->count());
    }

    #[DataProvider('mismatchedMonopayStatuses')]
    public function test_status_rejects_bank_details_that_do_not_match_the_purchase(array $overrides): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->monopayPurchase($account, $location, $plan, $customer, [
            'expires_at' => now()->subMinute(),
        ]);
        $this->fakeMonopayStatus($purchase, 'success', $overrides);

        $this->actingAs($customer, 'customer')
            ->getJson($this->statusUrl($account, $location, $plan, $purchase))
            ->assertOk()
            ->assertJsonPath('status', CustomerPurchaseStatus::PaymentPending->value)
            ->assertJsonPath('class_pass_ready', false);
        $this->assertSame(0, $customer->customerClassPasses()->count());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function mismatchedMonopayStatuses(): array
    {
        return [
            'invoice' => [['invoiceId' => 'another-invoice']],
            'reference' => [['reference' => 'another-order']],
            'amount' => [['amount' => 1]],
            'missing amount' => [['amount' => null]],
            'currency' => [['ccy' => 840]],
            'unknown currency' => [['ccy' => 999]],
            'missing currency' => [['ccy' => null]],
        ];
    }

    public function test_a_foreign_customer_cannot_trigger_payment_reconciliation(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->monopayPurchase($account, $location, $plan, $customer);
        $this->fakeMonopayStatus($purchase, 'success');
        $otherCustomer = Customer::factory()->for($account)->create();

        $this->actingAs($otherCustomer, 'customer')
            ->getJson($this->statusUrl($account, $location, $plan, $purchase))
            ->assertNotFound();
        Http::assertNothingSent();
        $this->assertSame(CustomerPurchaseStatus::PaymentPending, $purchase->fresh()->status);
    }

    public function test_retry_recovers_an_expired_invoice_before_discarding_its_checkout_context(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->monopayPurchase($account, $location, $plan, $customer, [
            'status' => CustomerPurchaseStatus::PaymentExpired,
        ]);
        $this->fakeMonopayStatus($purchase, 'success');
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);

        $this->post(route('public.class-pass-plans.checkout.retry', [$account->slug, $location->slug, $plan->slug]))
            ->assertRedirect($this->checkoutUrl($account, $location, $plan));
        $this->get($this->checkoutUrl($account, $location, $plan))
            ->assertOk()->assertSee(__('app.class_pass_checkout_status_payment_paid'));
        $this->assertSame(1, $customer->purchases()->count());
        $this->assertSame(1, $customer->customerClassPasses()->count());
    }

    public function test_checkout_expires_other_provider_purchases_without_requesting_monopay(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->purchase($account, $location, $plan, $customer);
        $purchase->update(['expires_at' => now()->subMinute()]);
        Http::preventStrayRequests();
        Http::fake();
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);

        $this->get($this->checkoutUrl($account, $location, $plan))
            ->assertOk()->assertSee(__('app.class_pass_checkout_status_payment_expired'));
        Http::assertNothingSent();
    }

    #[DataProvider('unconfirmedRetryStatuses')]
    public function test_retry_preserves_a_terminal_purchase_when_the_bank_check_is_inconclusive(string $bankStatus): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->monopayPurchase($account, $location, $plan, $customer, [
            'status' => CustomerPurchaseStatus::PaymentExpired,
            'expires_at' => now()->subMinute(),
        ]);
        if ($bankStatus === 'unavailable') {
            Http::preventStrayRequests();
            Http::fake(['api.monobank.ua/api/merchant/invoice/status*' => Http::failedConnection()]);
        } elseif ($bankStatus === 'mismatch') {
            $this->fakeMonopayStatus($purchase, 'success', ['invoiceId' => 'foreign-invoice']);
        } elseif ($bankStatus === 'disabled') {
            Http::preventStrayRequests();
            Http::fake();
            IntegrationSetting::forAccount($account)->where('provider', 'monopay')->update(['is_enabled' => false]);
        } else {
            $this->fakeMonopayStatus($purchase, $bankStatus);
        }
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);

        $this->followingRedirects()
            ->post(route('public.class-pass-plans.checkout.retry', [$account->slug, $location->slug, $plan->slug]))
            ->assertOk()
            ->assertSee(__('app.class_pass_checkout_check_unavailable'));
        $this->assertSame($purchase->id, session('public_class_pass_checkout.purchase_id'));
        $this->assertSame(1, $customer->purchases()->count());
        $this->assertSame(0, $customer->customerClassPasses()->count());

    }

    /** @return array<string, array{0: string}> */
    public static function unconfirmedRetryStatuses(): array
    {
        return [
            'bank outage' => ['unavailable'],
            'bank still processing' => ['processing'],
            'mismatched invoice' => ['mismatch'],
            'disabled integration' => ['disabled'],
        ];
    }

    public function test_retry_checks_the_bank_again_even_during_the_polling_cooldown(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->monopayPurchase($account, $location, $plan, $customer);
        Http::preventStrayRequests();
        Http::fake([
            'api.monobank.ua/api/merchant/invoice/status*' => Http::sequence()
                ->push($this->monopayStatusPayload($purchase, 'expired'))
                ->push($this->monopayStatusPayload($purchase, 'success')),
        ]);
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);
        $this->get($this->checkoutUrl($account, $location, $plan))->assertOk();
        $this->assertSame(CustomerPurchaseStatus::PaymentExpired, $purchase->fresh()->status);

        $this->post(route('public.class-pass-plans.checkout.retry', [$account->slug, $location->slug, $plan->slug]))
            ->assertRedirect($this->checkoutUrl($account, $location, $plan));

        $this->assertSame($purchase->id, session('public_class_pass_checkout.purchase_id'));
        $this->assertSame(CustomerPurchaseStatus::PaymentPaid, $purchase->fresh()->status);
        $this->assertSame(1, $customer->customerClassPasses()->count());
    }

    public function test_demo_checkout_does_not_reconcile_or_expire_a_purchase(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $account->update(['mode' => AccountMode::DemoReadonly]);
        $purchase = $this->monopayPurchase($account, $location, $plan, $customer, [
            'expires_at' => now()->subMinute(),
        ]);
        $this->fakeMonopayStatus($purchase, 'success');

        $this->actingAs($customer, 'customer')
            ->getJson($this->statusUrl($account, $location, $plan, $purchase))
            ->assertOk()->assertJsonPath('status', CustomerPurchaseStatus::PaymentPending->value);

        $this->assertSame(CustomerPurchaseStatus::PaymentPending, $purchase->fresh()->status);
        $this->assertSame(0, $customer->customerClassPasses()->count());
        Http::assertNothingSent();
    }

    #[DataProvider('obsoleteBankStatuses')]
    public function test_retry_preserves_a_terminal_purchase_when_its_saved_callback_is_newer(string $bankStatus): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->monopayPurchase($account, $location, $plan, $customer, [
            'status' => CustomerPurchaseStatus::PaymentExpired,
            'last_callback_payload' => ['status' => 'processing', 'modifiedDate' => now()->toIso8601String()],
        ]);
        $this->fakeMonopayStatus($purchase, $bankStatus, ['modifiedDate' => now()->subMinute()->toIso8601String()]);
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);

        $this->followingRedirects()
            ->post(route('public.class-pass-plans.checkout.retry', [$account->slug, $location->slug, $plan->slug]))
            ->assertOk()->assertSee(__('app.class_pass_checkout_check_unavailable'));

        $this->assertSame($purchase->id, session('public_class_pass_checkout.purchase_id'));
        $this->assertSame(CustomerPurchaseStatus::PaymentExpired, $purchase->fresh()->status);
        $this->assertSame(0, $customer->customerClassPasses()->count());
    }

    /** @return array<string, array{0: string}> */
    public static function obsoleteBankStatuses(): array
    {
        return ['paid' => ['success'], 'expired' => ['expired']];
    }

    public function test_unpaid_monopay_checkout_offers_to_resume_the_existing_invoice(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->resumablePurchase($account, $location, $plan, $customer);
        $this->fakeMonopayStatus($purchase, 'created');
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);

        $this->get($this->checkoutUrl($account, $location, $plan))
            ->assertOk()
            ->assertSee('action="'.$this->checkoutUrl($account, $location, $plan).'/resume"', false)
            ->assertDontSee(__('app.class_pass_checkout_status_payment_pending'));

        $this->post($this->checkoutUrl($account, $location, $plan).'/resume')
            ->assertRedirect('https://pay.monobank.ua/invoice/resumable-checkout');
        $this->assertSame(1, $customer->purchases()->count());
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/invoice/create'));
    }

    #[DataProvider('nonResumableBankStatuses')]
    public function test_resume_checks_the_bank_again_and_does_not_reopen_an_invoice_that_is_no_longer_payable(string $status): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->resumablePurchase($account, $location, $plan, $customer);
        Http::preventStrayRequests();
        Http::fake([
            'api.monobank.ua/api/merchant/invoice/status*' => Http::sequence()
                ->push($this->monopayStatusPayload($purchase, 'created'))
                ->push($this->monopayStatusPayload($purchase, $status)),
        ]);
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);
        $this->get($this->checkoutUrl($account, $location, $plan))->assertOk();

        $this->post($this->checkoutUrl($account, $location, $plan).'/resume')
            ->assertRedirect($this->checkoutUrl($account, $location, $plan));

        $this->assertSame($status, $purchase->fresh()->gateway_status);
        $this->assertSame(1, $customer->purchases()->count());
        $this->assertSame($status === 'success' ? 1 : 0, $customer->customerClassPasses()->count());
        Http::assertSentCount(2);
    }

    /** @return array<string, array{0: string}> */
    public static function nonResumableBankStatuses(): array
    {
        return [
            'bank processing' => ['processing'],
            'already paid' => ['success'],
            'expired' => ['expired'],
            'failed' => ['failure'],
        ];
    }

    public function test_resume_preserves_the_invoice_when_a_fresh_bank_check_fails(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->resumablePurchase($account, $location, $plan, $customer);
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);
        Http::preventStrayRequests();
        Http::fake(['api.monobank.ua/api/merchant/invoice/status*' => Http::failedConnection()]);

        $this->post($this->checkoutUrl($account, $location, $plan).'/resume')
            ->assertRedirect($this->checkoutUrl($account, $location, $plan))
            ->assertSessionHasErrors('payment_status');
        $this->assertSame($purchase->id, session('public_class_pass_checkout.purchase_id'));
        $this->assertSame(1, $customer->purchases()->count());
    }

    #[DataProvider('unsafeResumeUrls')]
    public function test_checkout_never_resumes_an_untrusted_or_missing_payment_url(?string $url): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->resumablePurchase($account, $location, $plan, $customer);
        $purchase->update(['gateway_checkout_payload' => ['response' => ['pageUrl' => $url]]]);
        $this->fakeMonopayStatus($purchase, 'created');
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);

        $this->get($this->checkoutUrl($account, $location, $plan))->assertOk()
            ->assertDontSee('action="'.$this->checkoutUrl($account, $location, $plan).'/resume"', false);
        $this->post($this->checkoutUrl($account, $location, $plan).'/resume')
            ->assertRedirect($this->checkoutUrl($account, $location, $plan))
            ->assertSessionHasErrors('payment_status');
    }

    /** @return array<string, array{0: string|null}> */
    public static function unsafeResumeUrls(): array
    {
        return [
            'missing' => [null],
            'external host' => ['https://example.com/invoice'],
            'deceptive host' => ['https://pay.monobank.ua.example.com/invoice'],
            'insecure' => ['http://pay.monobank.ua/invoice'],
        ];
    }

    public function test_another_customer_cannot_resume_a_remembered_invoice(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->resumablePurchase($account, $location, $plan, $customer);
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);
        $otherCustomer = Customer::factory()->for($account)->create();
        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($otherCustomer, 'customer')
            ->post($this->checkoutUrl($account, $location, $plan).'/resume')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_stale_class_pass_payments_are_reconciled_without_an_open_browser(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->resumablePurchase($account, $location, $plan, $customer);
        $purchase->forceFill(['expires_at' => now()->subHour(), 'updated_at' => now()->subHour()])->save();
        $this->fakeMonopayStatus($purchase, 'expired');

        $this->artisan('customer-purchases:reconcile')->assertSuccessful();
        $this->assertSame(CustomerPurchaseStatus::PaymentExpired, $purchase->fresh()->status);
        $this->assertSame('expired', $purchase->fresh()->gateway_status);
        $this->assertNull($purchase->fresh()->customer_class_pass_id);
    }

    public function test_background_reconciliation_recovers_a_paid_invoice_once_and_skips_fresh_paid_manual_and_demo_purchases(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->resumablePurchase($account, $location, $plan, $customer);
        $purchase->forceFill(['updated_at' => now()->subHour()])->save();
        $this->purchase($account, $location, $plan, $customer);
        $this->purchase($account, $location, $plan, $customer, CustomerPurchaseStatus::PaymentPaid);
        $manual = $this->purchase($account, $location, $plan, $customer);
        $manual->forceFill(['provider' => CustomerPurchase::ProviderStudioCash, 'payment_source' => CustomerPurchase::SourceManualCashClassPass, 'updated_at' => now()->subHour()])->save();
        [$demoAccount, $demoLocation, $demoPlan, $demoCustomer] = $this->checkoutContext();
        $demoAccount->update(['mode' => AccountMode::DemoReadonly]);
        $demo = $this->resumablePurchase($demoAccount, $demoLocation, $demoPlan, $demoCustomer);
        $demo->forceFill(['updated_at' => now()->subHour()])->save();
        $this->fakeMonopayStatus($purchase, 'success');

        $this->artisan('customer-purchases:reconcile')->assertSuccessful();
        $this->artisan('customer-purchases:reconcile')->assertSuccessful();

        $this->assertSame(CustomerPurchaseStatus::PaymentPaid, $purchase->fresh()->status);
        $this->assertSame(1, $customer->customerClassPasses()->count());
        $this->assertSame(CustomerPurchaseStatus::PaymentPending, $demo->fresh()->status);
        $this->assertSame(CustomerPurchaseStatus::PaymentPending, $manual->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_background_reconciliation_rotates_an_unavailable_payment_so_it_cannot_starve_later_payments(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $first = $this->resumablePurchase($account, $location, $plan, $customer);
        $first->forceFill(['updated_at' => now()->subHours(2)])->save();
        $second = $this->purchase($account, $location, $plan, $customer);
        $second->forceFill(['provider' => 'monopay', 'gateway_invoice_id' => 'second-invoice', 'updated_at' => now()->subHour()])->save();
        Http::preventStrayRequests();
        Http::fake(fn ($request) => $request['invoiceId'] === $first->gateway_invoice_id
            ? Http::failedConnection()
            : Http::response($this->monopayStatusPayload($second, 'expired')));
        $this->artisan('customer-purchases:reconcile', ['--limit' => 1])->assertFailed();
        $this->assertSame(CustomerPurchaseStatus::PaymentPending, $first->fresh()->status);
        $this->assertTrue($first->fresh()->updated_at->greaterThan($second->updated_at));

        $this->artisan('customer-purchases:reconcile', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(CustomerPurchaseStatus::PaymentExpired, $second->fresh()->status);
        $this->assertSame(CustomerPurchaseStatus::PaymentPending, $first->fresh()->status);
    }

    #[DataProvider('mismatchedMonopayStatuses')]
    public function test_resume_does_not_redirect_when_bank_details_do_not_match_the_purchase(array $overrides): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->resumablePurchase($account, $location, $plan, $customer);
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);
        $this->fakeMonopayStatus($purchase, 'created', $overrides);

        $this->post($this->checkoutUrl($account, $location, $plan).'/resume')
            ->assertRedirect($this->checkoutUrl($account, $location, $plan))
            ->assertSessionHasErrors('payment_status');
        $this->assertSame(1, $customer->purchases()->count());
    }

    public function test_resume_rejects_an_obsolete_created_status_without_overwriting_processing(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->resumablePurchase($account, $location, $plan, $customer);
        $purchase->update(['gateway_status' => 'processing', 'last_callback_payload' => ['status' => 'processing', 'modifiedDate' => now()->toIso8601String()]]);
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);
        $this->fakeMonopayStatus($purchase, 'created', ['modifiedDate' => now()->subMinute()->toIso8601String()]);

        $this->post($this->checkoutUrl($account, $location, $plan).'/resume')
            ->assertRedirect($this->checkoutUrl($account, $location, $plan))
            ->assertSessionHasErrors('payment_status');
        $this->assertSame('processing', $purchase->fresh()->gateway_status);
    }

    public function test_a_pending_bank_response_cannot_reopen_a_purchase_finalized_during_the_status_check(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $purchase = $this->resumablePurchase($account, $location, $plan, $customer);
        $this->rememberPurchase($account, $location, $plan, $customer, $purchase);
        Http::preventStrayRequests();
        Http::fake(function () use ($purchase) {
            $purchase->update(['status' => CustomerPurchaseStatus::PaymentExpired, 'gateway_status' => 'expired']);

            return Http::response($this->monopayStatusPayload($purchase, 'created'));
        });

        $this->post($this->checkoutUrl($account, $location, $plan).'/resume')
            ->assertRedirect($this->checkoutUrl($account, $location, $plan));
        $this->assertSame(CustomerPurchaseStatus::PaymentExpired, $purchase->fresh()->status);
        $this->assertSame('expired', $purchase->fresh()->gateway_status);
    }

    private function resumablePurchase(Account $account, Location $location, ClassPassPlan $plan, Customer $customer): CustomerPurchase
    {
        return $this->monopayPurchase($account, $location, $plan, $customer, [
            'gateway_status' => 'created',
            'gateway_checkout_payload' => ['response' => ['pageUrl' => 'https://pay.monobank.ua/invoice/resumable-checkout']],
        ]);
    }

    private function rememberPurchase(Account $account, Location $location, ClassPassPlan $plan, Customer $customer, CustomerPurchase $purchase): void
    {
        $this->actingAs($customer, 'customer')->get($this->checkoutUrl($account, $location, $plan))->assertOk();
        app(PublicClassPassCheckoutContext::class)->rememberPurchase($account, $location, $plan, $purchase);
    }

    private function statusUrl(Account $account, Location $location, ClassPassPlan $plan, CustomerPurchase $purchase): string
    {
        return route('public.class-pass-plans.checkout.status', [$account->slug, $location->slug, $plan->slug, $purchase]);
    }

    /** @param array<string, mixed> $attributes */
    private function monopayPurchase(Account $account, Location $location, ClassPassPlan $plan, Customer $customer, array $attributes = []): CustomerPurchase
    {
        $this->accountIntegration($account, IntegrationProvider::Monopay, ['api_token' => 'mono-recovery-token']);
        $purchase = $this->purchase($account, $location, $plan, $customer);
        $purchase->update([
            'provider' => IntegrationProvider::Monopay->value,
            'gateway_invoice_id' => 'checkout-invoice-'.$purchase->id,
            'expires_at' => now()->addHour(),
            ...$attributes,
        ]);

        return $purchase;
    }

    /** @param array<string, mixed> $overrides */
    private function fakeMonopayStatus(CustomerPurchase $purchase, string $status, array $overrides = []): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.monobank.ua/api/merchant/invoice/status*' => Http::response($this->monopayStatusPayload($purchase, $status, $overrides)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function monopayStatusPayload(CustomerPurchase $purchase, string $status, array $overrides = []): array
    {
        return [
            'invoiceId' => $purchase->gateway_invoice_id,
            'reference' => $purchase->order_id,
            'status' => $status,
            'amount' => $purchase->amount_cents,
            'ccy' => 980,
            'modifiedDate' => now()->toIso8601String(),
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $planAttributes
     * @return array{0: Account, 1: Location, 2: ClassPassPlan, 3: Customer}
     */
    private function checkoutContext(array $planAttributes = []): array
    {
        $account = Account::factory()->create([
            'default_language' => 'en',
            'default_currency' => 'UAH',
            'studio_rules_html' => '<p>Checkout rules</p>',
            'public_offer_html' => '<p>Checkout offer</p>',
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'main-studio']);
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'External website pass',
            'slug' => 'external-website-pass',
            'price_cents' => 180000,
            'currency' => 'UAH',
            'sessions_count' => 8,
            ...$planAttributes,
        ]);
        $plan->classTypes()->sync([$classType->id]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Checkout Customer',
            'phone' => '+380501112233',
            'email' => 'checkout-customer-'.fake()->unique()->numberBetween(1000, 9999).'@example.com',
        ]);

        return [$account, $location, $plan, $customer];
    }

    private function checkoutUrl(Account $account, Location $location, ClassPassPlan $plan): string
    {
        return route('public.class-pass-plans.checkout', [$account->slug, $location->slug, $plan->slug]);
    }

    private function purchase(
        Account $account,
        Location $location,
        ClassPassPlan $plan,
        Customer $customer,
        CustomerPurchaseStatus $status = CustomerPurchaseStatus::PaymentPending,
    ): CustomerPurchase {
        return CustomerPurchase::factory()->for($account)->for($customer)->for($location)->for($plan)->create([
            'status' => $status,
            'plan_name' => $plan->name,
            'plan_slug' => $plan->slug,
            'amount_cents' => $plan->price_cents,
            'currency' => $plan->currency,
            'sessions_count' => $plan->sessions_count,
            'validity_days' => $plan->validity_days,
            'total_validity_days' => $plan->total_validity_days,
        ]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function accountIntegration(Account $account, IntegrationProvider $provider, array $credentials): IntegrationSetting
    {
        return IntegrationSetting::create([
            'scope_type' => IntegrationScope::Account->value,
            'scope_id' => $account->id,
            'account_id' => $account->id,
            'provider' => $provider->value,
            'category' => IntegrationCategory::Payment->value,
            'is_enabled' => true,
            'credentials' => $credentials,
        ]);
    }
}
