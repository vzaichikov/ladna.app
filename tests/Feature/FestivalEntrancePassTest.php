<?php

namespace Tests\Feature;

use App\Actions\Festivals\FestivalTicketScanner;
use App\Actions\Festivals\ReconcileFestivalEntrancePasses;
use App\Enums\FestivalEntrancePassStatus;
use App\Enums\FestivalNotificationStatus;
use App\Jobs\SendFestivalNotification;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntrancePass;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Models\FestivalSubmission;
use App\Models\User;
use App\Support\ScheduledTaskRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FestivalEntrancePassTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
    }

    public function test_reconciliation_issues_performer_and_enabled_helper_passes_without_consuming_tickets(): void
    {
        [$account, $edition, $portalUser, $entry] = $this->acceptedEntry();
        $performer = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $entry->participants()->attach($performer->id, ['account_id' => $account->id, 'sort_order' => 0]);
        $helper = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'member_type' => 'helper',
            'first_name' => 'Helpful',
        ]);
        $this->selectHelper($entry, $helper);

        $result = app(ReconcileFestivalEntrancePasses::class)->reconcileEdition($edition);

        $this->assertSame(['created' => 2, 'reactivated' => 0, 'disabled' => 0], $result);
        $this->assertSame(2, $edition->entrancePasses()->count());
        $this->assertSame(0, $edition->tickets()->count());
        $this->assertDatabaseHas('festival_entrance_passes', ['festival_participant_id' => $performer->id, 'status' => FestivalEntrancePassStatus::Valid->value]);
        $this->assertDatabaseHas('festival_entrance_passes', ['festival_participant_id' => $helper->id, 'status' => FestivalEntrancePassStatus::Valid->value]);
    }

    public function test_reconciliation_disables_and_rotates_reactivated_passes_but_never_touches_past_editions(): void
    {
        [, $edition, , $entry, $participant] = $this->acceptedPerformer();
        $reconcile = app(ReconcileFestivalEntrancePasses::class);
        $reconcile->reconcileEdition($edition);
        $pass = $participant->entrancePasses()->firstOrFail();
        $originalTokenHash = $pass->token_hash;

        $entry->update(['status' => 'withdrawn']);
        $this->assertSame(1, $reconcile->reconcileEdition($edition)['disabled']);
        $this->assertSame(FestivalEntrancePassStatus::Disabled, $pass->refresh()->status);
        $this->assertFalse($pass->is_checked_in);

        $entry->update(['status' => 'accepted']);
        $this->assertSame(1, $reconcile->reconcileEdition($edition)['reactivated']);
        $this->assertNotSame($originalTokenHash, $pass->refresh()->token_hash);
        $this->assertSame(FestivalEntrancePassStatus::Valid, $pass->status);
        $this->assertNotNull($pass->credentials_rotated_at);

        $pass->update(['is_checked_in' => true, 'checked_in_at' => now()]);
        $entry->update(['status' => 'withdrawn']);
        $edition->update(['ends_at' => now()->subMinute()]);

        $this->assertSame(['created' => 0, 'reactivated' => 0, 'disabled' => 0], $reconcile->reconcileEdition($edition->refresh()));
        $this->assertSame(FestivalEntrancePassStatus::Valid, $pass->refresh()->status);
        $this->assertTrue($pass->is_checked_in);
    }

    public function test_scanner_rechecks_live_eligibility_and_preserves_two_phase_admission_audit(): void
    {
        [, $edition, , $entry, $participant] = $this->acceptedPerformer();
        app(ReconcileFestivalEntrancePasses::class)->reconcileEdition($edition);
        $pass = $participant->entrancePasses()->firstOrFail();
        $actor = User::factory()->create();
        $scanner = app(FestivalTicketScanner::class);

        $preview = $scanner->checkIn($edition, $pass->token_encrypted, $actor, 'qr', '127.0.0.1');
        $this->assertSame('awaiting_confirmation', $preview['state']);
        $this->assertSame('participant_pass', $preview['ticket']['kind']);
        $this->assertFalse($pass->refresh()->is_checked_in);

        $confirmed = $scanner->checkIn($edition, $pass->token_encrypted, $actor, 'qr', '127.0.0.1', true);
        $this->assertSame('checked_in', $confirmed['state']);
        $this->assertDatabaseHas('festival_entrance_pass_scans', ['festival_entrance_pass_id' => $pass->id, 'action' => 'check_in']);

        $entry->update(['status' => 'withdrawn']);
        $this->assertSame('void', $scanner->checkIn($edition, $pass->code, $actor, 'manual', null)['state']);
    }

    public function test_scheduler_runs_reconciliation_once_daily_on_one_server(): void
    {
        $definition = collect(app(ScheduledTaskRegistry::class)->definitions())->firstWhere('key', 'festival_entrance_passes_reconcile');

        $this->assertNotNull($definition);
        $this->assertSame('festival-entrance-passes:reconcile', $definition['command']);
        $this->assertSame('0 2 * * *', $definition['expression']);
        $this->assertSame(30, $definition['overlap_minutes']);
        $this->assertTrue($definition['single_server']);
    }

    public function test_queued_pass_email_is_cancelled_if_the_edition_becomes_invalid(): void
    {
        [, $edition, $portalUser] = $this->acceptedPerformer();
        app(ReconcileFestivalEntrancePasses::class)->reconcileEdition($edition);
        $notification = $portalUser->festivalNotifications()->latest('id')->firstOrFail();

        $edition->update(['status' => 'cancelled']);
        app()->call([(new SendFestivalNotification($notification->id)), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Cancelled, $notification->refresh()->status);
        Mail::assertNothingSent();
    }

    public function test_entrance_search_and_monitor_label_participant_passes_without_changing_guest_counters(): void
    {
        [$account, $edition, , , $participant] = $this->acceptedPerformer();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        app(ReconcileFestivalEntrancePasses::class)->reconcileEdition($edition);
        $pass = $participant->entrancePasses()->firstOrFail();

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.festivals.entrance.search', [$account, $edition, 'q' => $pass->code]))
            ->assertOk()
            ->assertJsonPath('results.0.credentials.0.kind', 'participant_pass')
            ->assertJsonPath('results.0.credentials.0.type', __('app.festival_participant_pass'));

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.festivals.attendance.data', [$account, $edition]))
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('guest_tickets.total', 0)
            ->assertJsonPath('participants.total', 1)
            ->assertJsonPath('helpers.total', 0)
            ->assertJsonPath('credentials.0.kind', 'participant_pass');
    }

    public function test_monitor_can_undo_a_participant_pass_without_escaping_the_edition_scope(): void
    {
        [$account, $edition, , , $participant] = $this->acceptedPerformer();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        app(ReconcileFestivalEntrancePasses::class)->reconcileEdition($edition);
        $pass = $participant->entrancePasses()->firstOrFail();
        app(FestivalTicketScanner::class)->checkIn($edition, $pass->code, $owner, 'manual', '203.0.113.20', true);

        $this->actingAs($owner)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
            ->postJson(route('dashboard.accounts.festivals.attendance.passes.undo', [$account, $edition, $pass]), [
                'reason' => 'Operator admitted the adjacent participant by mistake.',
            ])
            ->assertOk()
            ->assertJsonPath('state', 'checked_out');

        $this->assertFalse($pass->refresh()->is_checked_in);
        $this->assertNull($pass->checked_in_at);
        $this->assertDatabaseHas('festival_entrance_pass_scans', [
            'festival_entrance_pass_id' => $pass->id,
            'action' => 'check_out',
            'source' => 'monitor',
            'request_ip' => '203.0.113.21',
            'reason' => 'Operator admitted the adjacent participant by mistake.',
        ]);

        $otherEdition = FestivalEdition::factory()->published()->for(FestivalSeries::factory()->for($account))->create([
            'account_id' => $account->id,
        ]);
        $otherPass = FestivalEntrancePass::factory()->for($otherEdition, 'edition')->create();

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.festivals.attendance.passes.undo', [$account, $edition, $otherPass]), [
                'reason' => 'This credential belongs to another edition.',
            ])
            ->assertNotFound();
    }

    /** @return array{Account, FestivalEdition, FestivalPortalUser, FestivalEntry} */
    private function acceptedEntry(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $edition = FestivalEdition::factory()->published()->for(FestivalSeries::factory()->for($account))->create(['account_id' => $account->id]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => 'accepted',
        ]);

        return [$account, $edition, $portalUser, $entry];
    }

    /** @return array{Account, FestivalEdition, FestivalPortalUser, FestivalEntry, FestivalParticipant} */
    private function acceptedPerformer(): array
    {
        [$account, $edition, $portalUser, $entry] = $this->acceptedEntry();
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $entry->participants()->attach($participant->id, ['account_id' => $account->id, 'sort_order' => 0]);

        return [$account, $edition, $portalUser, $entry, $participant];
    }

    private function selectHelper(FestivalEntry $entry, FestivalParticipant $helper): void
    {
        $definition = FestivalRequirementDefinition::factory()->for($entry->edition)->create([
            'account_id' => $entry->account_id,
            'type' => 'helper_selection',
            'input_type' => 'helper_selection',
        ]);
        $requirement = FestivalEntryRequirement::query()->create([
            'account_id' => $entry->account_id,
            'festival_entry_id' => $entry->id,
            'festival_requirement_definition_id' => $definition->id,
            'status' => 'accepted',
            'definition_snapshot' => [],
        ]);
        $requirement->selectedHelpers()->attach($helper->id, ['sort_order' => 0]);
        FestivalSubmission::query()->create([
            'account_id' => $entry->account_id,
            'festival_entry_id' => $entry->id,
            'festival_entry_requirement_id' => $requirement->id,
            'festival_portal_user_id' => $entry->festival_portal_user_id,
            'value_json' => ['value' => ['enabled' => true]],
            'status' => 'submitted',
        ]);
    }
}
