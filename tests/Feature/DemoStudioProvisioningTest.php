<?php

namespace Tests\Feature;

use App\Enums\AccountMode;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\PeopleCounterSample;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassPeopleCount;
use App\Models\ScheduleSeries;
use App\Models\User;
use App\Support\DemoStudioFixture;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoStudioProvisioningTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_account_mode_defaults_and_scopes_are_explicit(): void
    {
        $live = Account::factory()->create();
        $demo = Account::factory()->demoReadonly()->create();

        $this->assertSame(AccountMode::Live, $live->mode);
        $this->assertSame(AccountMode::DemoReadonly, $demo->mode);
        $this->assertFalse($live->isReadOnlyDemo());
        $this->assertTrue($demo->isReadOnlyDemo());
        $this->assertTrue(Account::operational()->whereKey($live)->exists());
        $this->assertFalse(Account::operational()->whereKey($demo)->exists());
        $this->assertTrue(Account::eligibleForScheduleGeneration()->whereKey($demo)->exists());
        $this->assertFalse(Account::publiclyDiscoverable()->whereKey($demo)->exists());
    }

    public function test_demo_provision_command_is_a_non_mutating_dry_run_by_default(): void
    {
        $this->artisan('demo-studio:provision')
            ->expectsOutputToContain('Dry run only')
            ->assertSuccessful();

        $this->assertDatabaseMissing('accounts', ['slug' => DemoStudioFixture::AccountSlug]);
        $this->assertDatabaseMissing('users', ['email' => config('demo-studio.owner.email')]);
    }

    public function test_demo_provisioner_creates_only_synthetic_readonly_studio_data(): void
    {
        $neighborOwner = User::factory()->create();
        $neighbor = Account::factory()->create(['name' => 'Untouched studio']);
        $neighbor->addOwner($neighborOwner);
        $neighborHash = $neighborOwner->password;

        $this->artisan('demo-studio:provision', ['--execute' => true])->assertSuccessful();

        $account = Account::query()->where('slug', DemoStudioFixture::AccountSlug)->sole();
        $owner = User::query()->where('email', config('demo-studio.owner.email'))->sole();

        $this->assertTrue($account->isReadOnlyDemo());
        $this->assertTrue(Hash::check('demo', $owner->password));
        $this->assertSame(24, Customer::query()->whereBelongsTo($account)->count());
        $this->assertSame(6, $account->trainers()->count());
        $this->assertSame(12, ScheduleSeries::query()->whereBelongsTo($account)->count());
        $this->assertGreaterThan(12, ScheduledClass::query()->whereBelongsTo($account)->count());
        $this->assertGreaterThan(10, ClassBooking::query()->whereBelongsTo($account)->count());
        $this->assertSame(4, CustomerPurchase::query()->whereBelongsTo($account)->count());
        $this->assertTrue($account->allowsRtspCameras());
        $this->assertTrue($account->peopleCounterEnabled());
        $this->assertSame(2, $account->rooms()->where('rtsp_enabled', true)->count());
        $this->assertSame(
            DemoStudioFixture::PeopleCounterSampleCount,
            PeopleCounterSample::query()->whereBelongsTo($account)->count(),
        );
        $this->assertSame(6, ScheduledClassPeopleCount::query()->whereBelongsTo($account)->count());
        $this->assertTrue($account->rooms()->get()->every(
            fn ($room): bool => str_contains((string) $room->rtsp_url, '.example.test/'),
        ));
        $this->assertFalse($account->subscription()->exists());
        $this->assertFalse($account->apiTokens()->exists());
        $this->assertFalse($account->fiscalReceipts()->exists());
        $this->assertFalse($account->customerNotifications()->exists());
        $this->assertFalse($account->telegramAlerts()->exists());
        $this->assertSame($neighborHash, $neighborOwner->fresh()->password);
        $this->assertSame('Untouched studio', $neighbor->fresh()->name);

        $this->assertSame(0, Customer::query()->whereBelongsTo($account)->whereNot('email', 'like', '%.example.test')->count());
        $this->assertSame(0, $account->trainers()->whereNot('email', 'like', '%.example.test')->count());
        $this->assertSame(0, $account->trainers()->whereNotNull('photo_path')->count());
    }

    public function test_demo_camera_dashboard_and_report_use_only_synthetic_images(): void
    {
        Http::fake();
        Storage::fake('local');
        $this->artisan('demo-studio:provision', ['--execute' => true])->assertSuccessful();

        $account = Account::query()->where('slug', DemoStudioFixture::AccountSlug)->sole();
        $owner = User::query()->where('email', config('demo-studio.owner.email'))->sole();
        $sample = PeopleCounterSample::query()
            ->whereBelongsTo($account)
            ->whereNotNull('scheduled_class_id')
            ->firstOrFail();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee(__('app.people_counter_live_title'))
            ->assertSee('Лавандова зала')
            ->assertSee('Сливова студія')
            ->assertSee('data-people-counter-screenshot-trigger', false);

        $this->get(route('dashboard.accounts.cameras.index', $account))
            ->assertOk()
            ->assertSee(__('app.demo_camera_feed'))
            ->assertSee('data-demo-camera-feed', false)
            ->assertDontSee(__('app.rtsp_camera_gateway_unavailable'));

        $this->get(route('dashboard.accounts.reports.people-counter', $account))
            ->assertOk()
            ->assertSee('data-people-counter-row', false)
            ->assertSee('data-people-counter-screenshot-trigger', false);

        $this->get(route('dashboard.accounts.people-counter-samples.image', [$account, $sample, 'original']))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        Storage::disk('local')->put('people-counter/live-account/private.jpg', 'not demo data');
        $sample->update(['original_image_path' => 'people-counter/live-account/private.jpg']);

        $this->get(route('dashboard.accounts.people-counter-samples.image', [$account, $sample, 'original']))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_refresh_replaces_only_the_validated_demo_account(): void
    {
        $neighborOwner = User::factory()->create();
        $neighbor = Account::factory()->create(['name' => 'Neighbor studio']);
        $neighbor->addOwner($neighborOwner);
        $neighborOwnerHash = $neighborOwner->password;

        $this->artisan('demo-studio:provision', ['--execute' => true])->assertSuccessful();
        $firstDemoId = Account::query()->where('slug', DemoStudioFixture::AccountSlug)->value('id');

        $this->artisan('demo-studio:provision', ['--execute' => true, '--refresh' => true])->assertSuccessful();

        $refreshed = Account::query()->where('slug', DemoStudioFixture::AccountSlug)->sole();

        $this->assertNotSame($firstDemoId, $refreshed->id);
        $this->assertSame('Neighbor studio', $neighbor->fresh()->name);
        $this->assertSame($neighborOwnerHash, $neighborOwner->fresh()->password);
        $this->assertSame(1, User::query()->where('email', config('demo-studio.owner.email'))->count());
    }

    public function test_normal_schedule_generation_keeps_an_eight_week_demo_group_horizon(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 07:00:00', 'UTC'));
        $this->artisan('demo-studio:provision', ['--execute' => true])->assertSuccessful();

        $account = Account::query()->where('slug', DemoStudioFixture::AccountSlug)->sole();
        $firstHorizon = ScheduledClass::query()
            ->whereBelongsTo($account)
            ->where('is_generated', true)
            ->max('starts_at');

        $this->assertSame(8, $account->schedule_generation_weeks);
        $this->assertNotNull($firstHorizon);
        $this->assertFalse(ScheduledClass::query()
            ->whereBelongsTo($account)
            ->where('is_generated', true)
            ->whereHas('classType', fn ($query) => $query->whereNot('schedule_kind', 'group_class'))
            ->exists());

        Carbon::setTestNow(now()->addWeek());
        $this->artisan('schedule:generate', ['--account' => $account->id])->assertSuccessful();

        $nextHorizon = ScheduledClass::query()
            ->whereBelongsTo($account)
            ->where('is_generated', true)
            ->max('starts_at');

        $this->assertNotNull($nextHorizon);
        $this->assertTrue(Carbon::parse($nextHorizon)->greaterThan(Carbon::parse($firstHorizon)));
    }

    public function test_provisioner_refuses_live_slug_and_unexpected_demo_memberships(): void
    {
        $live = Account::factory()->create(['slug' => DemoStudioFixture::AccountSlug]);

        $this->artisan('demo-studio:provision', ['--execute' => true, '--refresh' => true])
            ->expectsOutputToContain('Refusing to replace a live account')
            ->assertFailed();

        $this->assertModelExists($live);
    }

    public function test_provisioner_refuses_an_unrelated_owner_email_collision(): void
    {
        $user = User::factory()->create([
            'email' => config('demo-studio.owner.email'),
        ]);

        $this->artisan('demo-studio:provision', ['--execute' => true])
            ->expectsOutputToContain('already used by an unrelated user')
            ->assertFailed();

        $this->assertModelExists($user);
        $this->assertDatabaseMissing('accounts', ['slug' => DemoStudioFixture::AccountSlug]);
    }

    public function test_refresh_refuses_unexpected_memberships_and_provider_records(): void
    {
        $this->artisan('demo-studio:provision', ['--execute' => true])->assertSuccessful();

        $account = Account::query()->where('slug', DemoStudioFixture::AccountSlug)->sole();
        $unexpectedMember = User::factory()->create();
        $account->addOwner($unexpectedMember);

        $this->artisan('demo-studio:provision', ['--execute' => true, '--refresh' => true])
            ->expectsOutputToContain('unexpected owner memberships')
            ->assertFailed();

        $account->memberships()->where('user_id', $unexpectedMember->id)->delete();
        AccountSubscription::factory()->for($account)->create();

        $this->artisan('demo-studio:provision', ['--execute' => true, '--refresh' => true])
            ->expectsOutputToContain('provider, signup, integration, token, or fiscal records')
            ->assertFailed();

        $this->assertModelExists($account);
    }

    public function test_failed_refresh_rolls_back_the_entire_demo_replacement(): void
    {
        $this->artisan('demo-studio:provision', ['--execute' => true])->assertSuccessful();

        $account = Account::query()->where('slug', DemoStudioFixture::AccountSlug)->sole();
        $owner = User::query()->where('email', config('demo-studio.owner.email'))->sole();
        $accountId = $account->id;
        $ownerHash = $owner->password;
        $customerCount = $account->customers()->count();
        $armed = true;

        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_contains(strtolower($query->sql), 'insert into `customers`')) {
                throw new \RuntimeException('Synthetic rollback probe.');
            }
        });

        try {
            $this->artisan('demo-studio:provision', ['--execute' => true, '--refresh' => true])
                ->expectsOutputToContain('Synthetic rollback probe')
                ->assertFailed();
        } finally {
            $armed = false;
        }

        $restoredAccount = Account::query()->where('slug', DemoStudioFixture::AccountSlug)->sole();

        $this->assertSame($accountId, $restoredAccount->id);
        $this->assertSame($customerCount, $restoredAccount->customers()->count());
        $this->assertSame($ownerHash, $owner->fresh()->password);
    }
}
