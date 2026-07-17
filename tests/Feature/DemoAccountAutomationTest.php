<?php

namespace Tests\Feature;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\CustomerNotificationStatus;
use App\Enums\ScheduleKind;
use App\Enums\SubscriptionStatus;
use App\Enums\TelegramAlertStatus;
use App\Models\Account;
use App\Models\AccountActivityLog;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPayment;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerNotification;
use App\Models\Location;
use App\Models\PeopleCounterSample;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\ScheduleSeries;
use App\Models\SubscriptionPlan;
use App\Models\TelegramAlert;
use App\Models\User;
use App\Support\AccountActivityLogSettings;
use App\Support\CustomerAuth\CustomerStudioAccess;
use App\Support\Mail\TransactionalMailDispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoAccountAutomationTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_schedule_generation_includes_demo_for_every_command_selection_path(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 07:00:00', 'UTC'));

        $globalSeries = $this->scheduleSeriesForDemo();

        $this->artisan('schedule:generate')->assertSuccessful();
        $this->assertGreaterThan(0, $globalSeries->scheduledClasses()->count());

        $accountSeries = $this->scheduleSeriesForDemo();

        $this->artisan('schedule:generate', ['--account' => $accountSeries->account_id])->assertSuccessful();
        $this->assertGreaterThan(0, $accountSeries->scheduledClasses()->count());

        $singleSeries = $this->scheduleSeriesForDemo();

        $this->artisan('schedule:generate', ['--series' => $singleSeries->id])->assertSuccessful();
        $this->assertGreaterThan(0, $singleSeries->scheduledClasses()->count());
    }

    public function test_non_schedule_batch_commands_leave_demo_business_records_unchanged(): void
    {
        $account = Account::factory()->demoReadonly()->create();
        $customer = Customer::factory()->for($account)->create();
        $plan = ClassPassPlan::factory()->for($account)->create();
        $classPass = CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->create([
                'reserved_sessions_count' => 7,
                'used_sessions_count' => 6,
            ]);
        $subscriptionPlan = SubscriptionPlan::factory()->create();
        $subscription = AccountSubscription::factory()
            ->for($account)
            ->for($subscriptionPlan, 'plan')
            ->create([
                'status' => SubscriptionStatus::Active->value,
                'ends_at' => now()->subDay(),
                'auto_renew_enabled' => false,
            ]);
        AccountActivityLogSettings::setRetentionDays(1);
        $activityLog = AccountActivityLog::factory()->for($account)->create([
            'occurred_at' => now()->subDays(2),
        ]);

        $this->artisan('class-passes:normalize')->assertSuccessful();
        $this->artisan('billing:reconcile')->assertSuccessful();
        $this->artisan('account-activity-logs:prune')->assertSuccessful();

        $this->assertSame(7, $classPass->fresh()->reserved_sessions_count);
        $this->assertSame(6, $classPass->fresh()->used_sessions_count);
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
        $this->assertModelExists($activityLog);
    }

    public function test_outbound_notification_commands_and_mail_ignore_demo_records(): void
    {
        Http::fake();
        Mail::fake();
        $account = Account::factory()->demoReadonly()->create();
        $customer = Customer::factory()->for($account)->create([
            'email' => 'customer@demo.example.test',
        ]);
        $plan = ClassPassPlan::factory()->for($account)->create();
        $classPass = CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->create();
        $notification = CustomerNotification::create([
            'account_id' => $account->id,
            'status' => CustomerNotificationStatus::Pending->value,
            'channel' => 'sms',
            'type' => 'class_reminder',
            'recipient_kind' => 'customer',
            'recipient_phone' => '+380501112233',
            'scheduled_send_at' => now()->subMinute(),
        ]);
        $alert = TelegramAlert::factory()->for($account)->create();

        $this->artisan('customer-notifications:send')->assertSuccessful();
        $this->artisan('telegram-alerts:send')->assertSuccessful();
        app(TransactionalMailDispatcher::class)->customerClassPassIssued($classPass);

        $this->assertSame(CustomerNotificationStatus::Pending, $notification->fresh()->status);
        $this->assertSame(0, $notification->fresh()->attempts);
        $this->assertSame(TelegramAlertStatus::Pending, $alert->fresh()->status);
        $this->assertSame(0, $alert->fresh()->attempts);
        Http::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_people_counter_pruning_leaves_demo_records_and_files_untouched(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00', 'UTC'));
        Storage::fake('local');
        $series = $this->scheduleSeriesForDemo();
        $scheduledClass = ScheduledClass::factory()
            ->for($series->account)
            ->for($series->location)
            ->for($series->room)
            ->for($series->classType)
            ->create([
                'starts_at' => now()->subDays(20)->subHour(),
                'ends_at' => now()->subDays(20),
            ]);
        $path = 'people-counter/a'.$series->account_id.'/r'.$series->room_id.'/2026/06/30/demo.jpg';
        Storage::disk('local')->put($path, 'demo');
        touch(Storage::disk('local')->path($path), now()->subDays(20)->getTimestamp());
        $sample = PeopleCounterSample::factory()->for($scheduledClass)->create([
            'account_id' => $series->account_id,
            'location_id' => $series->location_id,
            'room_id' => $series->room_id,
            'captured_at' => now()->subDays(20),
            'original_image_path' => $path,
            'masked_image_path' => null,
        ]);

        $this->artisan('people-counter:prune')->assertSuccessful();

        $this->assertModelExists($sample);
        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    public function test_fiscalization_command_rejects_an_explicit_demo_account(): void
    {
        Http::fake();
        $account = Account::factory()->demoReadonly()->create();

        $this->artisan('payments:fiscalize', ['account' => $account->id])
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_demo_is_hidden_from_discovery_and_metrics_but_visible_to_platform_admin(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $liveAccount = Account::factory()->create([
            'name' => 'Live Discovery Studio',
            'slug' => 'live-discovery-studio',
        ]);
        Location::factory()->for($liveAccount)->create(['is_active' => true]);
        $demoAccount = Account::factory()->demoReadonly()->create([
            'name' => 'Hidden Demo Studio',
            'slug' => 'hidden-demo-studio',
        ]);
        Location::factory()->for($demoAccount)->create(['is_active' => true]);
        $phone = '+380501112299';
        $liveCustomer = Customer::factory()->for($liveAccount)->create([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ]);
        Customer::factory()->for($demoAccount)->create(['phone' => $phone]);
        $baselineAccounts = Account::includedInMetrics()->whereKeyNot($liveAccount->id)->count();
        $baselineActiveAccounts = Account::includedInMetrics()->active()->whereKeyNot($liveAccount->id)->count();
        $livePlan = SubscriptionPlan::factory()->create(['name' => 'Live SaaS Plan']);
        $demoPlan = SubscriptionPlan::factory()->create(['name' => 'Hidden Demo SaaS Plan']);
        AccountSubscriptionPayment::factory()->for($liveAccount)->for($livePlan, 'plan')->create([
            'status' => AccountSubscriptionPaymentStatus::PaymentPaid->value,
        ]);
        AccountSubscriptionPayment::factory()->for($demoAccount)->for($demoPlan, 'plan')->create([
            'status' => AccountSubscriptionPaymentStatus::PaymentPaid->value,
        ]);

        $discoverableAccountIds = Account::publiclyDiscoverable()->pluck('id');
        $this->assertTrue($discoverableAccountIds->contains($liveAccount->id));
        $this->assertFalse($discoverableAccountIds->contains($demoAccount->id));

        $matchingCustomers = app(CustomerStudioAccess::class)->matchingCustomersFor($liveCustomer);
        $this->assertCount(1, $matchingCustomers);
        $this->assertTrue($matchingCustomers->first()->is($liveCustomer));

        $this->actingAs($platformAdmin)
            ->get(route('platform.index'))
            ->assertOk()
            ->assertViewHas('accountsCount', $baselineAccounts + 1)
            ->assertViewHas('activeAccountsCount', $baselineActiveAccounts + 1);

        $this->get(route('platform.accounts.index'))
            ->assertOk()
            ->assertSee('Hidden Demo Studio')
            ->assertSee(__('app.demo_account_badge'));

        $this->get(route('platform.payments.index'))
            ->assertOk()
            ->assertSee('Live SaaS Plan')
            ->assertDontSee('Hidden Demo SaaS Plan');
    }

    private function scheduleSeriesForDemo(): ScheduleSeries
    {
        $account = Account::factory()->demoReadonly()->create([
            'timezone' => 'UTC',
            'schedule_generation_weeks' => 1,
        ]);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'default_duration_minutes' => 60,
        ]);

        return ScheduleSeries::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'weekday' => now('UTC')->isoWeekday(),
                'start_time' => '10:00',
                'start_date' => now('UTC')->toDateString(),
            ]);
    }
}
