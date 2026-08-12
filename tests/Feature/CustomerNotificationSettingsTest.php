<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\SmsSendingMode;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\User;
use App\Support\AccountActivityLogSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerNotificationSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_can_enable_customer_notifications_and_sms_source(): void
    {
        AccountActivityLogSettings::setEnabled(true);
        $platformAdmin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create([
            'enable_telegram_alerts' => false,
            'enable_customer_notifications' => false,
        ]);

        $this->actingAs($platformAdmin)
            ->put(route('platform.accounts.studio-possibilities.update', $account), [
                'allow_otp' => '0',
                'allow_rtsp_cameras' => '0',
                'enable_people_counter' => '0',
                'enable_telegram_alerts' => '1',
                'enable_customer_notifications' => '1',
                'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
                'sms_provider' => 'smsclub',
            ])
            ->assertRedirect(route('platform.accounts.studio-possibilities.edit', $account));

        $account->refresh();
        $settings = $account->customerAuthSetting()->firstOrFail();

        $this->assertTrue($account->customerNotificationsEnabled());
        $this->assertFalse($account->telegramAlertsEnabled());
        $this->assertSame(SmsSendingMode::OwnGateway, $settings->sms_sending_mode);
        $this->assertSame('smsclub', $settings->sms_provider);
        $this->assertDatabaseHas('account_activity_logs', [
            'account_id' => $account->id,
            'route_name' => 'platform.accounts.studio-possibilities.update',
            'actor_user_id' => $platformAdmin->id,
        ]);
    }

    public function test_studio_owner_can_configure_customer_notification_tab_when_platform_enabled(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'enable_customer_notifications' => true,
        ]);
        $account->addOwner($owner);
        $account->customerAuthSetting()->create([
            'allow_otp' => false,
            'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
            'sms_provider' => 'smsclub',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'customers']))
            ->assertOk()
            ->assertSee(__('app.customer_notifications'), false)
            ->assertSee(__('app.customer_notifications_sms_only_legend'))
            ->assertSee(__('app.customer_otp_title'))
            ->assertSee(__('app.customer_otp_enable_hint'))
            ->assertSee(__('app.otp_off'))
            ->assertSee(__('app.customer_otp_own_gateway_setup_help'))
            ->assertSee(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'messaging']), false)
            ->assertSee('name="allow_otp"', false)
            ->assertSee(__('app.notification_scenarios'))
            ->assertSee('name="class_reminder_hours_before"', false)
            ->assertSee('name="class_cancellation_enabled"', false)
            ->assertDontSee('name="telegram_bots[customer][token]"', false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.customer-notification-settings.update', $account), [
                'allow_otp' => '1',
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
        $this->assertDatabaseHas('customer_auth_settings', [
            'account_id' => $account->id,
            'allow_otp' => true,
            'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
            'sms_provider' => 'smsclub',
        ]);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.customer-notification-settings.update', $account), [
                'allow_otp' => '0',
                'is_enabled' => '1',
                'class_reminder_enabled' => '1',
                'class_reminder_hours_before' => '7',
                'class_cancellation_enabled' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'customers']));

        $this->assertDatabaseHas('customer_auth_settings', [
            'account_id' => $account->id,
            'allow_otp' => false,
            'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
            'sms_provider' => 'smsclub',
        ]);

        $account->customerAuthSetting()->update([
            'sms_sending_mode' => SmsSendingMode::LadnaService->value,
            'sms_provider' => null,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'customers']))
            ->assertOk()
            ->assertSee(__('app.customer_otp_sms_setup_help'))
            ->assertSee(route('dashboard.accounts.sms-account.show', $account), false);
    }

    public function test_otp_remains_editable_while_customer_notification_controls_stay_under_platform_capability(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'enable_customer_notifications' => false,
        ]);
        $account->addOwner($owner);
        $notificationSetting = $account->customerNotificationSetting()->create([
            'is_enabled' => true,
            'class_reminder_enabled' => true,
            'class_reminder_hours_before' => 9,
            'class_cancellation_enabled' => true,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'customers']))
            ->assertOk()
            ->assertSee(__('app.customer_notifications_platform_disabled'))
            ->assertSee(__('app.customer_otp_title'))
            ->assertSee('name="allow_otp"', false)
            ->assertDontSee('name="class_reminder_hours_before"', false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.customer-notification-settings.update', $account), [
                'allow_otp' => '1',
                'is_enabled' => '1',
                'class_reminder_enabled' => '0',
                'class_reminder_hours_before' => '3',
                'class_cancellation_enabled' => '0',
            ])
            ->assertRedirect(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'customers']));

        $this->assertTrue($account->customerAuthSetting()->firstOrFail()->allow_otp);
        $notificationSetting->refresh();
        $this->assertTrue($notificationSetting->is_enabled);
        $this->assertTrue($notificationSetting->class_reminder_enabled);
        $this->assertSame(9, $notificationSetting->class_reminder_hours_before);
        $this->assertTrue($notificationSetting->class_cancellation_enabled);
    }

    public function test_customer_notification_update_requires_manage_settings_permission_and_tenant_access(): void
    {
        AccountActivityLogSettings::setEnabled(true);

        $authorizedStaff = User::factory()->create();
        $permissionlessStaff = User::factory()->create();
        $otherOwner = User::factory()->create();
        $account = Account::factory()->create([
            'enable_customer_notifications' => true,
        ]);

        $account->users()->attach($authorizedStaff, [
            'role' => AccountRole::Manager->value,
            'permissions' => [StudioPermission::ManageStudioSettings->value],
        ]);
        $account->users()->attach($permissionlessStaff, [
            'role' => AccountRole::Manager->value,
            'permissions' => [],
        ]);
        Account::factory()->create()->addOwner($otherOwner);

        $payload = [
            'allow_otp' => '1',
            'is_enabled' => '1',
            'class_reminder_enabled' => '1',
            'class_reminder_hours_before' => '5',
            'class_cancellation_enabled' => '0',
        ];

        $this->actingAs($authorizedStaff)
            ->put(route('dashboard.accounts.customer-notification-settings.update', $account), $payload)
            ->assertRedirect(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'customers']));

        $this->assertTrue($account->customerAuthSetting()->firstOrFail()->allow_otp);
        $this->assertDatabaseHas('account_activity_logs', [
            'account_id' => $account->id,
            'route_name' => 'dashboard.accounts.customer-notification-settings.update',
            'actor_user_id' => $authorizedStaff->id,
        ]);

        $this->actingAs($authorizedStaff)
            ->put(
                route('dashboard.accounts.customer-notification-settings.update', $account),
                collect($payload)->except('allow_otp')->all(),
            )
            ->assertRedirect(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'customers']));

        $this->assertTrue($account->customerAuthSetting()->firstOrFail()->allow_otp);

        $this->actingAs($permissionlessStaff)
            ->put(route('dashboard.accounts.customer-notification-settings.update', $account), [
                ...$payload,
                'allow_otp' => '0',
            ])
            ->assertForbidden();

        $this->actingAs($otherOwner)
            ->put(route('dashboard.accounts.customer-notification-settings.update', $account), [
                ...$payload,
                'allow_otp' => '0',
            ])
            ->assertForbidden();

        $this->assertTrue($account->customerAuthSetting()->firstOrFail()->allow_otp);
    }
}
