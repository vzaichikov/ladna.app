<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\CustomerNotificationChannel;
use App\Enums\CustomerNotificationStatus;
use App\Enums\CustomerNotificationType;
use App\Enums\StudioPermission;
use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\CustomerNotification;
use App\Models\TelegramAlert;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class AccountNotificationLogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_studio_owner_can_view_only_their_paginated_customer_sms_log(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $otherAccount = Account::factory()->create();

        foreach (range(1, 26) as $index) {
            CustomerNotification::factory()
                ->for($account)
                ->create([
                    'customer_id' => null,
                    'scheduled_class_id' => null,
                    'class_booking_id' => null,
                    'recipient_name' => sprintf('Scoped Customer %02d', $index),
                    'text' => sprintf('Scoped SMS %02d', $index),
                    'created_at' => now()->subMinutes($index),
                ]);
        }

        CustomerNotification::factory()
            ->for($otherAccount)
            ->create([
                'customer_id' => null,
                'scheduled_class_id' => null,
                'class_booking_id' => null,
                'recipient_name' => 'Other Account Customer',
                'text' => 'Other Account SMS',
            ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customer-notification-logs.index', $account))
            ->assertOk()
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications instanceof LengthAwarePaginator
                && $notifications->perPage() === 25
                && $notifications->hasPages())
            ->assertSee(__('app.customer_sms_log'))
            ->assertSee('Scoped SMS 01')
            ->assertSee('notifications_page=2', false)
            ->assertDontSee('Scoped SMS 26')
            ->assertDontSee('Other Account SMS');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customer-notification-logs.index', [
                'account' => $account,
                'notifications_page' => 2,
            ]))
            ->assertOk()
            ->assertSee('Scoped SMS 26')
            ->assertDontSee('Other Account SMS');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customer-notification-logs.index', [
                'account' => $account,
                'search' => 'Scoped SMS 01',
                'status' => CustomerNotificationStatus::Pending->value,
                'type' => CustomerNotificationType::ClassReminder->value,
                'channel' => CustomerNotificationChannel::Sms->value,
            ]))
            ->assertOk()
            ->assertSee('Scoped SMS 01')
            ->assertDontSee('Scoped SMS 02')
            ->assertDontSee('Other Account SMS');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customer-notification-logs.index', $otherAccount))
            ->assertForbidden();
    }

    public function test_studio_owner_can_filter_only_their_paginated_trainer_telegram_alert_log(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Scoped Trainer']);
        $otherAccount = Account::factory()->create();
        $otherTrainer = Trainer::factory()->for($otherAccount)->create();

        foreach (range(1, 26) as $index) {
            TelegramAlert::factory()
                ->for($account)
                ->for($trainer)
                ->create([
                    'scheduled_class_id' => null,
                    'class_booking_id' => null,
                    'status' => TelegramAlertStatus::Pending->value,
                    'type' => TelegramAlertType::TrainerAssignment->value,
                    'text' => sprintf('Scoped Telegram %02d', $index),
                    'created_at' => now()->subMinutes($index),
                ]);
        }

        TelegramAlert::factory()
            ->for($account)
            ->create([
                'trainer_id' => null,
                'scheduled_class_id' => null,
                'class_booking_id' => null,
                'recipient_kind' => TelegramAlertRecipientKind::StudioOwner->value,
                'type' => TelegramAlertType::OwnerAnnouncement->value,
                'text' => 'Private owner announcement',
            ]);

        TelegramAlert::factory()
            ->for($otherAccount)
            ->for($otherTrainer)
            ->create([
                'scheduled_class_id' => null,
                'class_booking_id' => null,
                'text' => 'Other Account Telegram',
            ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.trainer-telegram-alert-logs.index', $account))
            ->assertOk()
            ->assertViewHas('alerts', fn ($alerts): bool => $alerts instanceof LengthAwarePaginator
                && $alerts->perPage() === 25
                && $alerts->hasPages())
            ->assertSee(__('app.trainer_telegram_alert_log'))
            ->assertSee('Scoped Telegram 01')
            ->assertSee('alerts_page=2', false)
            ->assertDontSee('Scoped Telegram 26')
            ->assertDontSee('Private owner announcement')
            ->assertDontSee('Other Account Telegram');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.trainer-telegram-alert-logs.index', [
                'account' => $account,
                'search' => 'Scoped Telegram 01',
                'status' => TelegramAlertStatus::Pending->value,
                'type' => TelegramAlertType::TrainerAssignment->value,
            ]))
            ->assertOk()
            ->assertSee('Scoped Telegram 01')
            ->assertDontSee('Scoped Telegram 02')
            ->assertDontSee('Private owner announcement')
            ->assertDontSee('Other Account Telegram');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.trainer-telegram-alert-logs.index', $otherAccount))
            ->assertForbidden();
    }

    public function test_staff_needs_view_activity_log_permission_for_notification_logs(): void
    {
        $staff = User::factory()->create();
        $account = Account::factory()->create();
        $membership = AccountMembership::factory()
            ->for($account)
            ->for($staff, 'user')
            ->create([
                'role' => AccountRole::Trainer->value,
                'permissions' => [],
            ]);

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.customer-notification-logs.index', $account))
            ->assertForbidden();
        $this->actingAs($staff)
            ->get(route('dashboard.accounts.trainer-telegram-alert-logs.index', $account))
            ->assertForbidden();

        $membership->update([
            'permissions' => [StudioPermission::ViewActivityLog->value],
        ]);

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.customer-notification-logs.index', $account))
            ->assertOk();
        $this->actingAs($staff)
            ->get(route('dashboard.accounts.trainer-telegram-alert-logs.index', $account))
            ->assertOk();
    }
}
