<?php

namespace Tests\Feature;

use App\Enums\CustomerNotificationStatus;
use App\Enums\SmsDeliveryPurpose;
use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
use App\Models\Account;
use App\Models\CustomerNotification;
use App\Models\CustomerOtpChallenge;
use App\Models\User;
use App\Support\Sms\LegacySmsDeliveryBackfill;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LegacySmsDeliveryBackfillTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_restores_accepted_sms_and_otp_history_idempotently_without_retroactive_charges(): void
    {
        $account = Account::factory()->create();
        $sentAt = now()->subDay()->startOfSecond();
        $message = str_repeat('Я', 71);
        $notification = CustomerNotification::factory()->create([
            'account_id' => $account->id,
            'customer_id' => null,
            'scheduled_class_id' => null,
            'class_booking_id' => null,
            'status' => CustomerNotificationStatus::Sent->value,
            'recipient_phone' => '+380501112233',
            'text' => $message,
            'provider_scope' => 'platform',
            'provider' => 'smsclub',
            'provider_message_id' => 'legacy-notification-id',
            'attempts' => 1,
            'sent_at' => $sentAt,
        ]);
        $pendingNotification = CustomerNotification::factory()->create([
            'account_id' => $account->id,
            'customer_id' => null,
            'scheduled_class_id' => null,
            'class_booking_id' => null,
            'status' => CustomerNotificationStatus::Pending->value,
            'recipient_phone' => '+380501112244',
        ]);
        $challenge = CustomerOtpChallenge::query()->create([
            'account_id' => $account->id,
            'phone' => '+380501112255',
            'code_hash' => Hash::make('123456'),
            'expires_at' => $sentAt->copy()->addMinutes(10),
            'resend_available_at' => $sentAt->copy()->addMinute(),
            'send_count' => 2,
            'last_sent_at' => $sentAt,
            'provider_scope' => 'account',
            'provider' => 'smsclub',
        ]);

        $firstRun = app(LegacySmsDeliveryBackfill::class)->run($account->id);
        $secondRun = app(LegacySmsDeliveryBackfill::class)->run($account->id);

        $this->assertSame([
            'customer_notifications' => 1,
            'customer_otp_sends' => 2,
        ], $firstRun);
        $this->assertSame([
            'customer_notifications' => 0,
            'customer_otp_sends' => 0,
        ], $secondRun);
        $this->assertSame(3, $account->smsDeliveries()->count());

        $notificationDelivery = $account->smsDeliveries()
            ->where('idempotency_key', 'legacy-customer-notification:'.$notification->id)
            ->firstOrFail();

        $this->assertSame(SmsDeliveryPurpose::CustomerNotification, $notificationDelivery->purpose);
        $this->assertSame(SmsDeliveryStatus::Accepted, $notificationDelivery->status);
        $this->assertSame(SmsSendingMode::LadnaService, $notificationDelivery->source_mode);
        $this->assertSame(2, $notificationDelivery->estimated_segments);
        $this->assertSame(2, $notificationDelivery->billed_segments);
        $this->assertNull($notificationDelivery->sms_segment_price_cents);
        $this->assertNull($notificationDelivery->amount_cents);
        $this->assertSame($message, $notificationDelivery->message_preview);
        $this->assertSame(CustomerNotification::class, $notificationDelivery->source_type);
        $this->assertSame($notification->id, $notificationDelivery->source_id);

        $otpDeliveries = $account->smsDeliveries()
            ->where('purpose', SmsDeliveryPurpose::CustomerOtp->value)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $otpDeliveries);
        $this->assertTrue($otpDeliveries->every(fn ($delivery): bool => $delivery->status === SmsDeliveryStatus::Accepted));
        $this->assertTrue($otpDeliveries->every(fn ($delivery): bool => $delivery->source_mode === SmsSendingMode::OwnGateway));
        $this->assertTrue($otpDeliveries->every(fn ($delivery): bool => $delivery->message_preview === null));
        $this->assertTrue($otpDeliveries->every(fn ($delivery): bool => $delivery->amount_cents === null));
        $this->assertTrue($otpDeliveries->every(fn ($delivery): bool => $delivery->source_type === CustomerOtpChallenge::class));
        $this->assertTrue($otpDeliveries->every(fn ($delivery): bool => $delivery->source_id === $challenge->id));
        $this->assertFalse($account->smsDeliveries()
            ->where('idempotency_key', 'legacy-customer-notification:'.$pendingNotification->id)
            ->exists());

        $owner = User::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customer-notification-logs.index', $account))
            ->assertOk()
            ->assertSee('legacy-notification-id')
            ->assertSee('+380501112255');

        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->get(route('platform.sms-deliveries.index', ['account_id' => $account->id]))
            ->assertOk()
            ->assertSee('legacy-notification-id')
            ->assertSee('+380501112255');
    }
}
