<?php

namespace Tests\Feature;

use App\Enums\CustomerNotificationStatus;
use App\Models\Account;
use App\Models\CustomerNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
