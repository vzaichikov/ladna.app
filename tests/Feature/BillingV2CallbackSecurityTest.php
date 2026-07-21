<?php

namespace Tests\Feature;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\IntegrationProvider;
use App\Enums\SubscriptionBillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSubscriptionPayment;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceVersion;
use App\Support\SaasBilling\CreateBillingV2Payment;
use App\Support\SaasBilling\StartAccountTrial;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BillingV2CallbackSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
    }

    public function test_invalid_signature_is_rejected_without_changing_payment(): void
    {
        [, $publicKey] = $this->ecdsaKeys();
        $this->monopaySetting();
        Http::fake(['https://api.monobank.ua/api/merchant/pubkey' => Http::response(['key' => $publicKey])]);
        $payment = $this->pendingPayment();
        $payload = $this->successfulPayload($payment);
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->call(
            'POST',
            route('api.v1.saas.payments.callbacks', IntegrationProvider::Monopay->value),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGN' => base64_encode('invalid')],
            $body,
        )->assertBadRequest();

        $this->assertNotSame(AccountSubscriptionPaymentStatus::PaymentPaid, $payment->refresh()->status);
    }

    public function test_signed_amount_mismatch_is_rejected_before_access_is_extended(): void
    {
        [$privateKey, $publicKey] = $this->ecdsaKeys();
        $this->monopaySetting();
        Http::fake(['https://api.monobank.ua/api/merchant/pubkey' => Http::response(['key' => $publicKey])]);
        $payment = $this->pendingPayment();
        $payload = $this->successfulPayload($payment);
        $payload['finalAmount'] = $payment->amount_cents - 1;

        $this->postSigned($privateKey, $payload)->assertBadRequest();

        $this->assertNotSame(AccountSubscriptionPaymentStatus::PaymentPaid, $payment->refresh()->status);
        $this->assertSame(SubscriptionStatus::Expired, $payment->subscription->refresh()->status);
    }

    public function test_duplicate_valid_callback_is_idempotent(): void
    {
        [$privateKey, $publicKey] = $this->ecdsaKeys();
        $this->monopaySetting();
        Http::fake(['https://api.monobank.ua/api/merchant/pubkey' => Http::response(['key' => $publicKey])]);
        $payment = $this->pendingPayment();
        $payload = $this->successfulPayload($payment);

        $this->postSigned($privateKey, $payload)->assertOk();
        $endsAt = $payment->subscription->refresh()->ends_at;
        $this->postSigned($privateKey, $payload)->assertOk();

        $this->assertSame(AccountSubscriptionPaymentStatus::PaymentPaid, $payment->refresh()->status);
        $this->assertTrue($payment->subscription->refresh()->ends_at->equalTo($endsAt));
        $this->assertSame(1, AccountSubscriptionPayment::where('order_id', $payment->order_id)->count());
    }

    public function test_valid_in_flight_payment_callback_still_completes_when_billing_v2_is_disabled(): void
    {
        [$privateKey, $publicKey] = $this->ecdsaKeys();
        $this->monopaySetting();
        Http::fake(['https://api.monobank.ua/api/merchant/pubkey' => Http::response(['key' => $publicKey])]);
        $payment = $this->pendingPayment();
        config()->set('ladna.saas_billing_v2_enabled', false);

        $this->postSigned($privateKey, $this->successfulPayload($payment))->assertOk();

        $this->assertSame(AccountSubscriptionPaymentStatus::PaymentPaid, $payment->refresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $payment->subscription->refresh()->status);
    }

    private function pendingPayment(): AccountSubscriptionPayment
    {
        $plan = SubscriptionPlan::factory()->create(['name' => 'Ladna']);
        $priceVersion = SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->published()
            ->create(['version' => 1]);
        $account = Account::factory()->create();
        Location::factory()->for($account)->create(['is_active' => true]);
        $subscription = app(StartAccountTrial::class)->execute($account, $priceVersion);
        $subscription->forceFill([
            'status' => SubscriptionStatus::Expired,
            'billing_interval_v2' => SubscriptionBillingInterval::Monthly,
            'ends_at' => now()->subDay(),
        ])->save();

        return app(CreateBillingV2Payment::class)->execute(
            $subscription->refresh(),
            AccountSubscriptionPaymentType::FullSubscription,
            renewalAttempt: 1,
        );
    }

    private function monopaySetting(): IntegrationSetting
    {
        return IntegrationSetting::factory()->create([
            'is_enabled' => true,
            'credentials' => ['api_token' => 'test-token'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulPayload(AccountSubscriptionPayment $payment): array
    {
        return [
            'invoiceId' => 'invoice-'.$payment->id,
            'paymentId' => 'payment-'.$payment->id,
            'status' => 'success',
            'amount' => $payment->amount_cents,
            'finalAmount' => $payment->amount_cents,
            'ccy' => 980,
            'reference' => $payment->order_id,
            'modifiedDate' => now()->toIso8601String(),
        ];
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

        return [$privatePem, base64_encode((string) ($details['key'] ?? ''))];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSigned(mixed $privateKey, array $payload): TestResponse
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
