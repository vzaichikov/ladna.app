<?php

namespace Tests\Feature;

use App\Actions\Payments\CompleteCustomerPurchase;
use App\Actions\Payments\StartAdminCustomerPurchasePayment;
use App\Actions\Payments\StartCustomerPurchasePayment;
use App\Enums\IntegrationProvider;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseItem;
use App\Models\IntegrationSetting;
use App\Support\Payments\LiqPayGateway;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminCustomerCartGatewaySafetyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    #[DataProvider('missingPaymentFields')]
    public function test_signed_paid_cart_callback_requires_amount_and_currency(string $missingField): void
    {
        $purchase = $this->pendingCart();
        $payload = ['order_id' => $purchase->order_id, 'status' => 'success', 'amount' => '1000.00', 'currency' => 'UAH'];
        unset($payload[$missingField]);
        $data = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = app(LiqPayGateway::class)->signature($data, 'test-private');

        $this->post(route('api.v1.payments.callbacks', 'liqpay'), compact('data', 'signature'))->assertBadRequest();

        $this->assertFalse($purchase->fresh()->isPaid());
        $this->assertSame(0, $purchase->customer->customerClassPasses()->count());
    }

    public static function missingPaymentFields(): array
    {
        return ['missing amount' => ['amount'], 'missing currency' => ['currency']];
    }

    public function test_gateway_failure_does_not_overwrite_a_concurrent_paid_callback(): void
    {
        $purchase = $this->pendingCart();
        $gatewayFailed = false;
        $callbackCommitted = false;
        $this->mock(StartCustomerPurchasePayment::class)->shouldReceive('execute')->once()
            ->andReturnUsing(function () use (&$gatewayFailed): never {
                $gatewayFailed = true;
                throw new RuntimeException('Invoice initialization response was lost.');
            });
        DB::listen(function (QueryExecuted $query) use ($purchase, &$gatewayFailed, &$callbackCommitted): void {
            if (! $gatewayFailed || $callbackCommitted || ! str_starts_with($query->sql, 'select')
                || ! str_contains($query->sql, '`customer_purchases`')) {
                return;
            }
            $callbackCommitted = true;
            app(CompleteCustomerPurchase::class)->execute($purchase, new PaymentCallbackResult(
                orderId: $purchase->order_id, status: PaymentCallbackStatus::Paid,
                amountCents: $purchase->amount_cents, currency: $purchase->currency,
                gatewayPaymentId: 'confirmed-during-start-failure', paidAt: now(),
            ));
        });

        $result = app(StartAdminCustomerPurchasePayment::class)->execute($purchase);

        $this->assertTrue($callbackCommitted);
        $this->assertTrue($result->isPaid());
        $this->assertTrue($purchase->fresh()->isPaid());
        $this->assertSame(1, $purchase->customer->customerClassPasses()->count());
        $this->assertSame('confirmed-during-start-failure', $purchase->fresh()->gateway_payment_id);
    }

    public function test_legacy_single_pass_keeps_nullable_callback_details(): void
    {
        $cart = $this->pendingCart();
        $legacy = CustomerPurchase::factory()->for($cart->account)->for($cart->customer)->for($cart->items->first()->classPassPlan)->create();

        app(CompleteCustomerPurchase::class)->execute($legacy, new PaymentCallbackResult(
            orderId: $legacy->order_id, status: PaymentCallbackStatus::Paid, paidAt: now(),
        ));

        $this->assertTrue($legacy->fresh()->isPaid());
        $this->assertNotNull($legacy->fresh()->customer_class_pass_id);
    }

    private function pendingCart(): CustomerPurchase
    {
        $account = Account::factory()->internal()->create(['default_currency' => 'UAH']);
        $customer = Customer::factory()->for($account)->create();
        $plan = ClassPassPlan::factory()->for($account)->create(['price_cents' => 100000, 'currency' => 'UAH', 'is_trial' => false]);
        $plan->classTypes()->attach(ClassType::factory()->for($account)->create());
        $purchase = CustomerPurchase::factory()->for($account)->for($customer)->create([
            'class_pass_plan_id' => null, 'amount_cents' => 100000, 'currency' => 'UAH',
            'order_id' => 'CART-'.Str::uuid(), 'expires_at' => now()->addHour(),
            'access_token_encrypted' => Str::random(64),
        ]);
        CustomerPurchaseItem::factory()->for($purchase, 'purchase')->create([
            'account_id' => $account->id, 'class_pass_plan_id' => $plan->id, 'schedule_kind' => $plan->schedule_kind->value,
        ]);
        IntegrationSetting::factory()->forAccountScope($account)->create([
            'provider' => IntegrationProvider::Liqpay, 'is_enabled' => true,
            'credentials' => ['public_key' => 'test-public', 'private_key' => 'test-private'],
        ]);

        return $purchase->load('items.classPassPlan');
    }
}
