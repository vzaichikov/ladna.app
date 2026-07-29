<?php

namespace Tests\Feature;

use App\Actions\SyncDemoStudioShowcase;
use App\Enums\AccountMode;
use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Enums\EventTicketStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\AccountAiProviderCredential;
use App\Models\AccountApiToken;
use App\Models\AccountSubscription;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventTicket;
use App\Models\IntegrationSetting;
use App\Models\ScheduledClass;
use App\Models\User;
use App\Support\DemoStudioFixture;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DemoStudioShowcaseTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_requires_the_exact_demo_account_id_and_is_a_non_mutating_dry_run(): void
    {
        $account = $this->provisionDemo();
        $before = $this->showcaseFingerprint($account);

        $this->artisan('demo-studio:showcase')
            ->expectsOutputToContain('positive --expected-account-id is required')
            ->assertFailed();
        $this->artisan('demo-studio:showcase', ['--expected-account-id' => $account->id + 1])
            ->expectsOutputToContain('Expected account #'.($account->id + 1))
            ->assertFailed();
        $this->artisan('demo-studio:showcase', ['--expected-account-id' => $account->id])
            ->expectsOutputToContain('Dry run only. No database changes were made.')
            ->assertSuccessful();

        $this->assertSame($before, $this->showcaseFingerprint($account->fresh()));
    }

    public function test_fresh_demo_provisioning_contains_closed_classes_and_full_event_ticket_lifecycle(): void
    {
        Http::fake();
        Mail::fake();
        Notification::fake();
        Queue::fake();

        $account = $this->provisionDemo();

        $this->assertContains(ScheduleKind::InternalClass->value, $account->enabled_schedule_kinds);
        $this->assertSame(3, $account->classTypes()->where('schedule_kind', ScheduleKind::InternalClass->value)->count());
        $this->assertSame(6, $account->scheduledClasses()
            ->whereHas('classType', fn ($query) => $query->where('schedule_kind', ScheduleKind::InternalClass->value))
            ->count());
        $this->assertFalse($account->scheduledClasses()
            ->whereHas('classType', fn ($query) => $query->where('schedule_kind', ScheduleKind::InternalClass->value))
            ->where('is_public', true)
            ->exists());
        $this->assertFalse($account->classBookings()
            ->whereHas('scheduledClass.classType', fn ($query) => $query->where('schedule_kind', ScheduleKind::InternalClass->value))
            ->exists());

        $this->assertSame(5, $account->events()->count());
        $this->assertSame(3, $account->events()->where('status', EventStatus::Published->value)->count());
        $this->assertSame(1, $account->events()->where('status', EventStatus::Cancelled->value)->count());
        $this->assertSame(1, $account->events()->where('status', EventStatus::Draft->value)->count());
        $this->assertSame(5, $account->eventOrders()->count());
        $this->assertSame(10, EventTicket::query()->where('account_id', $account->id)->count());
        $this->assertSame(7, EventTicket::query()->where('account_id', $account->id)->where('status', EventTicketStatus::Valid->value)->count());
        $this->assertSame(1, EventTicket::query()->where('account_id', $account->id)->where('status', EventTicketStatus::Refunded->value)->count());
        $this->assertSame(2, EventTicket::query()->where('account_id', $account->id)->where('status', EventTicketStatus::Voided->value)->count());
        $this->assertSame(4, EventTicket::query()->where('account_id', $account->id)->where('is_checked_in', true)->count());
        $this->assertSame(1, $account->eventOrders()->where('status', EventOrderStatus::RefundRequired->value)->count());
        $this->assertSame(1, $account->eventOrders()->where('status', EventOrderStatus::Refunded->value)->count());
        $this->assertTrue($account->events()
            ->where('slug', 'demo-showcase-spring-festival-2027')
            ->whereYear('starts_at', 2027)
            ->exists());
        $this->assertSame(0, $account->eventOrders()->whereNot('buyer_email', 'like', '%.example.test')->count());
        $this->assertSame(0, $account->eventOrders()->where('buyer_phone', '!=', '+380000000000')->count());

        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_second_execution_is_a_true_noop_that_preserves_ids_and_timestamps(): void
    {
        $account = $this->provisionDemo();
        $before = $this->showcaseFingerprint($account);

        $this->artisan('demo-studio:showcase', [
            '--expected-account-id' => $account->id,
            '--execute' => true,
        ])->assertSuccessful();

        $plan = app(SyncDemoStudioShowcase::class)->preview($account->id);

        $this->assertSame($before, $this->showcaseFingerprint($account->fresh()));
        $this->assertSame(0, collect($plan['resources'])->sum('create'));
        $this->assertSame(0, collect($plan['resources'])->sum('update'));
        $this->assertGreaterThan(0, collect($plan['resources'])->sum('noop'));
    }

    public function test_live_mode_membership_provider_and_identifier_collisions_fail_before_writes(): void
    {
        $account = $this->provisionDemo();
        $eventCount = $account->events()->count();

        $account->forceFill(['mode' => AccountMode::Live])->saveQuietly();
        $this->artisan('demo-studio:showcase', ['--expected-account-id' => $account->id, '--execute' => true])
            ->expectsOutputToContain('not in demo_readonly mode')
            ->assertFailed();
        $account->forceFill(['mode' => AccountMode::DemoReadonly])->saveQuietly();

        $unexpectedMember = User::factory()->create();
        $account->addOwner($unexpectedMember);
        $this->artisan('demo-studio:showcase', ['--expected-account-id' => $account->id, '--execute' => true])
            ->expectsOutputToContain('unexpected owner memberships')
            ->assertFailed();
        $account->memberships()->where('user_id', $unexpectedMember->id)->delete();

        AccountSubscription::factory()->for($account)->create();
        $this->artisan('demo-studio:showcase', ['--expected-account-id' => $account->id, '--execute' => true])
            ->expectsOutputToContain('subscription, integration, token, fiscal, or non-demo provider records')
            ->assertFailed();
        $account->subscription()->delete();

        $integration = IntegrationSetting::factory()->for($account)->create();
        $this->artisan('demo-studio:showcase', ['--expected-account-id' => $account->id, '--execute' => true])
            ->expectsOutputToContain('subscription, integration, token, fiscal, or non-demo provider records')
            ->assertFailed();
        $integration->delete();

        $providerCredential = AccountAiProviderCredential::factory()->for($account)->create();
        $this->artisan('demo-studio:showcase', ['--expected-account-id' => $account->id, '--execute' => true])
            ->expectsOutputToContain('subscription, integration, token, fiscal, or non-demo provider records')
            ->assertFailed();
        $providerCredential->delete();

        $apiToken = AccountApiToken::factory()->for($account)->create();
        $this->artisan('demo-studio:showcase', ['--expected-account-id' => $account->id, '--execute' => true])
            ->expectsOutputToContain('subscription, integration, token, fiscal, or non-demo provider records')
            ->assertFailed();
        $apiToken->delete();

        $demoOrder = EventOrder::query()->whereBelongsTo($account)->firstOrFail();
        $demoOrder->forceFill(['provider' => 'real_provider'])->saveQuietly();
        $this->artisan('demo-studio:showcase', ['--expected-account-id' => $account->id, '--execute' => true])
            ->expectsOutputToContain('subscription, integration, token, fiscal, or non-demo provider records')
            ->assertFailed();
        $demoOrder->forceFill(['provider' => DemoStudioFixture::ShowcaseEventProvider])->saveQuietly();

        $neighborOwner = User::factory()->create();
        $neighbor = Account::factory()->create();
        $neighbor->addOwner($neighborOwner);
        $demoOrder->forceFill(['account_id' => $neighbor->id])->saveQuietly();

        $this->artisan('demo-studio:showcase', ['--expected-account-id' => $account->id, '--execute' => true])
            ->expectsOutputToContain('deterministic showcase order or ticket identifier')
            ->assertFailed();

        $this->assertSame($eventCount, $account->events()->count());
    }

    public function test_injected_failure_rolls_back_every_showcase_update(): void
    {
        $account = $this->provisionDemo();
        $event = $account->events()->where('slug', 'demo-showcase-paid-workshop-2026')->sole();
        $account->forceFill([
            'schedule_kind_colors' => [
                ...$account->schedule_kind_colors,
                ScheduleKind::InternalClass->value => '#000000',
            ],
        ])->saveQuietly();
        $event->forceFill(['title' => 'Rollback probe'])->saveQuietly();
        $armed = true;

        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_contains(strtolower($query->sql), 'update `events`')) {
                throw new \RuntimeException('Synthetic showcase rollback probe.');
            }
        });

        try {
            $this->artisan('demo-studio:showcase', [
                '--expected-account-id' => $account->id,
                '--execute' => true,
            ])
                ->expectsOutputToContain('Synthetic showcase rollback probe')
                ->assertFailed();
        } finally {
            $armed = false;
        }

        $this->assertSame('#000000', $account->fresh()->schedule_kind_colors[ScheduleKind::InternalClass->value]);
        $this->assertSame('Rollback probe', $event->fresh()->title);
    }

    public function test_neighboring_live_account_fingerprint_remains_unchanged_during_demo_repair(): void
    {
        $neighborOwner = User::factory()->create();
        $neighbor = Account::factory()->create(['name' => 'Neighbor live studio']);
        $neighbor->addOwner($neighborOwner);
        $account = $this->provisionDemo();
        $before = $this->accountFingerprint($neighbor);

        $account->events()
            ->where('slug', 'demo-showcase-paid-workshop-2026')
            ->update(['title' => 'Repair me']);

        $this->artisan('demo-studio:showcase', [
            '--expected-account-id' => $account->id,
            '--execute' => true,
        ])->assertSuccessful();

        $this->assertSame($before, $this->accountFingerprint($neighbor->fresh()));
    }

    private function provisionDemo(): Account
    {
        $this->artisan('demo-studio:provision', ['--execute' => true])->assertSuccessful();

        return Account::query()->where('slug', DemoStudioFixture::AccountSlug)->sole();
    }

    private function showcaseFingerprint(Account $account): string
    {
        $payload = [
            'account' => $account->only(['id', 'slug', 'enabled_schedule_kinds', 'schedule_kind_colors', 'updated_at']),
            'class_types' => $account->classTypes()
                ->where('schedule_kind', ScheduleKind::InternalClass->value)
                ->orderBy('id')
                ->get(['id', 'slug', 'updated_at'])
                ->toArray(),
            'classes' => ScheduledClass::query()
                ->whereBelongsTo($account)
                ->whereNotNull(DemoStudioFixture::showcaseMetadataKeyPath())
                ->orderBy('id')
                ->get(['id', 'metadata', 'updated_at'])
                ->toArray(),
            'events' => Event::query()->whereBelongsTo($account)->orderBy('id')->get(['id', 'slug', 'updated_at'])->toArray(),
            'orders' => EventOrder::query()->whereBelongsTo($account)->orderBy('id')->get(['id', 'order_id', 'updated_at'])->toArray(),
            'tickets' => EventTicket::query()->where('account_id', $account->id)->orderBy('id')->get(['id', 'code', 'updated_at'])->toArray(),
        ];

        return hash('sha256', serialize($payload));
    }

    private function accountFingerprint(Account $account): string
    {
        $account = $account->fresh();
        $payload = [
            'account' => $account->getRawOriginal(),
            'membership_ids' => $account->memberships()->orderBy('id')->pluck('id')->all(),
            'customer_ids' => $account->customers()->orderBy('id')->pluck('id')->all(),
            'event_ids' => $account->events()->orderBy('id')->pluck('id')->all(),
            'scheduled_class_ids' => $account->scheduledClasses()->orderBy('id')->pluck('id')->all(),
        ];

        return hash('sha256', serialize($payload));
    }
}
