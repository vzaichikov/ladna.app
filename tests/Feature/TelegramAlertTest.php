<?php

namespace Tests\Feature;

use App\Enums\ScheduleKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\TelegramAlert;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\Trainer;
use App\Support\Telegram\Alerts\QueueTrainerAssignmentTelegramAlert;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramAlertTest extends TestCase
{
    use DatabaseTransactions;

    public function test_private_booking_queues_pending_trainer_assignment_alert(): void
    {
        ['booking' => $booking, 'scheduledClass' => $scheduledClass, 'trainer' => $trainer] = $this->bookingFixture(ScheduleKind::PrivateLesson);

        $alert = app(QueueTrainerAssignmentTelegramAlert::class)->execute($booking);

        $this->assertNotNull($alert);
        $this->assertSame(TelegramAlertType::TrainerAssignment, $alert->type);
        $this->assertSame(TelegramAlertStatus::Pending, $alert->status);
        $this->assertSame($trainer->id, $alert->trainer_id);
        $this->assertSame($scheduledClass->id, $alert->scheduled_class_id);
        $this->assertNull($alert->telegram_chat_id);
        $this->assertStringContainsString('Iryna, you have a new booking in Test Studio', (string) $alert->text);
        $this->assertStringContainsString('Private Pole', (string) $alert->text);
        $this->assertStringContainsString('Test Studio', (string) $alert->text);
        $this->assertStringContainsString('Customer: Anna', (string) $alert->text);
    }

    public function test_disabled_studio_still_queues_pending_assignment_alert(): void
    {
        ['account' => $account, 'booking' => $booking] = $this->bookingFixture(ScheduleKind::PrivateLesson);
        $account->update(['enable_telegram_alerts' => false]);

        $alert = app(QueueTrainerAssignmentTelegramAlert::class)->execute($booking);

        $this->assertNotNull($alert);
        $this->assertSame(TelegramAlertStatus::Pending, $alert->status);
    }

    public function test_group_booking_queues_only_first_active_assignment_alert(): void
    {
        ['account' => $account, 'scheduledClass' => $scheduledClass, 'booking' => $firstBooking] = $this->bookingFixture(ScheduleKind::GroupClass);

        app(QueueTrainerAssignmentTelegramAlert::class)->execute($firstBooking);

        $secondBooking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass, 'scheduledClass')
            ->for(Customer::factory()->for($account))
            ->create();

        app(QueueTrainerAssignmentTelegramAlert::class)->execute($secondBooking);

        $this->assertSame(1, TelegramAlert::where('scheduled_class_id', $scheduledClass->id)->count());
        $this->assertNotNull(TelegramAlert::where('scheduled_class_id', $scheduledClass->id)->firstOrFail()->dedupe_key);
    }

    public function test_group_first_booking_still_queues_if_later_booking_already_exists(): void
    {
        ['account' => $account, 'scheduledClass' => $scheduledClass, 'booking' => $firstBooking] = $this->bookingFixture(ScheduleKind::GroupClass);
        ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass, 'scheduledClass')
            ->for(Customer::factory()->for($account))
            ->create();

        $alert = app(QueueTrainerAssignmentTelegramAlert::class)->execute($firstBooking);

        $this->assertNotNull($alert);
        $this->assertSame(1, TelegramAlert::where('scheduled_class_id', $scheduledClass->id)->count());
    }

    public function test_send_command_uses_newest_trainer_authorization_and_logs_outbound_message(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 98765],
        ])]);

        ['account' => $account, 'trainer' => $trainer] = $this->bookingFixture(ScheduleKind::PrivateLesson);
        $installation = $this->platformOwnerInstallation([
            'encrypted_token' => '123456:owner-secret',
            'is_enabled' => true,
        ]);
        TelegramChatAuthorization::factory()->for($account)->for($trainer, 'trainer')->create([
            'telegram_bot_installation_id' => $installation->id,
            'telegram_chat_id' => 'old-chat',
            'authorized_at' => now()->subHour(),
        ]);
        $newerAuthorization = TelegramChatAuthorization::factory()->for($account)->for($trainer, 'trainer')->create([
            'telegram_bot_installation_id' => $installation->id,
            'telegram_chat_id' => 'new-chat',
            'telegram_user_id' => 'new-user',
            'authorized_at' => now(),
        ]);
        $alert = TelegramAlert::factory()->for($account)->for($trainer)->create([
            'text' => 'Hello trainer',
        ]);

        $this->artisan('telegram-alerts:send')
            ->expectsOutput(__('app.telegram_alerts_send_command_result', [
                'processed' => 1,
                'sent' => 1,
                'retried' => 0,
                'failed' => 0,
            ]))
            ->assertSuccessful();

        $alert->refresh();

        $this->assertSame(TelegramAlertStatus::Sent, $alert->status);
        $this->assertSame('new-chat', $alert->telegram_chat_id);
        $this->assertSame('98765', $alert->telegram_message_id);
        $this->assertSame($newerAuthorization->id, $alert->telegram_chat_authorization_id);
        $this->assertDatabaseHas((new TelegramMessage)->getTable(), [
            'telegram_chat_authorization_id' => $newerAuthorization->id,
            'telegram_chat_id' => 'new-chat',
            'telegram_message_id' => '98765',
            'message_type' => 'alert',
            'text' => 'Hello trainer',
        ]);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === 'new-chat'
            && $request['text'] === 'Hello trainer');
    }

    public function test_send_command_fails_alert_without_trainer_authorization(): void
    {
        Http::fake();

        ['account' => $account, 'trainer' => $trainer] = $this->bookingFixture(ScheduleKind::PrivateLesson);
        $this->platformOwnerInstallation([
            'encrypted_token' => '123456:owner-secret',
            'is_enabled' => true,
        ]);
        $alert = TelegramAlert::factory()->for($account)->for($trainer)->create();

        $this->artisan('telegram-alerts:send')
            ->expectsOutput(__('app.telegram_alerts_send_command_result', [
                'processed' => 1,
                'sent' => 0,
                'retried' => 0,
                'failed' => 1,
            ]))
            ->assertSuccessful();

        $alert->refresh();

        $this->assertSame(TelegramAlertStatus::Failed, $alert->status);
        $this->assertSame(1, $alert->attempts);
        $this->assertSame('trainer_telegram_authorization_missing', $alert->last_error);
    }

    public function test_send_command_fails_disabled_studio_alert_without_telegram_request(): void
    {
        Http::fake();

        ['account' => $account, 'trainer' => $trainer] = $this->bookingFixture(ScheduleKind::PrivateLesson);
        $account->update(['enable_telegram_alerts' => false]);
        $installation = $this->platformOwnerInstallation([
            'encrypted_token' => '123456:owner-secret',
            'is_enabled' => true,
        ]);
        TelegramChatAuthorization::factory()->for($account)->for($trainer, 'trainer')->create([
            'telegram_bot_installation_id' => $installation->id,
            'telegram_chat_id' => 'disabled-chat',
        ]);
        $alert = TelegramAlert::factory()->for($account)->for($trainer)->create();

        $this->artisan('telegram-alerts:send')
            ->expectsOutput(__('app.telegram_alerts_send_command_result', [
                'processed' => 1,
                'sent' => 0,
                'retried' => 0,
                'failed' => 1,
            ]))
            ->assertSuccessful();

        $alert->refresh();

        $this->assertSame(TelegramAlertStatus::Failed, $alert->status);
        $this->assertSame(1, $alert->attempts);
        $this->assertSame('telegram_alerts_disabled_for_studio', $alert->last_error);
        $this->assertNull($alert->telegram_chat_id);
        Http::assertNothingSent();
    }

    public function test_send_command_retries_transient_telegram_failures_then_fails(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00', config('app.timezone')));
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => false,
            'description' => 'Too Many Requests',
        ], 429)]);

        ['account' => $account, 'trainer' => $trainer] = $this->bookingFixture(ScheduleKind::PrivateLesson);
        $installation = $this->platformOwnerInstallation([
            'encrypted_token' => '123456:owner-secret',
            'is_enabled' => true,
        ]);
        TelegramChatAuthorization::factory()->for($account)->for($trainer, 'trainer')->create([
            'telegram_bot_installation_id' => $installation->id,
            'telegram_chat_id' => 'retry-chat',
        ]);
        $alert = TelegramAlert::factory()->for($account)->for($trainer)->create();

        $this->artisan('telegram-alerts:send')
            ->expectsOutput(__('app.telegram_alerts_send_command_result', [
                'processed' => 1,
                'sent' => 0,
                'retried' => 1,
                'failed' => 0,
            ]))
            ->assertSuccessful();

        $this->assertSame(TelegramAlertStatus::Pending, $alert->fresh()->status);
        $this->assertSame(1, $alert->fresh()->attempts);

        Carbon::setTestNow(Carbon::parse('2026-07-06 12:01:01', config('app.timezone')));

        $this->artisan('telegram-alerts:send')->assertSuccessful();

        $this->assertSame(TelegramAlertStatus::Pending, $alert->fresh()->status);
        $this->assertSame(2, $alert->fresh()->attempts);

        Carbon::setTestNow(Carbon::parse('2026-07-06 12:06:02', config('app.timezone')));

        $this->artisan('telegram-alerts:send')->assertSuccessful();

        $alert->refresh();

        $this->assertSame(TelegramAlertStatus::Failed, $alert->status);
        $this->assertSame(3, $alert->attempts);
        $this->assertSame('Too Many Requests', $alert->last_error);

        Carbon::setTestNow();
    }

    /**
     * @return array{account: Account, trainer: Trainer, scheduledClass: ScheduledClass, booking: ClassBooking}
     */
    private function bookingFixture(ScheduleKind $scheduleKind): array
    {
        $account = Account::factory()->create([
            'name' => 'Test Studio',
            'default_language' => 'en',
            'timezone' => 'Europe/Kyiv',
        ]);
        $location = Location::factory()->for($account)->create([
            'name' => 'Podil Studio',
            'timezone' => 'Europe/Kyiv',
        ]);
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Blue Hall']);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Iryna']);
        $classType = ClassType::factory()->for($account)->create([
            'name' => $scheduleKind === ScheduleKind::PrivateLesson ? 'Private Pole' : 'Group Choreo',
            'schedule_kind' => $scheduleKind->value,
            'default_duration_minutes' => 60,
        ]);
        $startsAt = Carbon::parse('2026-07-07 09:00:00', 'Europe/Kyiv')->timezone(config('app.timezone'));
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'title' => $classType->name,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
            ]);
        $customer = Customer::factory()->for($account)->create(['name' => 'Anna']);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass, 'scheduledClass')
            ->for($customer)
            ->create();

        return [
            'account' => $account,
            'trainer' => $trainer,
            'scheduledClass' => $scheduledClass,
            'booking' => $booking,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function platformOwnerInstallation(array $attributes = []): TelegramBotInstallation
    {
        $installation = TelegramBotInstallation::query()
            ->where('scope_type', 'platform')
            ->where('scope_id', 0)
            ->where('profile', TelegramBotProfile::Owner->value)
            ->first();

        if (! $installation) {
            return TelegramBotInstallation::factory()->platformOwner()->create($attributes);
        }

        $installation->forceFill(array_merge([
            'account_id' => null,
            'scope_type' => 'platform',
            'scope_id' => 0,
            'profile' => TelegramBotProfile::Owner->value,
            'status' => 'configured',
            'is_enabled' => true,
        ], $attributes))->save();

        return $installation->refresh();
    }
}
