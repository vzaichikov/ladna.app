<?php

namespace Tests\Feature;

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
use App\Support\PublicClassPassCheckoutContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
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

    public function test_ineligible_trial_is_rejected_before_a_purchase_is_created(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext(['is_trial' => true]);
        ClassBooking::factory()->create([
            'account_id' => $account->id,
            'customer_id' => $customer->id,
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
