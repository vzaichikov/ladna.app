<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\TrainerNotificationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TrainerNotificationSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_view_independent_notification_tabs_with_trainer_scenario_enabled_by_default(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['enable_telegram_alerts' => true]);
        $account->addOwner($owner);

        $this->assertFalse($account->trainerNotificationSetting()->exists());
        $this->assertTrue($account->trainerAssignmentTelegramAlertsEnabled());
        $this->assertFalse($account->trainerClassCancellationTelegramAlertsEnabled());

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'trainers']))
            ->assertOk()
            ->assertSee(__('app.notifications_trainers'))
            ->assertSee(__('app.trainer_notifications_master_hint'))
            ->assertSee(__('app.trainer_notification_assignment_hint'))
            ->assertSee(__('app.trainer_notification_class_cancellation_hint'))
            ->assertSee('name="enable_telegram_alerts"', false)
            ->assertSee('name="trainer_assignment_enabled"', false)
            ->assertSee('name="class_cancellation_enabled"', false)
            ->assertDontSee('name="class_reminder_hours_before"', false)
            ->assertDontSee('name="telegram_bots[customer][token]"', false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'customers']))
            ->assertOk()
            ->assertSee(__('app.notifications_customers'))
            ->assertSee('name="telegram_bots[customer][token]"', false)
            ->assertDontSee('name="trainer_assignment_enabled"', false);

        $this->assertFalse($account->trainerNotificationSetting()->exists());
    }

    public function test_owner_can_save_master_and_assignment_switches_together(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['enable_telegram_alerts' => true]);
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.trainer-notification-settings.update', $account), [
                'enable_telegram_alerts' => '0',
                'trainer_assignment_enabled' => '0',
                'class_cancellation_enabled' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'trainers']))
            ->assertSessionHas('status', __('app.trainer_notification_settings_updated'));

        $this->assertFalse($account->fresh()->telegramAlertsEnabled());
        $this->assertDatabaseHas((new TrainerNotificationSetting)->getTable(), [
            'account_id' => $account->id,
            'trainer_assignment_enabled' => false,
            'class_cancellation_enabled' => true,
        ]);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.trainer-notification-settings.update', $account), [
                'enable_telegram_alerts' => '1',
                'trainer_assignment_enabled' => '1',
                'class_cancellation_enabled' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'trainers']));

        $this->assertTrue($account->fresh()->trainerAssignmentTelegramAlertsEnabled());
        $this->assertTrue($account->fresh()->trainerClassCancellationTelegramAlertsEnabled());
    }

    public function test_staff_needs_manage_studio_settings_permission_and_cannot_cross_tenants(): void
    {
        $authorizedStaff = User::factory()->create();
        $permissionlessStaff = User::factory()->create();
        $otherOwner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();

        $account->users()->attach($authorizedStaff, [
            'role' => AccountRole::Manager->value,
            'permissions' => [StudioPermission::ManageStudioSettings->value],
        ]);
        $account->users()->attach($permissionlessStaff, [
            'role' => AccountRole::Manager->value,
            'permissions' => [],
        ]);
        $otherAccount->addOwner($otherOwner);

        $this->actingAs($authorizedStaff)
            ->get(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'trainers']))
            ->assertOk();

        $this->actingAs($authorizedStaff)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee(route('dashboard.accounts.qr-links.show', $account), false)
            ->assertSee(route('dashboard.accounts.notification-settings.edit', $account), false);

        $this->actingAs($authorizedStaff)
            ->put(route('dashboard.accounts.trainer-notification-settings.update', $account), [
                'enable_telegram_alerts' => '1',
                'trainer_assignment_enabled' => '1',
            ])
            ->assertRedirect();

        $this->actingAs($permissionlessStaff)
            ->get(route('dashboard.accounts.notification-settings.edit', $account))
            ->assertForbidden();

        $this->actingAs($permissionlessStaff)
            ->get(route('dashboard.accounts.qr-links.show', $account))
            ->assertForbidden();

        $this->actingAs($permissionlessStaff)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertDontSee(route('dashboard.accounts.qr-links.show', $account), false)
            ->assertDontSee(route('dashboard.accounts.notification-settings.edit', $account), false);

        $this->actingAs($otherOwner)
            ->get(route('dashboard.accounts.notification-settings.edit', $account))
            ->assertForbidden();

        $this->actingAs($otherOwner)
            ->put(route('dashboard.accounts.trainer-notification-settings.update', $account), [
                'enable_telegram_alerts' => '0',
                'trainer_assignment_enabled' => '0',
            ])
            ->assertForbidden();
    }

    public function test_legacy_general_settings_tabs_redirect_to_canonical_pages(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'qr']))
            ->assertRedirect(route('dashboard.accounts.qr-links.show', $account));

        foreach (['customer_notifications', 'ai'] as $legacyTab) {
            $this->actingAs($owner)
                ->get(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => $legacyTab]))
                ->assertRedirect(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'customers']));
        }
    }
}
