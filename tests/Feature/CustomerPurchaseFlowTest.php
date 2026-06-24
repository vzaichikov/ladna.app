<?php

namespace Tests\Feature;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\IntegrationSetting;
use App\Models\Location;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerPurchaseFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_returns_to_checkout_after_login_and_profile_completion(): void
    {
        [$account, $location, $plan] = $this->purchaseContext();
        $buyUrl = route('public.class-pass-plans.buy', [$account->slug, $location->slug, $plan->slug]);

        $this->get($buyUrl)
            ->assertRedirect(route('customer.studio.login', $account->slug))
            ->assertSessionHas('url.intended', $buyUrl);

        $this->post(route('customer.email.login', $account->slug), [
            'customer_auth_method' => 'email',
            'email' => 'checkout@example.com',
            'password' => 'secret-password',
        ])->assertRedirect(route('customer.profile.complete', $account->slug));

        $this->put(route('customer.profile.update', $account->slug), [
            'name' => 'Checkout Client',
            'phone' => '+380501112244',
            'email' => 'checkout@example.com',
        ])->assertRedirect($buyUrl);
    }

    public function test_checkout_page_shows_configured_payment_buttons(): void
    {
        [$account, $location, $plan, $customer] = $this->purchaseContext();
        $this->accountIntegration($account, IntegrationProvider::Liqpay, [
            'public_key' => 'public-key',
            'private_key' => 'private-key',
        ]);

        $this->actingAs($customer, 'customer')
            ->get(route('public.class-pass-plans.buy', [$account->slug, $location->slug, $plan->slug]))
            ->assertOk()
            ->assertSee($plan->name)
            ->assertSee('LiqPay')
            ->assertDontSee('We will create a payment attempt before sending you to the provider.')
            ->assertSee('name="provider"', false)
            ->assertSee(route('public.class-pass-plans.purchase', [$account->slug, $location->slug, $plan->slug]), false);
    }

    public function test_liqpay_purchase_creates_payment_attempt_before_form_redirect(): void
    {
        [$account, $location, $plan, $customer] = $this->purchaseContext();
        $this->accountIntegration($account, IntegrationProvider::Liqpay, [
            'public_key' => 'public-key',
            'private_key' => 'private-key',
        ]);

        $this->actingAs($customer, 'customer')
            ->post(route('public.class-pass-plans.purchase', [$account->slug, $location->slug, $plan->slug]), [
                'provider' => IntegrationProvider::Liqpay->value,
            ])
            ->assertOk()
            ->assertSee('https://www.liqpay.ua/api/3/checkout', false)
            ->assertSee('name="data"', false)
            ->assertSee('name="signature"', false);

        $purchase = $customer->purchases()->firstOrFail();

        $this->assertSame(IntegrationProvider::Liqpay->value, $purchase->provider);
        $this->assertSame('payment_started', $purchase->status->value);
        $this->assertSame($plan->name, $purchase->plan_name);
        $this->assertSame($plan->price_cents, $purchase->amount_cents);
        $this->assertSame(0, CustomerClassPass::whereBelongsTo($customer)->count());
    }

    public function test_monopay_purchase_creates_invoice_and_redirects_to_provider(): void
    {
        [$account, $location, $plan, $customer] = $this->purchaseContext();
        $this->accountIntegration($account, IntegrationProvider::Monopay, [
            'api_token' => 'mono-token',
            'payment_type' => 'debit',
            'invoice_validity_seconds' => 3600,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'mono-invoice-1',
                'pageUrl' => 'https://pay.monobank.ua/invoice/mono-invoice-1',
                'status' => 'created',
            ]),
        ]);

        $this->actingAs($customer, 'customer')
            ->post(route('public.class-pass-plans.purchase', [$account->slug, $location->slug, $plan->slug]), [
                'provider' => IntegrationProvider::Monopay->value,
            ])
            ->assertRedirect('https://pay.monobank.ua/invoice/mono-invoice-1');

        $purchase = $customer->purchases()->firstOrFail();

        $this->assertSame('mono-invoice-1', $purchase->gateway_invoice_id);
        $this->assertSame('created', $purchase->gateway_status);

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Token', 'mono-token')
            && $request->data()['amount'] === $plan->price_cents
            && $request->data()['merchantPaymInfo']['reference'] === $purchase->order_id);
    }

    /**
     * @return array{0: Account, 1: Location, 2: ClassPassPlan, 3: Customer}
     */
    private function purchaseContext(): array
    {
        $account = Account::factory()->create([
            'default_language' => 'en',
            'slug' => 'purchase-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'main']);
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Group 8 classes',
            'slug' => 'group-8',
            'price_cents' => 180000,
            'currency' => 'UAH',
            'sessions_count' => 8,
            'validity_days' => 30,
        ]);
        $plan->classTypes()->sync([$classType->id]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Existing Client',
            'phone' => '+380501112233',
            'email' => 'existing@example.com',
        ]);

        return [$account, $location, $plan, $customer];
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
