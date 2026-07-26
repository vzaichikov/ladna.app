<?php

namespace Tests\Feature;

use App\Enums\CustomerOtpSenderScope;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerNotificationSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_can_enable_customer_notifications_and_sms_source(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create([
            'enable_telegram_alerts' => false,
            'enable_customer_notifications' => false,
        ]);

        $this->actingAs($platformAdmin)
            ->put(route('platform.accounts.customer-auth.update', $account), [
                'allow_otp' => '0',
                'allow_rtsp_cameras' => '0',
                'enable_people_counter' => '0',
                'enable_telegram_alerts' => '1',
                'enable_customer_notifications' => '1',
                'otp_sender_scope' => CustomerOtpSenderScope::Platform->value,
                'otp_provider' => null,
                'customer_sms_sender_scope' => CustomerOtpSenderScope::Account->value,
                'customer_sms_provider' => 'smsclub',
            ])
            ->assertRedirect(route('platform.accounts.customer-auth.edit', $account));

        $account->refresh();
        $settings = $account->customerAuthSetting()->firstOrFail();

        $this->assertTrue($account->customerNotificationsEnabled());
        $this->assertFalse($account->telegramAlertsEnabled());
        $this->assertSame(CustomerOtpSenderScope::Account, $settings->customer_sms_sender_scope);
        $this->assertSame('smsclub', $settings->customer_sms_provider);
    }

    public function test_studio_owner_can_configure_customer_notification_tab_when_platform_enabled(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'enable_customer_notifications' => true,
        ]);
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'customers']))
            ->assertOk()
            ->assertSee(__('app.customer_notifications'), false)
            ->assertSee(__('app.customer_notifications_sms_only_legend'))
            ->assertSee(__('app.customer_telegram_bot_settings'))
            ->assertSee(__('app.notification_scenarios'))
            ->assertSee('name="class_reminder_hours_before"', false)
            ->assertSee('name="class_cancellation_enabled"', false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.customer-notification-settings.update', $account), [
                'is_enabled' => '1',
                'class_reminder_enabled' => '1',
                'class_reminder_hours_before' => '7',
                'class_cancellation_enabled' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'customers']));

        $this->assertDatabaseHas('customer_notification_settings', [
            'account_id' => $account->id,
            'is_enabled' => true,
            'class_reminder_enabled' => true,
            'class_reminder_hours_before' => 7,
            'class_cancellation_enabled' => true,
        ]);
    }

    public function test_customer_notification_controls_are_hidden_when_platform_disabled_but_customer_bot_remains_available(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'enable_customer_notifications' => false,
        ]);
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'customers']))
            ->assertOk()
            ->assertSee(__('app.customer_notifications_platform_disabled'))
            ->assertSee(__('app.customer_telegram_bot_settings'))
            ->assertDontSee('name="class_reminder_hours_before"', false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.customer-notification-settings.update', $account), [
                'is_enabled' => '1',
                'class_reminder_enabled' => '1',
                'class_reminder_hours_before' => '5',
            ])
            ->assertNotFound();
    }
}
