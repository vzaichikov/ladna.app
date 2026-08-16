<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\EventStatus;
use App\Enums\FestivalEditionStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\Event;
use App\Models\FestivalEdition;
use App\Models\FestivalOnlineStream;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\User;
use App\Support\EventFestivalStaffAccess;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EventFestivalStaffOperationalAccessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_role_never_inherits_generic_studio_permissions(): void
    {
        [$account, $staff] = $this->staffAccount();
        $membership = $account->membershipFor($staff);

        $membership?->forceFill([
            'permissions' => [
                StudioPermission::ManageEvents->value,
                StudioPermission::ManageFestivalFinance->value,
                StudioPermission::DoorStaff->value,
            ],
        ])->save();

        $this->assertSame([], AccountRole::EventFestivalStaff->defaultPermissions());
        $this->assertFalse($membership?->fresh()->allows(StudioPermission::ManageEvents));
        $this->assertFalse($membership?->fresh()->allows(StudioPermission::ManageFestivalFinance));
        $this->assertFalse($membership?->fresh()->allows(StudioPermission::DoorStaff));
    }

    public function test_operational_window_includes_the_exact_end_and_twenty_four_hour_cutoff(): void
    {
        Carbon::setTestNow('2026-08-16 12:00:00');

        try {
            [$account, $staff] = $this->staffAccount(['enable_festivals' => true]);
            $series = FestivalSeries::factory()->for($account)->create();
            $access = app(EventFestivalStaffAccess::class);
            $moments = [
                'before_end' => [now()->addSecond(), true],
                'at_end' => [now(), true],
                'within_grace' => [now()->subDay()->addSecond(), true],
                'at_cutoff' => [now()->subDay(), true],
                'after_cutoff' => [now()->subDay()->subSecond(), false],
            ];

            foreach ($moments as $label => [$endsAt, $expected]) {
                $event = Event::factory()->published()->for($account)->create([
                    'title' => 'Event '.$label,
                    'starts_at' => now()->subDays(2),
                    'ends_at' => $endsAt,
                ]);
                $edition = FestivalEdition::factory()->published()->for($series)->create([
                    'account_id' => $account->id,
                    'title' => 'Festival '.$label,
                    'starts_at' => now()->subDays(2),
                    'ends_at' => $endsAt,
                ]);

                $this->assertSame($expected, $access->canAccessEvent($staff, $account, $event), "Event {$label} boundary did not match.");
                $this->assertSame($expected, $access->canAccessFestival($staff, $account, $edition), "Festival {$label} boundary did not match.");
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cross_studio_operational_resources_are_not_found(): void
    {
        [$account, $staff] = $this->staffAccount(['enable_festivals' => true]);
        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $otherEvent = Event::factory()->published()->for($otherAccount)->create([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $otherSeries = FestivalSeries::factory()->for($otherAccount)->create();
        $otherEdition = FestivalEdition::factory()->published()->for($otherSeries)->create([
            'account_id' => $otherAccount->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.events.scanner', [$account, $otherEvent]))
            ->assertNotFound();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.festivals.scanner', [$account, $otherEdition]))
            ->assertNotFound();
    }

    public function test_event_staff_only_lists_and_operates_published_events_within_the_grace_window(): void
    {
        [$account, $staff] = $this->staffAccount();
        $current = Event::factory()->published()->for($account)->create([
            'title' => 'Current entry event',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $grace = Event::factory()->published()->for($account)->create([
            'title' => 'Grace entry event',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subHours(23),
        ]);
        $expired = Event::factory()->published()->for($account)->create([
            'title' => 'Expired entry event',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subHours(25),
        ]);
        $draft = Event::factory()->for($account)->create([
            'title' => 'Draft entry event',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        $cancelled = Event::factory()->published()->for($account)->create([
            'title' => 'Cancelled entry event',
            'status' => EventStatus::Cancelled->value,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $archived = Event::factory()->published()->for($account)->create([
            'title' => 'Archived entry event',
            'status' => EventStatus::Archived->value,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $response = $this->actingAs($staff)
            ->get(route('dashboard.accounts.events.index', [$account, 'tab' => 'past']))
            ->assertOk()
            ->assertViewHas('tab', 'upcoming');

        $this->assertEqualsCanonicalizing(
            [$current->id, $grace->id],
            $response->viewData('events')->pluck('id')->all(),
        );
        $response
            ->assertDontSee($expired->title)
            ->assertDontSee($draft->title)
            ->assertDontSee($cancelled->title)
            ->assertDontSee($archived->title);

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.events.scanner', [$account, $current]))
            ->assertOk()
            ->assertSee(__('app.event_attendance'))
            ->assertSee('data-entrance-tools', false);

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.events.attendance', [$account, $grace]))
            ->assertOk();

        $this->actingAs($staff)
            ->getJson(route('dashboard.accounts.events.entrance.search', [$account, $current, 'q' => 'guest']))
            ->assertOk()
            ->assertJsonPath('results', []);

        $this->actingAs($staff)
            ->postJson(route('dashboard.accounts.events.entrance.cash', [$account, $current]), [])
            ->assertUnprocessable();

        $this->actingAs($staff)
            ->postJson(route('dashboard.accounts.events.entrance.card', [$account, $current]), [])
            ->assertUnprocessable();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.events.entrance.poster', [$account, $current]))
            ->assertUnprocessable();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.events.scanner', [$account, $expired]))
            ->assertForbidden();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.events.scanner', [$account, $draft]))
            ->assertForbidden();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.events.scanner', [$account, $cancelled]))
            ->assertForbidden();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.events.scanner', [$account, $archived]))
            ->assertForbidden();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.events.edit', [$account, $current]))
            ->assertForbidden();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.customers.index', $account))
            ->assertForbidden();

        $this->actingAs($staff)
            ->post(route('dashboard.accounts.working-location.update', $account))
            ->assertForbidden();
    }

    public function test_festival_staff_gets_only_the_strict_operational_surface(): void
    {
        [$account, $staff] = $this->staffAccount(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $published = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Published operational festival',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);
        $inProgress = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'In progress operational festival',
            'status' => FestivalEditionStatus::InProgress->value,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addHour(),
        ]);
        $grace = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Grace operational festival',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subHours(23),
        ]);
        $expired = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Expired operational festival',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subHours(25),
        ]);
        $completed = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Completed operational festival',
            'status' => FestivalEditionStatus::Completed->value,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addHour(),
        ]);
        $draft = FestivalEdition::factory()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Draft operational festival',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
        ]);
        $cancelled = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Cancelled operational festival',
            'status' => FestivalEditionStatus::Cancelled->value,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $archived = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Archived operational festival',
            'status' => FestivalEditionStatus::Archived->value,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $response = $this->actingAs($staff)
            ->get(route('dashboard.accounts.festivals.index', $account))
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$published->id, $inProgress->id, $grace->id],
            $response->viewData('editions')->pluck('id')->all(),
        );
        $response
            ->assertSee(__('app.festival_staff_scanner'))
            ->assertSee(__('app.festival_staff_entrance_monitor'))
            ->assertSee(__('app.festival_staff_live_timeline'))
            ->assertSee(__('app.festival_staff_online_translation'))
            ->assertDontSee($expired->title)
            ->assertDontSee($completed->title)
            ->assertDontSee($draft->title)
            ->assertDontSee($cancelled->title)
            ->assertDontSee($archived->title);

        $scannerResponse = $this->actingAs($staff)
            ->get(route('dashboard.accounts.festivals.scanner', [$account, $published]))
            ->assertOk()
            ->assertViewHas('workspacePermissions', fn (array $permissions): bool => ! $permissions['schedule']
                && ! $permissions['finance']
                && $permissions['timeline_operator']
                && $permissions['stream_administrator'])
            ->assertSee('data-entrance-tools', false);

        $scannerResponse
            ->assertSee(route('dashboard.accounts.festivals.scanner', [$account, $published]), false)
            ->assertSee(route('dashboard.accounts.festivals.attendance', [$account, $published]), false)
            ->assertSee(route('dashboard.accounts.festivals.timeline.index', [$account, $published]), false)
            ->assertSee(route('dashboard.accounts.festivals.online-stream.edit', [$account, $published]), false)
            ->assertDontSee('href="'.route('dashboard.accounts.festivals.show', [$account, $published]).'"', false)
            ->assertDontSee(route('dashboard.accounts.festivals.program', [$account, $published]), false)
            ->assertDontSee(route('dashboard.accounts.festivals.tickets', [$account, $published]), false)
            ->assertDontSee(route('dashboard.accounts.festivals.settings.stages', [$account, $published]), false);

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.festivals.attendance', [$account, $published]))
            ->assertOk();

        $this->actingAs($staff)
            ->getJson(route('dashboard.accounts.festivals.entrance.search', [$account, $published, 'q' => 'guest']))
            ->assertOk()
            ->assertJsonPath('results', []);

        $this->actingAs($staff)
            ->postJson(route('dashboard.accounts.festivals.entrance.cash', [$account, $published]), [])
            ->assertUnprocessable();

        $this->actingAs($staff)
            ->postJson(route('dashboard.accounts.festivals.entrance.card', [$account, $published]), [])
            ->assertUnprocessable();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.festivals.entrance.poster', [$account, $published]))
            ->assertUnprocessable();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.festivals.show', [$account, $published]))
            ->assertForbidden();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.festivals.scanner', [$account, $expired]))
            ->assertForbidden();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.festivals.scanner', [$account, $cancelled]))
            ->assertForbidden();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.festivals.scanner', [$account, $archived]))
            ->assertForbidden();

        $stage = FestivalStage::factory()->for($published)->create([
            'account_id' => $account->id,
            'name' => 'Operations stage',
            'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.festivals.timeline.index', [$account, $published]))
            ->assertRedirect(route('dashboard.accounts.festivals.timeline.show', [$account, $published, $stage]));

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.festivals.timeline.show', [$account, $published, $stage]))
            ->assertOk()
            ->assertDontSee(__('app.festival_tab_program'))
            ->assertDontSee(__('app.festival_timeline_fill'));

        $this->actingAs($staff)
            ->post(route('dashboard.accounts.festivals.timeline.fill', [$account, $published]))
            ->assertForbidden();

        $this->actingAs($staff)
            ->post(route('dashboard.accounts.festivals.timeline.start', [$account, $published]))
            ->assertForbidden();

        $this->actingAs($staff)
            ->patchJson(route('dashboard.accounts.festivals.timeline.reorder', [$account, $published, $stage]), [])
            ->assertUnprocessable();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.festivals.online-stream.edit', [$account, $published]))
            ->assertOk()
            ->assertSee(__('app.festival_stream_settings'));

        $this->actingAs($staff)
            ->from(route('dashboard.accounts.festivals.online-stream.edit', [$account, $published]))
            ->put(route('dashboard.accounts.festivals.online-stream.update', [$account, $published]), [])
            ->assertRedirect()
            ->assertSessionHasErrors(['provider']);

        $stream = FestivalOnlineStream::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $published->id,
        ]);
        $previousPublisherTokenHash = $stream->publisher_token_hash;

        $this->actingAs($staff)
            ->put(route('dashboard.accounts.festivals.online-stream.update', [$account, $published]), [
                'is_enabled' => false,
                'provider' => 'mediamtx',
                'rotate_publisher_token' => true,
            ])
            ->assertRedirect(route('dashboard.accounts.festivals.online-stream.edit', [$account, $published]));

        $this->assertNotSame($previousPublisherTokenHash, $stream->refresh()->publisher_token_hash);

        $this->actingAs($staff)
            ->getJson(route('dashboard.accounts.festivals.online-stream.status', [$account, $published]))
            ->assertOk();

        $this->actingAs($staff)
            ->patch(route('dashboard.accounts.festivals.online-stream.start', [$account, $published]))
            ->assertSessionHasErrors('stream');

        $this->actingAs($staff)
            ->patch(route('dashboard.accounts.festivals.online-stream.stop', [$account, $published]))
            ->assertRedirect();

        $this->actingAs($staff)
            ->delete(route('dashboard.accounts.festivals.online-stream.reset-leases', [$account, $published]))
            ->assertRedirect();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.festivals.online-stream.preview', [$account, $published]))
            ->assertStatus(409);
    }

    /** @param array<string, mixed> $accountAttributes
     * @return array{Account, User}
     */
    private function staffAccount(array $accountAttributes = []): array
    {
        $account = Account::factory()->create($accountAttributes);
        $staff = User::factory()->create();
        $account->users()->attach($staff, [
            'role' => AccountRole::EventFestivalStaff->value,
            'permissions' => null,
        ]);

        return [$account, $staff];
    }
}
