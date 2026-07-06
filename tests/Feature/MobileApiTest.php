<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationScope;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\MobileDeviceToken;
use App\Models\MobileOAuthState;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use App\Support\Mobile\MobileSessionIssuer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_staff_login_returns_account_tokens_and_profile(): void
    {
        [$account] = $this->groupClassContext(['slug' => 'mobile-staff-login']);
        $owner = User::factory()->create(['email' => 'owner-mobile@example.test']);
        $account->addOwner($owner);

        $response = $this->postJson('/api/v1/mobile/auth/staff/login', [
            'email' => 'owner-mobile@example.test',
            'password' => 'password',
            'device_name' => 'Redmi Note 7',
            'platform' => 'android',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.actor.type', 'staff')
            ->assertJsonPath('data.accounts.0.account.slug', 'mobile-staff-login')
            ->assertJsonPath('data.accounts.0.role', 'owner');

        $token = (string) $response->json('data.accounts.0.token');

        $this->assertStringStartsWith('ladna_mobile_', $token);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/me')
            ->assertOk()
            ->assertJsonPath('data.account.slug', 'mobile-staff-login')
            ->assertJsonPath('data.actor.type', 'staff')
            ->assertJsonPath('data.actor.user.email', 'owner-mobile@example.test')
            ->assertJsonPath('data.actor.permissions.0', 'manage_schedule');
    }

    public function test_staff_login_rejects_platform_admins(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create(['email' => 'platform-mobile@example.test']);

        $this->postJson('/api/v1/mobile/auth/staff/login', [
            'email' => $platformAdmin->email,
            'password' => 'password',
            'platform' => 'android',
        ])->assertForbidden();
    }

    public function test_customer_email_login_returns_mobile_session(): void
    {
        [$account] = $this->groupClassContext(['slug' => 'mobile-customer-login']);
        Customer::factory()->for($account)->create([
            'email' => 'customer-mobile@example.test',
            'password' => 'secret123',
            'name' => 'Mobile Customer',
            'phone' => '+380501111111',
        ]);

        $response = $this->postJson('/api/v1/mobile/auth/customer/email-login', [
            'account_slug' => 'mobile-customer-login',
            'email' => 'customer-mobile@example.test',
            'password' => 'secret123',
            'device_name' => 'Android phone',
            'platform' => 'android',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.account.slug', 'mobile-customer-login')
            ->assertJsonPath('data.actor.type', 'customer')
            ->assertJsonPath('data.actor.customer.email', 'customer-mobile@example.test');

        $this->assertStringStartsWith('ladna_mobile_', (string) $response->json('data.token'));
    }

    public function test_mobile_google_redirect_rejects_untrusted_return_url(): void
    {
        [$account] = $this->groupClassContext(['slug' => 'mobile-google-return']);
        $this->platformIntegration('google_oauth', IntegrationCategory::Authentication->value, [
            'client_id' => 'google-client',
            'client_secret' => 'google-secret',
        ]);

        $this->getJson("/api/v1/mobile/auth/customer/google/{$account->slug}/redirect?return_url=https://evil.example/mobile")
            ->assertUnprocessable();

        $this->assertSame(0, MobileOAuthState::count());
    }

    public function test_customer_can_book_group_class_and_see_it_in_mobile_schedule(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, , , $scheduledClass] = $this->groupClassContext(
            ['slug' => 'mobile-customer-booking'],
            ['capacity' => 3, 'starts_at' => '2026-06-18 15:00:00', 'ends_at' => '2026-06-18 16:00:00'],
        );
        $customer = Customer::factory()->for($account)->create([
            'email' => 'booker-mobile@example.test',
            'password' => 'secret123',
            'name' => 'Booker',
            'phone' => '+380502222222',
        ]);
        $token = $this->customerToken($account, $customer);

        $this->withToken($token)
            ->postJson("/api/v1/mobile/classes/{$scheduledClass->id}/customer-booking", [
                'notes' => 'Booked from mobile',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'booked')
            ->assertJsonPath('data.notes', 'Booked from mobile')
            ->assertJsonPath('data.scheduled_class.id', $scheduledClass->id);

        $booking = ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->firstOrFail();

        $this->assertSame(ClassBookingStatus::Booked, $booking->status);
        $this->assertSame('customer', $booking->booked_by_actor_role);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/schedule?from=2026-06-17&to=2026-06-19')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $scheduledClass->id)
            ->assertJsonPath('data.0.booked_count', 1)
            ->assertJsonPath('data.0.available_spots', 2)
            ->assertJsonPath('data.0.customer_booking.status', 'booked');

        Carbon::setTestNow();
    }

    public function test_customer_mobile_class_show_hides_non_public_classes(): void
    {
        [$account, , , $scheduledClass] = $this->groupClassContext([
            'slug' => 'mobile-customer-private-class',
        ]);
        $scheduledClass->update(['is_public' => false]);
        $customer = Customer::factory()->for($account)->create();
        $token = $this->customerToken($account, $customer);

        $this->withToken($token)
            ->getJson("/api/v1/mobile/classes/{$scheduledClass->id}")
            ->assertNotFound();
    }

    public function test_trainer_mobile_session_cannot_access_another_trainers_class(): void
    {
        [$account, , , $scheduledClass] = $this->groupClassContext([
            'slug' => 'mobile-trainer-scope',
        ]);
        $trainerUser = User::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($trainerUser, 'user')
            ->create([
                'role' => AccountRole::Trainer->value,
                'permissions' => null,
            ]);
        Trainer::factory()->for($account)->create(['user_id' => $trainerUser->id]);
        $otherTrainer = Trainer::factory()->for($account)->create();
        $scheduledClass->update(['trainer_id' => $otherTrainer->id]);
        $customer = Customer::factory()->for($account)->create();
        $token = $this->staffToken($account, $trainerUser, AccountRole::Trainer->value);

        $this->withToken($token)
            ->getJson("/api/v1/mobile/classes/{$scheduledClass->id}")
            ->assertNotFound();

        $this->withToken($token)
            ->postJson("/api/v1/mobile/classes/{$scheduledClass->id}/staff-bookings", [
                'customer_id' => $customer->id,
            ])
            ->assertNotFound();
    }

    public function test_staff_mobile_booking_cannot_overfill_group_class_capacity(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, , , $scheduledClass] = $this->groupClassContext(
            ['slug' => 'mobile-staff-capacity'],
            ['capacity' => 1, 'starts_at' => '2026-06-18 15:00:00', 'ends_at' => '2026-06-18 16:00:00'],
        );
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $firstCustomer = Customer::factory()->for($account)->create();
        $secondCustomer = Customer::factory()->for($account)->create();
        ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass, 'scheduledClass')
            ->for($firstCustomer)
            ->create(['status' => ClassBookingStatus::Booked->value]);
        $staffToken = $this->staffToken($account, $owner);

        $this->withToken($staffToken)
            ->getJson("/api/v1/mobile/classes/{$scheduledClass->id}")
            ->assertOk()
            ->assertJsonPath('data.bookings.0.customer.id', $firstCustomer->id)
            ->assertJsonPath('data.bookings.0.status', 'booked');

        $this->withToken($staffToken)
            ->postJson("/api/v1/mobile/classes/{$scheduledClass->id}/staff-bookings", [
                'customer_id' => $secondCustomer->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_class_id');

        $this->assertSame(1, ClassBooking::whereBelongsTo($scheduledClass, 'scheduledClass')->count());

        Carbon::setTestNow();
    }

    public function test_mobile_device_token_upserts_for_active_session(): void
    {
        [$account] = $this->groupClassContext(['slug' => 'mobile-device-token']);
        $customer = Customer::factory()->for($account)->create();
        $token = $this->customerToken($account, $customer);
        $payload = [
            'provider' => 'fcm',
            'platform' => 'android',
            'token' => 'fcm-device-token-123',
            'device_name' => 'Redmi Note 7',
            'app_version' => '0.1.0',
        ];

        $first = $this->withToken($token)->postJson('/api/v1/mobile/device-tokens', $payload);
        $second = $this->withToken($token)->postJson('/api/v1/mobile/device-tokens', [
            ...$payload,
            'app_version' => '0.1.1',
        ]);

        $first->assertOk()->assertJsonPath('data.provider', 'fcm');
        $second->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, MobileDeviceToken::where('token_hash', hash('sha256', 'fcm-device-token-123'))->count());
        $this->assertSame(
            '0.1.1',
            MobileDeviceToken::where('token_hash', hash('sha256', 'fcm-device-token-123'))->firstOrFail()->app_version,
        );
    }

    public function test_mobile_profile_requires_bearer_token(): void
    {
        $this->getJson('/api/v1/mobile/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', __('app.api_token_missing'));
    }

    /**
     * @return array{0: Account, 1: Location, 2: ClassType, 3: ScheduledClass}
     */
    private function groupClassContext(array $accountAttributes = [], array $scheduledClassAttributes = []): array
    {
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            ...$accountAttributes,
        ]);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 8]);
        $direction = ActivityDirection::factory()->for($account)->create();
        $classType = ClassType::factory()
            ->for($account)
            ->for($direction, 'activityDirection')
            ->create([
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'default_capacity' => 8,
            ]);
        $trainer = Trainer::factory()->for($account)->create();
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'title' => 'Mobile Pole Beginner',
                'starts_at' => now()->addDay()->setTime(15, 0),
                'ends_at' => now()->addDay()->setTime(16, 0),
                'capacity' => 8,
                'is_public' => true,
                ...$scheduledClassAttributes,
            ]);

        return [$account, $location, $classType, $scheduledClass];
    }

    private function staffToken(Account $account, User $user, string $role = 'owner'): string
    {
        $session = app(MobileSessionIssuer::class)->issueForStaff($account, $user, $role, 'Test device', 'android');

        return (string) $session->getAttribute('plain_token');
    }

    private function customerToken(Account $account, Customer $customer): string
    {
        $session = app(MobileSessionIssuer::class)->issueForCustomer($account, $customer, 'Test device', 'android');

        return (string) $session->getAttribute('plain_token');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function platformIntegration(string $provider, string $category, array $credentials): IntegrationSetting
    {
        return IntegrationSetting::create([
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
            'provider' => $provider,
            'category' => $category,
            'is_enabled' => true,
            'credentials' => $credentials,
        ]);
    }
}
