<?php

namespace Tests\Feature;

use App\Enums\AccountMode;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\SmsDeliveryPurpose;
use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
use App\Models\Account;
use App\Models\AccountSmsWallet;
use App\Models\AccountSubscription;
use App\Models\IntegrationSetting;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Support\CustomerAuth\CustomerOtpService;
use App\Support\CustomerAuth\SmsGateway;
use App\Support\CustomerAuth\SmsGatewayResolver;
use App\Support\CustomerAuth\SmsGatewayResult;
use App\Support\CustomerAuth\SmsSegmentCalculator;
use App\Support\Sms\SmsServiceSettings;
use App\Support\Sms\SmsWalletService;
use App\Support\Sms\StudioSmsSender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudioSmsSenderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ladna_service_reserves_and_charges_cyrillic_segments_without_storing_otp_plaintext(): void
    {
        [$account, $wallet] = $this->ladnaAccount(balanceCents: 1_000, segmentPriceCents: 100);
        Http::fake([
            'https://im.smsclub.mobi/sms/send' => Http::response([
                'success_request' => ['info' => ['sms-id-1' => '+380501112233']],
            ]),
        ]);

        $result = app(StudioSmsSender::class)->send(
            $account,
            '+380501112233',
            str_repeat('Я', 71),
            SmsDeliveryPurpose::CustomerOtp,
        );

        $this->assertTrue($result->accepted());
        $this->assertSame(SmsDeliveryStatus::Accepted, $result->delivery->status);
        $this->assertSame(2, $result->delivery->estimated_segments);
        $this->assertSame(2, $result->delivery->billed_segments);
        $this->assertSame(200, $result->delivery->amount_cents);
        $this->assertSame($account->subscription->subscription_plan_id, $result->delivery->subscription_plan_id);
        $this->assertSame($account->subscription->plan->name, $result->delivery->subscription_plan_name_snapshot);
        $this->assertSame(100, $result->delivery->sms_segment_price_cents);
        $this->assertNull($result->delivery->message_preview);
        $this->assertSame(800, $wallet->refresh()->balance_cents);
        $this->assertSame(0, $wallet->reserved_cents);

        $account->subscription->plan->update(['sms_segment_price_cents' => 250]);

        $this->assertSame(100, $result->delivery->refresh()->sms_segment_price_cents);
        $this->assertSame(200, $result->delivery->amount_cents);
    }

    public function test_customer_otp_service_creates_the_normal_charged_delivery_log(): void
    {
        [$account, $wallet] = $this->ladnaAccount(balanceCents: 1_000, segmentPriceCents: 100);
        Http::fake([
            'https://im.smsclub.mobi/sms/send' => Http::response([
                'success_request' => ['info' => ['customer-otp-id' => '+380501112233']],
            ]),
        ]);

        $result = app(CustomerOtpService::class)->send($account, '+380501112233');

        $this->assertTrue($result->ok);
        $this->assertNotNull($result->challenge);

        $delivery = $account->smsDeliveries()->sole();
        $expectedSegments = app(SmsSegmentCalculator::class)->estimate(__('app.customer_otp_sms_message', [
            'code' => $result->debugCode,
            'studio' => $account->name,
        ]))->segments;

        $this->assertSame(SmsDeliveryPurpose::CustomerOtp, $delivery->purpose);
        $this->assertSame(SmsDeliveryStatus::Accepted, $delivery->status);
        $this->assertSame($expectedSegments, $delivery->billed_segments);
        $this->assertSame(100, $delivery->sms_segment_price_cents);
        $this->assertSame($expectedSegments * 100, $delivery->amount_cents);
        $this->assertSame('customer-otp-id', $delivery->provider_message_id);
        $this->assertNull($delivery->message_preview);
        $this->assertSame($result->challenge->getMorphClass(), $delivery->source_type);
        $this->assertSame($result->challenge->id, $delivery->source_id);
        $this->assertSame(1_000 - ($expectedSegments * 100), $wallet->refresh()->balance_cents);
    }

    public function test_festival_otp_uses_the_same_sensitive_preview_and_credit_rules_as_customer_otp(): void
    {
        [$account, $wallet] = $this->ladnaAccount(balanceCents: 1_000, segmentPriceCents: 100);
        Http::fake([
            'https://im.smsclub.mobi/sms/send' => Http::response([
                'success_request' => ['info' => ['festival-otp-id' => '+380501112233']],
            ]),
        ]);

        $result = app(StudioSmsSender::class)->send(
            $account,
            '+380501112233',
            'Festival code: 123456',
            SmsDeliveryPurpose::FestivalOtp,
        );

        $this->assertTrue($result->accepted());
        $this->assertSame(SmsDeliveryPurpose::FestivalOtp, $result->delivery->purpose);
        $this->assertNull($result->delivery->message_preview);
        $this->assertSame(100, $result->delivery->amount_cents);
        $this->assertSame(900, $wallet->refresh()->balance_cents);
    }

    public function test_ambiguous_provider_timeout_holds_the_reservation_and_is_not_retried(): void
    {
        [$account, $wallet] = $this->ladnaAccount(balanceCents: 1_000, segmentPriceCents: 100);
        Http::fake(fn () => throw new ConnectionException('Timeout.'));

        $result = app(StudioSmsSender::class)->send(
            $account,
            '+380501112233',
            'Appointment reminder',
            SmsDeliveryPurpose::CustomerNotification,
            idempotencyKey: 'ambiguous-send',
        );
        $sameResult = app(StudioSmsSender::class)->send(
            $account,
            '+380501112233',
            'Appointment reminder',
            SmsDeliveryPurpose::CustomerNotification,
            idempotencyKey: 'ambiguous-send',
        );

        $this->assertTrue($result->unknown());
        $this->assertSame($result->delivery->id, $sameResult->delivery->id);
        $this->assertSame(100, $wallet->refresh()->reserved_cents);
        $this->assertSame(1, $account->smsDeliveries()->count());
    }

    public function test_free_tariff_records_segments_without_a_wallet_charge(): void
    {
        [$account] = $this->ladnaAccount(balanceCents: 0, segmentPriceCents: 0);
        Http::fake([
            'https://im.smsclub.mobi/sms/send' => Http::response([
                'success_request' => ['info' => ['sms-id-free' => '+380501112233']],
            ]),
        ]);

        $result = app(StudioSmsSender::class)->send(
            $account,
            '+380501112233',
            str_repeat('A', 161),
            SmsDeliveryPurpose::CustomerNotification,
        );

        $this->assertTrue($result->accepted());
        $this->assertSame(2, $result->delivery->billed_segments);
        $this->assertSame(0, $result->delivery->amount_cents);
        $this->assertSame(0, $account->smsWallet()->firstOrFail()->balance_cents);
    }

    public function test_otp_with_insufficient_credit_fails_without_waiting_or_dispatching(): void
    {
        [$account] = $this->ladnaAccount(balanceCents: 0, segmentPriceCents: 100);
        Http::fake();

        $result = app(StudioSmsSender::class)->send(
            $account,
            '+380501112233',
            'Code: 123456',
            SmsDeliveryPurpose::CustomerOtp,
        );

        $this->assertFalse($result->accepted());
        $this->assertFalse($result->waitingForCredit());
        $this->assertSame(SmsDeliveryStatus::Failed, $result->delivery->status);
        $this->assertSame('insufficient_sms_credit', $result->delivery->error_code);
        Http::assertNothingSent();
    }

    public function test_provider_segment_count_overrides_the_estimate_and_creates_reconciliation_debt(): void
    {
        [$account, $wallet] = $this->ladnaAccount(balanceCents: 250, segmentPriceCents: 100);
        $gateway = new class implements SmsGateway
        {
            public function sendOtp(string $phone, string $message): SmsGatewayResult
            {
                return $this->sendSms($phone, $message);
            }

            public function sendSms(string $phone, string $message): SmsGatewayResult
            {
                return SmsGatewayResult::sent(
                    providerMessageId: 'provider-segments-3',
                    providerSegmentCount: 3,
                    wholesaleCostMinor: 120,
                    wholesaleCostCurrency: 'UAH',
                );
            }
        };
        $resolver = $this->createMock(SmsGatewayResolver::class);
        $resolver->method('resolve')->willReturn($gateway);
        $this->app->instance(SmsGatewayResolver::class, $resolver);

        $result = app(StudioSmsSender::class)->send(
            $account,
            '+380501112233',
            'One estimated segment',
            SmsDeliveryPurpose::CustomerNotification,
        );

        $this->assertTrue($result->accepted());
        $this->assertSame(1, $result->delivery->estimated_segments);
        $this->assertSame(3, $result->delivery->provider_segments);
        $this->assertSame(3, $result->delivery->billed_segments);
        $this->assertSame(300, $result->delivery->amount_cents);
        $this->assertSame(120, $result->delivery->wholesale_cost_cents);
        $this->assertSame(0, $wallet->refresh()->balance_cents);
        $this->assertSame(50, $wallet->outstanding_cents);
    }

    public function test_own_smsclub_gateway_schedules_delivery_status_polling_without_ladna_charge(): void
    {
        $account = Account::factory()->create();
        $account->customerAuthSetting()->create([
            'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
            'sms_provider' => IntegrationProvider::Smsclub->value,
        ]);
        IntegrationSetting::factory()
            ->forAccountScope($account)
            ->create([
                'provider' => IntegrationProvider::Smsclub->value,
                'category' => IntegrationCategory::Messaging->value,
                'is_enabled' => true,
                'credentials' => [
                    'bearer_token' => 'test-token',
                    'src_addr' => 'Studio',
                ],
            ]);
        Http::fake([
            'https://im.smsclub.mobi/sms/send' => Http::response([
                'success_request' => ['info' => ['own-sms-id' => '+380501112233']],
            ]),
        ]);

        $result = app(StudioSmsSender::class)->send(
            $account,
            '+380501112233',
            'Appointment reminder',
            SmsDeliveryPurpose::CustomerNotification,
        );

        $this->assertTrue($result->accepted());
        $this->assertNull($result->delivery->amount_cents);
        $this->assertNull($result->delivery->account_sms_wallet_id);
        $this->assertNotNull($result->delivery->next_status_check_at);
        $this->assertNotNull($result->delivery->status_polling_expires_at);
    }

    public function test_outstanding_debt_blocks_ladna_sends_even_after_switching_to_a_free_tariff(): void
    {
        [$account, $wallet] = $this->ladnaAccount(balanceCents: 0, segmentPriceCents: 0);
        $wallet->forceFill(['outstanding_cents' => 100])->save();
        Http::fake();

        $result = app(StudioSmsSender::class)->send(
            $account,
            '+380501112233',
            'Appointment reminder',
            SmsDeliveryPurpose::CustomerNotification,
        );

        $this->assertFalse($result->accepted());
        $this->assertSame(SmsDeliveryStatus::Failed, $result->delivery->status);
        $this->assertSame('outstanding_sms_debt', $result->delivery->error_code);
        Http::assertNothingSent();
    }

    public function test_read_only_demo_cannot_send_sms_even_when_the_source_is_ready(): void
    {
        [$account, $wallet] = $this->ladnaAccount(balanceCents: 1_000, segmentPriceCents: 100);
        $account->forceFill(['mode' => AccountMode::DemoReadonly->value])->save();
        Http::fake();

        $result = app(StudioSmsSender::class)->send(
            $account->refresh(),
            '+380501112233',
            'Appointment reminder',
            SmsDeliveryPurpose::CustomerNotification,
        );

        $this->assertFalse($result->accepted());
        $this->assertSame(SmsDeliveryStatus::Failed, $result->delivery->status);
        $this->assertSame('sms_disabled', $result->delivery->error_code);
        $this->assertSame(1_000, $wallet->refresh()->balance_cents);
        Http::assertNothingSent();
    }

    /**
     * @return array{Account, AccountSmsWallet}
     */
    private function ladnaAccount(int $balanceCents, int $segmentPriceCents): array
    {
        $plan = SubscriptionPlan::factory()->create([
            'sms_segment_price_cents' => $segmentPriceCents,
        ]);
        $account = Account::factory()->create();
        AccountSubscription::factory()
            ->for($account)
            ->for($plan, 'plan')
            ->create();
        $account->customerAuthSetting()->create([
            'allow_otp' => true,
            'sms_sending_mode' => SmsSendingMode::LadnaService->value,
        ]);
        $wallet = app(SmsWalletService::class)->walletFor($account);
        $wallet->forceFill(['balance_cents' => $balanceCents])->save();
        SystemSetting::setValue(SmsServiceSettings::EnabledKey, '1');
        SystemSetting::setValue(SystemSetting::CentralSmsProviderKey, IntegrationProvider::Smsclub->value);
        IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::Smsclub->value,
            'category' => IntegrationCategory::Messaging->value,
            'is_enabled' => true,
            'credentials' => [
                'bearer_token' => 'test-token',
                'src_addr' => 'Ladna',
            ],
        ]);

        return [$account->refresh(), $wallet];
    }
}
