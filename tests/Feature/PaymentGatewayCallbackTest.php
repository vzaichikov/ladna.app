<?php

namespace Tests\Feature;

use App\Actions\Payments\CreateCustomerPurchase;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use App\Support\Payments\LiqPayGateway;
use App\Support\Payments\PaymentAmounts;
use App\Support\Payments\WayForPayGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentGatewayCallbackTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_liqpay_success_callback_creates_class_pass_and_logs_callback(): void
    {
        [$account, $purchase] = $this->purchase(IntegrationProvider::Liqpay, [
            'public_key' => 'public-key',
            'private_key' => 'private-key',
        ]);
        $data = base64_encode((string) json_encode([
            'order_id' => $purchase->order_id,
            'status' => 'success',
            'amount' => '1800.00',
            'currency' => 'UAH',
            'payment_id' => 12345,
            'liqpay_order_id' => 'liqpay-order-1',
        ]));
        $signature = app(LiqPayGateway::class)->signature($data, 'private-key');

        $this->post(route('api.v1.payments.callbacks', IntegrationProvider::Liqpay->value), [
            'data' => $data,
            'signature' => $signature,
        ])->assertOk();

        $purchase->refresh();

        $this->assertSame('payment_paid', $purchase->status->value);
        $this->assertNotNull($purchase->customer_class_pass_id);
        $this->assertSame(1, CustomerClassPass::whereBelongsTo($purchase->customer)->count());

        $files = Storage::disk('local')->allFiles("payment-callbacks/accounts/{$account->id}/liqpay/{$purchase->order_id}");

        $this->assertNotEmpty($files);
    }

    public function test_liqpay_callback_is_idempotent_and_rejects_bad_signature(): void
    {
        [$account, $purchase] = $this->purchase(IntegrationProvider::Liqpay, [
            'public_key' => 'public-key',
            'private_key' => 'private-key',
        ]);
        unset($account);

        $data = base64_encode((string) json_encode([
            'order_id' => $purchase->order_id,
            'status' => 'success',
            'amount' => '1800.00',
            'currency' => 'UAH',
        ]));

        $this->post(route('api.v1.payments.callbacks', IntegrationProvider::Liqpay->value), [
            'data' => $data,
            'signature' => 'bad-signature',
        ])->assertBadRequest();

        $this->assertSame(0, CustomerClassPass::whereBelongsTo($purchase->customer)->count());

        $signature = app(LiqPayGateway::class)->signature($data, 'private-key');

        $this->post(route('api.v1.payments.callbacks', IntegrationProvider::Liqpay->value), [
            'data' => $data,
            'signature' => $signature,
        ])->assertOk();

        $this->post(route('api.v1.payments.callbacks', IntegrationProvider::Liqpay->value), [
            'data' => $data,
            'signature' => $signature,
        ])->assertOk();

        $this->assertSame(1, CustomerClassPass::whereBelongsTo($purchase->customer)->count());
    }

    public function test_liqpay_failure_callback_marks_purchase_failed_without_class_pass(): void
    {
        [$account, $purchase] = $this->purchase(IntegrationProvider::Liqpay, [
            'public_key' => 'public-key',
            'private_key' => 'private-key',
        ]);
        unset($account);

        $data = base64_encode((string) json_encode([
            'order_id' => $purchase->order_id,
            'status' => 'failure',
            'amount' => '1800.00',
            'currency' => 'UAH',
            'err_description' => 'Declined',
        ]));
        $signature = app(LiqPayGateway::class)->signature($data, 'private-key');

        $this->post(route('api.v1.payments.callbacks', IntegrationProvider::Liqpay->value), [
            'data' => $data,
            'signature' => $signature,
        ])->assertOk();

        $purchase->refresh();

        $this->assertSame('payment_failed', $purchase->status->value);
        $this->assertSame('Declined', $purchase->failure_reason);
        $this->assertNull($purchase->customer_class_pass_id);
    }

    public function test_wayforpay_approved_callback_creates_class_pass_and_returns_signed_acceptance(): void
    {
        [$account, $purchase] = $this->purchase(IntegrationProvider::Wayforpay, [
            'merchant_account' => 'test_merchant',
            'merchant_secret_key' => 'merchant-secret',
            'merchant_domain_name' => 'ladna.local',
            'api_version' => 1,
            'merchant_auth_type' => 'SimpleSignature',
        ]);
        unset($account);

        $payload = [
            'merchantAccount' => 'test_merchant',
            'orderReference' => $purchase->order_id,
            'amount' => '1800.00',
            'currency' => 'UAH',
            'authCode' => '541963',
            'cardPan' => '41****8217',
            'transactionStatus' => 'Approved',
            'reasonCode' => '1100',
            'reason' => 'ok',
            'processingDate' => now()->timestamp,
        ];
        $payload['merchantSignature'] = app(WayForPayGateway::class)->callbackSignature($payload, 'merchant-secret');

        $response = $this->postJson(route('api.v1.payments.callbacks', IntegrationProvider::Wayforpay->value), $payload)
            ->assertOk()
            ->assertJsonPath('orderReference', $purchase->order_id)
            ->assertJsonPath('status', 'accept');

        $responsePayload = $response->json();
        $this->assertSame(
            hash_hmac('md5', implode(';', [$purchase->order_id, 'accept', $responsePayload['time']]), 'merchant-secret'),
            $responsePayload['signature'],
        );
        $this->assertSame('payment_paid', $purchase->fresh()->status->value);
        $this->assertSame(1, CustomerClassPass::whereBelongsTo($purchase->customer)->count());
    }

    public function test_monopay_success_callback_verifies_x_sign_and_creates_class_pass(): void
    {
        [$privateKey, $publicKeyBase64] = $this->ecdsaKeys();
        [$account, $purchase] = $this->purchase(IntegrationProvider::Monopay, [
            'api_token' => 'mono-token',
            'webhook_public_key' => $publicKeyBase64,
            'payment_type' => 'debit',
        ]);
        unset($account);

        $body = (string) json_encode([
            'invoiceId' => 'mono-invoice-1',
            'status' => 'success',
            'amount' => 180000,
            'finalAmount' => 180000,
            'ccy' => PaymentAmounts::iso4217NumericCode('UAH'),
            'reference' => $purchase->order_id,
            'modifiedDate' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);
        openssl_sign($body, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $this->call(
            'POST',
            route('api.v1.payments.callbacks', IntegrationProvider::Monopay->value),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGN' => base64_encode($signature)],
            $body,
        )->assertOk();

        $purchase->refresh();

        $this->assertSame('payment_paid', $purchase->status->value);
        $this->assertSame('mono-invoice-1', $purchase->gateway_invoice_id);
        $this->assertSame(1, CustomerClassPass::whereBelongsTo($purchase->customer)->count());
    }

    public function test_signed_amount_mismatch_is_rejected_without_issuing_pass(): void
    {
        [$account, $purchase] = $this->purchase(IntegrationProvider::Liqpay, [
            'public_key' => 'public-key',
            'private_key' => 'private-key',
        ]);
        unset($account);

        $data = base64_encode((string) json_encode([
            'order_id' => $purchase->order_id,
            'status' => 'success',
            'amount' => '1.00',
            'currency' => 'UAH',
        ]));
        $signature = app(LiqPayGateway::class)->signature($data, 'private-key');

        $this->post(route('api.v1.payments.callbacks', IntegrationProvider::Liqpay->value), [
            'data' => $data,
            'signature' => $signature,
        ])->assertBadRequest();

        $this->assertSame('payment_started', $purchase->fresh()->status->value);
        $this->assertSame(0, CustomerClassPass::whereBelongsTo($purchase->customer)->count());
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{0: Account, 1: CustomerPurchase}
     */
    private function purchase(IntegrationProvider $provider, array $credentials): array
    {
        $account = Account::factory()->create([
            'slug' => 'callback-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Group 8 classes',
            'price_cents' => 180000,
            'currency' => 'UAH',
            'sessions_count' => 8,
            'validity_days' => 30,
        ]);
        $plan->classTypes()->sync([$classType->id]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Payment Client',
            'phone' => '+380501119900',
        ]);

        IntegrationSetting::create([
            'scope_type' => IntegrationScope::Account->value,
            'scope_id' => $account->id,
            'account_id' => $account->id,
            'provider' => $provider->value,
            'category' => IntegrationCategory::Payment->value,
            'is_enabled' => true,
            'credentials' => $credentials,
        ]);

        $purchase = app(CreateCustomerPurchase::class)->execute($account, $customer, $plan, $provider);

        return [$account, $purchase->load(['customer', 'account'])];
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
}
