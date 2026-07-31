<?php

namespace Tests\Feature;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
use App\Models\Account;
use App\Models\IntegrationSetting;
use App\Models\SmsDelivery;
use App\Models\SystemSetting;
use App\Support\Sms\SmsServiceSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsDeliveryStatusSyncTest extends TestCase
{
    use DatabaseTransactions;

    public function test_status_polling_marks_smsclub_delivery_as_delivered(): void
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
        $delivery = SmsDelivery::factory()->create([
            'account_id' => $account->id,
            'source_mode' => SmsSendingMode::OwnGateway->value,
            'provider' => IntegrationProvider::Smsclub->value,
            'provider_message_id' => 'sms-id-1',
            'status' => SmsDeliveryStatus::Accepted->value,
            'accepted_at' => now()->subMinutes(10),
            'next_status_check_at' => now()->subMinute(),
            'status_polling_expires_at' => now()->addDay(),
        ]);
        Http::fake([
            'https://im.smsclub.mobi/sms/status' => Http::response([
                'success_request' => ['info' => ['sms-id-1' => 'DELIVRD']],
            ]),
        ]);
        $account->customerAuthSetting()->update([
            'sms_sending_mode' => SmsSendingMode::Disabled->value,
            'sms_provider' => null,
        ]);

        $this->artisan('sms-deliveries:sync-statuses')
            ->expectsOutput('Checked 1 SMS deliveries; updated 1.')
            ->assertSuccessful();

        $this->assertSame(SmsDeliveryStatus::Delivered, $delivery->refresh()->status);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertNull($delivery->next_status_check_at);
    }

    public function test_central_provider_balance_is_stored_for_platform_monitoring(): void
    {
        IntegrationSetting::create([
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
            'provider' => IntegrationProvider::Smsclub->value,
            'category' => IntegrationCategory::Messaging->value,
            'is_enabled' => true,
            'credentials' => [
                'bearer_token' => 'central-token',
                'src_addr' => 'Ladna',
            ],
        ]);
        SystemSetting::setValue(
            SystemSetting::CentralSmsProviderKey,
            IntegrationProvider::Smsclub->value,
        );
        Http::fake([
            'https://im.smsclub.mobi/sms/balance' => Http::response([
                'success_request' => [
                    'info' => [
                        'money' => '8121.18',
                        'currency' => 'UAH',
                    ],
                ],
            ]),
        ]);

        $this->artisan('sms-service:check-provider-balance')
            ->expectsOutput('Central SMS provider balance: 8121.18 UAH.')
            ->assertSuccessful();

        $this->assertSame(
            '812118',
            SystemSetting::stringValue(SmsServiceSettings::ProviderBalanceCentsKey),
        );
        $this->assertSame(
            'UAH',
            SystemSetting::stringValue(SmsServiceSettings::ProviderBalanceCurrencyKey),
        );
        $this->assertNotNull(
            SystemSetting::stringValue(SmsServiceSettings::ProviderBalanceCheckedAtKey),
        );
        $this->assertNull(
            SystemSetting::stringValue(SmsServiceSettings::ProviderBalanceErrorKey),
        );
    }
}
