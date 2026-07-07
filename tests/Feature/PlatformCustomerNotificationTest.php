<?php

namespace Tests\Feature;

use App\Enums\CustomerNotificationStatus;
use App\Models\Account;
use App\Models\ClassType;
use App\Models\CustomerNotification;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlatformCustomerNotificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_can_view_and_filter_customer_notification_queue(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create([
            'name' => 'Queue Studio',
            'slug' => 'queue-studio',
        ]);
        CustomerNotification::factory()->for($account)->create([
            'recipient_name' => 'Anna Customer',
            'recipient_phone' => '+380501112233',
            'text' => 'Queue SMS text',
            'status' => CustomerNotificationStatus::Pending->value,
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('platform.customer-notifications.index', ['search' => 'Queue SMS']))
            ->assertOk()
            ->assertSee(__('app.customer_notifications_queue'), false)
            ->assertSee('Queue Studio', false)
            ->assertSee('Anna Customer', false)
            ->assertSee('Queue SMS text', false)
            ->assertSee(__('app.customer_notification_status_pending'), false);
    }

    public function test_studio_owner_cannot_view_platform_customer_notification_queue(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('platform.customer-notifications.index'))
            ->assertForbidden();
    }

    public function test_platform_customer_notification_queue_displays_studio_timezone(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create([
            'name' => 'Kyiv Queue Studio',
            'slug' => 'kyiv-queue-studio',
            'timezone' => 'Europe/Kyiv',
        ]);
        $location = Location::factory()->for($account)->create([
            'timezone' => 'Europe/Kyiv',
        ]);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Morning Pole',
                'starts_at' => Carbon::parse('2026-07-09 07:00:00', 'UTC'),
                'ends_at' => Carbon::parse('2026-07-09 08:00:00', 'UTC'),
            ]);

        CustomerNotification::factory()
            ->for($account)
            ->for($scheduledClass, 'scheduledClass')
            ->create([
                'recipient_name' => 'Timezone Customer',
                'recipient_phone' => '+380501112233',
                'text' => 'Timezone SMS text',
                'status' => CustomerNotificationStatus::Pending->value,
                'scheduled_send_at' => Carbon::parse('2026-07-09 06:00:00', 'UTC'),
                'created_at' => Carbon::parse('2026-07-07 18:30:00', 'UTC'),
            ]);

        $this->actingAs($platformAdmin)
            ->get(route('platform.customer-notifications.index', ['search' => 'Timezone SMS']))
            ->assertOk()
            ->assertSee('09.07.2026 09:00', false)
            ->assertSee('09.07.2026 10:00', false)
            ->assertSee('07.07.2026 21:30', false)
            ->assertSee('Europe/Kyiv', false)
            ->assertDontSee('09.07.2026 06:00', false);
    }
}
