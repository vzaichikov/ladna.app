<?php

namespace Tests\Feature;

use App\Actions\RecordStudioCashEntry;
use App\Enums\AccountRole;
use App\Enums\IntegrationProvider;
use App\Enums\PromoCodeDiscountType;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\StudioPromoCode;
use App\Models\User;
use App\Support\Payments\LiqPayGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminCustomerCartTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_cash_cart_issues_separate_passes_and_records_one_payment(): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $input = $this->input($location, $plan, 2);
        $this->actingAs($owner);
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input)
            ->assertOk()->assertJsonPath('total_cents', 200000)->json();
        $response = $this->postJson($this->url($account, $customer, 'checkout'), $this->checkout($input, $quote))
            ->assertCreated()->assertJsonPath('paid', true);
        $purchase = CustomerPurchase::findOrFail($response->json('purchase_id'));
        $this->assertCount(2, $purchase->items);
        $this->assertSame(2, $customer->customerClassPasses()->count());
        $this->assertSame(1, $customer->purchases()->count());
        $this->assertSame(200000, (int) $account->studioCashEntries()->sum('amount_cents'));
        $this->assertSame(1, $account->studioCashEntries()->count());
    }

    #[DataProvider('quantityOfferCases')]
    public function test_quantity_offer_issues_independent_passes_with_free_units_last_in_each_group(int $quantity, array $freePositions, int $total): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $promo = $this->quantityPromo($account, $plan);
        $input = [...$this->input($location, $plan, $quantity), 'promo_code' => $promo->code];
        $this->actingAs($owner);
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input)
            ->assertOk()->assertJsonPath('total_cents', $total)->assertJsonPath('lines.0.free_quantity', count($freePositions))->json();
        $this->assertSame(0, $promo->customerPurchases()->count());
        $result = $this->postJson($this->url($account, $customer, 'checkout'), $this->checkout($input, $quote))->assertCreated();
        $purchase = CustomerPurchase::with('items.customerClassPass.purchaseItem')->findOrFail($result->json('purchase_id'));
        $this->assertCount($quantity, $purchase->items);
        $this->assertSame($quantity, $purchase->items->pluck('customerClassPass.code')->unique()->count());
        $this->assertSame($total, $purchase->amount_cents);
        $this->assertSame(9, $purchase->promo_buy_quantity);
        $this->assertSame(1, $purchase->promo_free_quantity);
        $this->assertSame(1, $promo->customerPurchases()->count());
        $this->assertSame(1, $account->studioCashEntries()->count());
        foreach ($purchase->items as $item) {
            $expected = in_array($item->position, $freePositions, true) ? 0 : 100000;
            $this->assertSame($expected, $item->amount_cents);
            $this->assertSame($expected, $item->customerClassPass->paid_amount_cents);
            $this->assertSame(100000, $item->customerClassPass->price_cents);
            $this->assertTrue($item->customerClassPass->is_paid);
            $this->assertSame(0, $item->customerClassPass->remainingPaymentCents());
        }
        $promo->update(['buy_quantity' => 3, 'free_quantity' => 2]);
        $this->assertSame(9, $purchase->fresh()->promo_buy_quantity);
        $this->assertSame(1, $purchase->fresh()->promo_free_quantity);
    }

    public static function quantityOfferCases(): array
    {
        return ['ten' => [10, [9], 900000], 'eleven' => [11, [9], 1000000], 'twenty' => [20, [9, 19], 1800000]];
    }

    public function test_nine_passes_do_not_qualify_for_buy_nine_get_one(): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $promo = $this->quantityPromo($account, $plan);
        $this->actingAs($owner)->postJson($this->url($account, $customer, 'quote'), [...$this->input($location, $plan, 9), 'promo_code' => $promo->code])
            ->assertUnprocessable()->assertJsonValidationErrors('promo_code');
        $this->assertSame(0, $customer->purchases()->count());
    }

    public function test_confirmed_transfer_is_cashless_and_keeps_staff_identity(): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $this->actingAs($owner);
        $input = $this->input($location, $plan, 2);
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input)->assertOk()->json();
        $response = $this->postJson($this->url($account, $customer, 'checkout'), $this->checkout($input, $quote, 'card_transfer'))
            ->assertCreated()->assertJsonPath('paid', true);
        $purchase = CustomerPurchase::findOrFail($response->json('purchase_id'));
        $this->assertSame(CustomerPurchase::ProviderStudioCardTransfer, $purchase->provider);
        $this->assertSame($owner->id, $purchase->actor_user_id);
        $this->assertSame($owner->name, $purchase->actor_name);
        $this->assertSame(0, $account->studioCashEntries()->count());
        $this->assertSame(2, $customer->customerClassPasses()->count());
    }

    public function test_duplicate_cart_lines_combine_before_quantity_promotion(): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $promo = $this->quantityPromo($account, $plan);
        $input = ['items' => [['class_pass_plan_id' => $plan->id, 'quantity' => 4], ['class_pass_plan_id' => $plan->id, 'quantity' => 6]], 'location_id' => $location->id, 'promo_code' => $promo->code];
        $this->actingAs($owner);
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input)->assertOk()->assertJsonCount(1, 'lines')->assertJsonPath('total_cents', 900000)->json();
        $result = $this->postJson($this->url($account, $customer, 'checkout'), $this->checkout($input, $quote))->assertCreated();
        $this->assertSame(10, CustomerPurchase::findOrFail($result->json('purchase_id'))->items()->count());
    }

    #[DataProvider('ordinaryPromotionCases')]
    public function test_ordinary_promotions_settle_discounted_prices(string $type, int $value, int $total): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $promo = StudioPromoCode::factory()->for($account)->create(['discount_type' => $type, 'discount_value' => $value]);
        $promo->classPassPlans()->attach($plan);
        $input = [...$this->input($location, $plan, 2), 'promo_code' => $promo->code];
        $this->actingAs($owner);
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input)->assertOk()->assertJsonPath('total_cents', $total)->json();
        $this->postJson($this->url($account, $customer, 'checkout'), $this->checkout($input, $quote))->assertCreated()->assertJsonPath('paid', true);
        $this->assertSame($total, (int) $customer->customerClassPasses()->sum('paid_amount_cents'));
        $this->assertSame(200000, (int) $customer->customerClassPasses()->sum('price_cents'));
        foreach ($customer->customerClassPasses()->with('purchaseItem')->get() as $pass) {
            $this->assertTrue($pass->is_paid);
            $this->assertSame(0, $pass->remainingPaymentCents());
        }
        $this->assertSame($total === 0 ? 0 : 1, $account->studioCashEntries()->count());
    }

    public static function ordinaryPromotionCases(): array
    {
        return ['fixed' => ['fixed', 30000, 170000], 'percent' => ['percent', 15, 170000], 'free' => ['percent', 100, 0]];
    }

    public function test_replay_reuses_purchase_after_prices_plan_and_quota_change(): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $promo = $this->quantityPromo($account, $plan);
        $promo->update(['max_total_uses' => 1]);
        $input = [...$this->input($location, $plan, 10), 'promo_code' => $promo->code];
        $this->actingAs($owner);
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input)->assertOk()->json();
        $payload = $this->checkout($input, $quote);
        $original = $this->postJson($this->url($account, $customer, 'checkout'), $payload)->assertCreated()->json('purchase_id');
        $plan->update(['price_cents' => 200000, 'is_active' => false]);
        $promo->update(['is_active' => false]);
        $location->update(['is_active' => false]);
        $this->postJson($this->url($account, $customer, 'checkout'), $payload)->assertCreated()->assertJsonPath('purchase_id', $original);
        $this->assertSame(1, $customer->purchases()->count());
        $this->assertSame(10, $customer->customerClassPasses()->count());
        $this->assertSame(1, $account->studioCashEntries()->count());
        $payload['items'][0]['quantity'] = 11;
        $this->postJson($this->url($account, $customer, 'checkout'), $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_stale_quote_requires_review_and_manual_payment_requires_receipt_confirmation(): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $this->actingAs($owner);
        $input = $this->input($location, $plan, 2);
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input)->assertOk()->json();
        $plan->update(['price_cents' => 150000]);
        $this->postJson($this->url($account, $customer, 'checkout'), $this->checkout($input, $quote))->assertUnprocessable()->assertJsonValidationErrors('quote_hash');
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input)->assertOk()->json();
        $payload = $this->checkout($input, $quote);
        unset($payload['payment_received']);
        $this->postJson($this->url($account, $customer, 'checkout'), $payload)->assertUnprocessable()->assertJsonValidationErrors('payment_received');
        $this->assertSame(0, $customer->purchases()->count());
    }

    #[DataProvider('permissionCases')]
    public function test_cart_requires_all_three_permissions_for_every_endpoint(array $permissions, bool $allowed): void
    {
        [, $account, $location, $plan, $customer] = $this->context();
        $trainer = User::factory()->create();
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $trainer->id, 'role' => AccountRole::Trainer, 'permissions' => $permissions]);
        $input = $this->input($location, $plan, 1);
        $this->actingAs($trainer);
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input);
        if ($allowed) {
            $quote->assertOk();
            $result = $this->postJson($this->url($account, $customer, 'checkout'), $this->checkout($input, $quote->json()))->assertCreated();
            $this->getJson(route('dashboard.accounts.customers.cart.status', [$account, $customer, $result->json('purchase_id')]))->assertOk();
        } else {
            $quote->assertForbidden();
            $this->postJson($this->url($account, $customer, 'checkout'), [])->assertForbidden();
            $purchase = CustomerPurchase::factory()->for($account)->for($customer)->create();
            $this->getJson(route('dashboard.accounts.customers.cart.status', [$account, $customer, $purchase]))->assertForbidden();
        }
    }

    public static function permissionCases(): array
    {
        $all = [StudioPermission::ManageClients->value, StudioPermission::IssueCustomerClassPasses->value, StudioPermission::RecordCustomerPayments->value];

        return ['all' => [$all, true], 'missing clients' => [array_slice($all, 1), false], 'missing issue' => [[$all[0], $all[2]], false], 'missing payments' => [array_slice($all, 0, 2), false]];
    }

    public function test_foreign_customer_plan_location_and_status_are_rejected(): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $otherAccount = Account::factory()->internal()->create();
        $foreignCustomer = Customer::factory()->for($otherAccount)->create();
        $foreignLocation = Location::factory()->for($otherAccount)->create();
        $foreignPlan = ClassPassPlan::factory()->for($otherAccount)->create();
        $this->actingAs($owner)->postJson($this->url($account, $foreignCustomer, 'quote'), $this->input($location, $plan, 1))->assertNotFound();
        $this->postJson($this->url($account, $customer, 'quote'), $this->input($location, $foreignPlan, 1))->assertUnprocessable();
        $this->postJson($this->url($account, $customer, 'quote'), $this->input($foreignLocation, $plan, 1))->assertUnprocessable();
        $foreignPurchase = CustomerPurchase::factory()->for($otherAccount)->for($foreignCustomer)->create();
        $this->getJson(route('dashboard.accounts.customers.cart.status', [$account, $customer, $foreignPurchase]))->assertNotFound();
    }

    public function test_trial_cart_allows_one_eligible_pass_and_rejects_repeat(): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $plan->update(['is_trial' => true]);
        $this->actingAs($owner);
        $this->postJson($this->url($account, $customer, 'quote'), $this->input($location, $plan, 2))->assertUnprocessable();
        $input = $this->input($location, $plan, 1);
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input)->assertOk()->json();
        $this->postJson($this->url($account, $customer, 'checkout'), $this->checkout($input, $quote))->assertCreated();
        $this->postJson($this->url($account, $customer, 'quote'), $input)->assertUnprocessable();
    }

    public function test_cash_entry_failure_rolls_back_purchase_and_every_pass(): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $this->actingAs($owner);
        $input = $this->input($location, $plan, 2);
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input)->assertOk()->json();
        $this->mock(RecordStudioCashEntry::class)->shouldReceive('execute')->once()->andThrow(new RuntimeException('Cash recording failed'));
        $this->postJson($this->url($account, $customer, 'checkout'), $this->checkout($input, $quote))->assertServerError();
        $this->assertSame(0, $customer->purchases()->count());
        $this->assertSame(0, $customer->customerClassPasses()->count());
        $this->assertSame(0, $account->studioCashEntries()->count());
    }

    public function test_qr_checkout_has_one_invoice_private_payment_page_and_verified_callback(): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $this->integration($account, IntegrationProvider::Liqpay);
        $promo = $this->quantityPromo($account, $plan);
        $input = [...$this->input($location, $plan, 10), 'promo_code' => $promo->code];
        $this->actingAs($owner);
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input)->assertOk()->json();
        $payload = [...$this->checkout($input, $quote, 'online'), 'provider' => 'liqpay'];
        $response = $this->postJson($this->url($account, $customer, 'checkout'), $payload)->assertCreated()->assertJsonPath('paid', false);
        $purchase = CustomerPurchase::findOrFail($response->json('purchase_id'));
        $this->assertNotNull($purchase->gateway_start_requested_at);
        $checkout = $purchase->gateway_checkout_payload['cart_checkout'];
        $this->postJson($this->url($account, $customer, 'checkout'), $payload)->assertCreated()->assertJsonPath('purchase_id', $purchase->id);
        $this->assertSame($checkout, $purchase->fresh()->gateway_checkout_payload['cart_checkout']);
        $this->assertSame(0, $customer->customerClassPasses()->count());
        $paymentUrl = $response->json('payment.url');
        $this->assertStringContainsString('/cart-payments/', $paymentUrl);
        $this->assertStringStartsWith('data:image/png;base64,', $response->json('payment.qr_data_uri'));
        $this->get($paymentUrl)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('9 000')->assertDontSee($customer->phone)->assertDontSee($customer->email);
        $payUrl = route('public.customer-cart.pay', [$account->slug, $purchase->access_token_encrypted]);
        $this->post($payUrl, [])->assertSessionHasErrors('studio_rules_accepted');
        $this->post($payUrl, ['studio_rules_accepted' => true])->assertOk()->assertSee('www.liqpay.ua/api/3/checkout');
        $this->sendCallback($purchase, amount: '8999.00')->assertBadRequest();
        $this->sendCallback($purchase, signature: 'invalid')->assertBadRequest();
        $this->assertSame(0, $customer->customerClassPasses()->count());
        $this->sendCallback($purchase)->assertOk();
        $this->sendCallback($purchase)->assertOk();
        $this->sendCallback($purchase, amount: '9001.00')->assertBadRequest();
        $this->assertSame(10, $customer->customerClassPasses()->count());
        $this->assertSame(1, $customer->purchases()->count());
        $this->assertSame(0, $account->studioCashEntries()->count());
        $this->get($paymentUrl)->assertOk()->assertSee($customer->customerClassPasses()->firstOrFail()->code);
        $this->getJson(route('public.customer-cart.status', [$account->slug, $purchase->access_token_encrypted]))->assertOk()->assertJsonPath('paid', true);
        $other = Account::factory()->internal()->create();
        $this->get(route('public.customer-cart.payment', [$other->slug, $purchase->access_token_encrypted]))->assertNotFound();
        $this->get(route('public.customer-cart.payment', [$account->slug, Str::random(64)]))->assertNotFound();
        $this->actingAs($owner)->get(route('dashboard.accounts.customers.edit', [$account, $customer]))->assertOk()->assertSee('data-customer-cart', false);
    }

    public function test_pending_purchase_preserves_nullable_plan_values_when_plan_is_edited(): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $this->integration($account, IntegrationProvider::Liqpay);
        $this->actingAs($owner);
        $input = $this->input($location, $plan, 2);
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input)->assertOk()->json();
        $response = $this->postJson($this->url($account, $customer, 'checkout'), [...$this->checkout($input, $quote, 'online'), 'provider' => 'liqpay'])->assertCreated();
        $purchase = CustomerPurchase::findOrFail($response->json('purchase_id'));
        $plan->update(['available_from_time' => '09:00', 'available_until_time' => '12:00', 'allows_any_time' => true, 'any_time_addon_price_cents' => 10000, 'sessions_count' => 8, 'validity_days' => 90, 'price_cents' => 200000]);
        $this->sendCallback($purchase)->assertOk();
        foreach ($customer->customerClassPasses()->get() as $pass) {
            $this->assertNull($pass->available_from_time);
            $this->assertNull($pass->available_until_time);
            $this->assertNull($pass->any_time_addon_price_cents);
            $this->assertFalse($pass->allows_any_time);
            $this->assertSame(1, $pass->sessions_count);
            $this->assertSame(30, $pass->validity_days);
            $this->assertSame(100000, $pass->price_cents);
        }
    }

    public function test_unavailable_gateway_does_not_create_purchase(): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $this->actingAs($owner);
        $input = $this->input($location, $plan, 1);
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input)->assertOk()->json();
        $this->postJson($this->url($account, $customer, 'checkout'), [...$this->checkout($input, $quote, 'online'), 'provider' => 'monopay'])
            ->assertUnprocessable()->assertJsonValidationErrors('provider');
        $this->assertSame(0, $customer->purchases()->count());
    }

    public function test_ambiguous_gateway_start_is_not_repeated_and_expires(): void
    {
        [$owner, $account, $location, $plan, $customer] = $this->context();
        $this->integration($account, IntegrationProvider::Monopay);
        Http::fake(['api.monobank.ua/api/merchant/invoice/create' => Http::response([], 503)]);
        $this->actingAs($owner);
        $input = $this->input($location, $plan, 1);
        $quote = $this->postJson($this->url($account, $customer, 'quote'), $input)->assertOk()->json();
        $payload = [...$this->checkout($input, $quote, 'online'), 'provider' => 'monopay'];
        $response = $this->postJson($this->url($account, $customer, 'checkout'), $payload)->assertCreated()->assertJsonPath('status', 'payment_pending');
        $this->postJson($this->url($account, $customer, 'checkout'), $payload)->assertCreated()->assertJsonPath('purchase_id', $response->json('purchase_id'));
        Http::assertSentCount(1);
        $purchase = CustomerPurchase::findOrFail($response->json('purchase_id'));
        $purchase->update(['expires_at' => now()->subMinute()]);
        $this->getJson(route('dashboard.accounts.customers.cart.status', [$account, $customer, $purchase]))->assertOk()->assertJsonPath('status', 'payment_expired')->assertJsonPath('payment', null);
        $this->assertSame(0, $customer->customerClassPasses()->count());
    }

    /** @return array{User, Account, Location, ClassPassPlan, Customer} */
    private function context(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->internal()->create(['default_currency' => 'UAH', 'default_language' => 'en']);
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $owner->id, 'role' => AccountRole::Owner]);
        $location = Location::factory()->for($account)->create();
        $type = ClassType::factory()->for($account)->create();
        $plan = ClassPassPlan::factory()->for($account)->create(['price_cents' => 100000, 'currency' => 'UAH', 'is_trial' => false, 'sessions_count' => 1]);
        $plan->classTypes()->attach($type);
        $customer = Customer::factory()->for($account)->create();

        return [$owner, $account, $location, $plan, $customer];
    }

    private function quantityPromo(Account $account, ClassPassPlan $plan): StudioPromoCode
    {
        $promo = StudioPromoCode::factory()->for($account)->create(['discount_type' => PromoCodeDiscountType::BuyXGetY, 'discount_value' => 0, 'buy_quantity' => 9, 'free_quantity' => 1]);
        $promo->classPassPlans()->attach($plan);

        return $promo;
    }

    private function integration(Account $account, IntegrationProvider $provider): IntegrationSetting
    {
        return IntegrationSetting::factory()->forAccountScope($account)->create([
            'provider' => $provider, 'is_enabled' => true,
            'credentials' => $provider === IntegrationProvider::Monopay ? ['api_token' => 'test-token'] : ['public_key' => 'test-public', 'private_key' => 'test-private'],
        ]);
    }

    private function input(Location $location, ClassPassPlan $plan, int $quantity): array
    {
        return ['items' => [['class_pass_plan_id' => $plan->id, 'quantity' => $quantity]], 'location_id' => $location->id];
    }

    private function checkout(array $input, array $quote, string $method = 'cash'): array
    {
        return [...$input, 'quote_hash' => $quote['quote_hash'], 'payment_method' => $method, 'payment_received' => true, 'idempotency_key' => (string) Str::uuid()];
    }

    private function url(Account $account, Customer $customer, string $action): string
    {
        return route('dashboard.accounts.customers.cart.'.$action, [$account, $customer]);
    }

    private function sendCallback(CustomerPurchase $purchase, ?string $amount = null, ?string $signature = null): TestResponse
    {
        $data = base64_encode(json_encode(['order_id' => $purchase->order_id, 'status' => 'success', 'amount' => $amount ?? number_format($purchase->amount_cents / 100, 2, '.', ''), 'currency' => $purchase->currency, 'payment_id' => 'test-payment']));

        return $this->post(route('api.v1.payments.callbacks', 'liqpay'), ['data' => $data, 'signature' => $signature ?? app(LiqPayGateway::class)->signature($data, 'test-private')]);
    }
}
