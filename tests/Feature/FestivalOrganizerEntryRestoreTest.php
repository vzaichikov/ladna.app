<?php

namespace Tests\Feature;

use App\Actions\Festivals\FestivalActivityRecorder;
use App\Actions\Festivals\InitializeFestivalEntryWorkflow;
use App\Actions\Festivals\ProvisionFestivalWorkflow;
use App\Enums\AccountRole;
use App\Enums\FestivalEntryStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FestivalOrganizerEntryRestoreTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_organizer_restores_a_paid_application_preserving_payment_and_workflow_facts(): void
    {
        [$account, $edition, $owner, $entry] = $this->festival();
        $charge = $entry->charges()->create([
            'account_id' => $account->id, 'code' => 'RESTORE-'.$entry->id,
            'kind' => 'participation', 'name' => 'Participation', 'status' => 'paid',
            'amount_cents' => 290000, 'currency' => 'UAH', 'paid_at' => now()->subDay(),
        ]);
        $attempt = $charge->paymentAttempts()->create([
            'account_id' => $account->id, 'provider' => 'monopay', 'order_id' => 'RESTORE-'.$entry->id,
            'status' => 'paid', 'amount_cents' => 290000, 'currency' => 'UAH', 'paid_at' => now()->subDay(),
        ]);
        $attempt->allocations()->create([
            'account_id' => $account->id, 'festival_charge_id' => $charge->id,
            'amount_cents' => 290000, 'currency' => 'UAH',
        ]);
        $entryFacts = $entry->getRawOriginal();
        $chargeFacts = $charge->refresh()->getRawOriginal();
        $attemptFacts = $attempt->refresh()->getRawOriginal();
        $steps = $entry->steps()->get()->map->getRawOriginal()->all();
        $allocations = $attempt->allocations()->get()->map->getRawOriginal()->all();
        $url = route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]);

        $this->actingAs($owner)->from($url)->patch($this->restoreUrl($account, $edition, $entry), ['reason' => 'Accidental withdrawal.'])
            ->assertRedirect($url)->assertSessionHasNoErrors();

        $this->assertSame(FestivalEntryStatus::UnderReview, $entry->refresh()->status);
        $this->assertNull($entry->withdrawn_at);
        $this->assertSame(collect($entryFacts)->except(['status', 'withdrawn_at', 'updated_at'])->all(), collect($entry->getRawOriginal())->except(['status', 'withdrawn_at', 'updated_at'])->all());
        $this->assertSame($chargeFacts, $charge->refresh()->getRawOriginal());
        $this->assertSame($attemptFacts, $attempt->refresh()->getRawOriginal());
        $this->assertSame($allocations, $attempt->allocations()->get()->map->getRawOriginal()->all());
        $this->assertSame($steps, $entry->steps()->get()->map->getRawOriginal()->all());
        $activity = $entry->activityLogs()->where('action', 'entry.restored')->sole();
        $this->assertSame($owner->id, $activity->actor_user_id);
        $this->assertSame('Accidental withdrawal.', $activity->payload['reason']);
        $this->get(route('dashboard.accounts.festivals.applications.history', [$account, $edition, $entry]))
            ->assertOk()->assertSee('Accidental withdrawal.');
        $this->patch($this->restoreUrl($account, $edition, $entry), ['reason' => 'Again'])->assertConflict();
        $this->assertSame(1, $entry->activityLogs()->where('action', 'entry.restored')->count());
    }

    #[DataProvider('previousStates')]
    public function test_restoration_recovers_the_review_state_without_granting_new_approval(string $previous, bool $legacy, string $expected): void
    {
        [$account, $edition, $owner, $entry] = $this->festival();
        if ($previous === 'draft') {
            $entry->update(['submitted_at' => null, 'qualification_status' => 'not_required']);
            $entry->steps()->update(['status' => 'draft', 'reviewed_at' => null, 'submitted_at' => null]);
        }
        if (in_array($previous, ['accepted', 'changes_pending'], true)) {
            $entry->update(['accepted_at' => now()->subDay(), 'registration_completed_at' => now()->subDay()]);
            $entry->steps()->update(['status' => 'approved', 'reviewed_at' => now()]);
        }
        if (! $legacy) {
            $entry->activityLogs()->where('action', 'entry.withdrawn')->update(['payload' => ['from_status' => $previous]]);
        }
        $this->actingAs($owner)->patch($this->restoreUrl($account, $edition, $entry), ['reason' => 'Restore saved progress.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($expected, $entry->refresh()->status->value);
    }

    public static function previousStates(): array
    {
        return [
            'legacy draft' => ['draft', true, 'draft'],
            'recorded accepted' => ['accepted', false, 'accepted'],
            'recorded pending final review' => ['changes_pending', false, 'changes_pending'],
            'legacy completed needs final review' => ['accepted', true, 'changes_pending'],
        ];
    }

    public function test_reason_permission_and_tenant_boundaries_are_enforced(): void
    {
        [$account, $edition, $owner, $entry] = $this->festival();
        $url = $this->restoreUrl($account, $edition, $entry);
        $this->actingAs($owner)->patch($url, ['reason' => '   '])->assertSessionHasErrors('reason');
        $staff = User::factory()->create();
        $account->users()->attach($staff, ['role' => AccountRole::Trainer->value, 'permissions' => [StudioPermission::ManageFestivalFinance->value]]);
        $this->actingAs($staff)->patch($url, ['reason' => 'No permission'])->assertForbidden();
        $this->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]))->assertOk()->assertDontSee($url, false);
        [$otherAccount, $otherEdition, $otherOwner] = $this->festival();
        $this->actingAs($otherOwner)->patch($this->restoreUrl($otherAccount, $otherEdition, $entry), ['reason' => 'Wrong account'])->assertNotFound();
        $this->assertSame(FestivalEntryStatus::Withdrawn, $entry->refresh()->status);
        $account->users()->updateExistingPivot($staff, ['permissions' => [StudioPermission::ManageFestivalRegistrations->value]]);
        $this->actingAs($staff->fresh())->patch($url, ['reason' => 'Authorized manager'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(FestivalEntryStatus::UnderReview, $entry->refresh()->status);
    }

    public function test_admin_action_is_visible_in_both_languages_and_works_after_registration_close(): void
    {
        [$account, $edition, $owner, $entry] = $this->festival();
        $edition->update(['registration_status' => 'closed', 'registration_closes_at' => now()->subDay()]);
        foreach (['en', 'uk'] as $locale) {
            app()->setLocale($locale);
            $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]))
                ->assertOk()->assertSee($this->restoreUrl($account, $edition, $entry), false);
        }
        $this->patch($this->restoreUrl($account, $edition, $entry), ['reason' => 'Organizer correction'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_active_participant_quota_cannot_be_bypassed_by_restoration(): void
    {
        [$account, $edition, $owner, $entry] = $this->festival();
        $edition->update(['max_entries_per_participant' => 1]);
        $other = FestivalEntry::factory()->for($entry->category)->create([
            'account_id' => $account->id, 'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $entry->festival_portal_user_id, 'status' => 'submitted',
        ]);
        $other->participants()->attach($entry->participants()->firstOrFail(), ['account_id' => $account->id]);
        $this->actingAs($owner)->patch($this->restoreUrl($account, $edition, $entry), ['reason' => 'Over quota'])->assertSessionHasErrors('participants');
        $this->assertSame(FestivalEntryStatus::Withdrawn, $entry->refresh()->status);
    }

    public function test_reversed_purchase_blocks_organizer_restore(): void
    {
        [$account, $edition, $owner, $entry] = $this->festival();
        FestivalEditionPurchase::factory()->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'status' => 'payment_reversed']);
        $this->actingAs($owner)->patch($this->restoreUrl($account, $edition, $entry), ['reason' => 'Restore'])->assertStatus(423);
        $this->assertSame(FestivalEntryStatus::Withdrawn, $entry->refresh()->status);
    }

    public function test_category_capacity_blocks_confirmed_restore_but_allows_reviewing_an_application(): void
    {
        [$account, $edition, $owner, $entry] = $this->festival();
        $entry->category->update(['maximum_accepted_entries' => 1]);
        FestivalEntry::factory()->for($entry->category)->create([
            'account_id' => $account->id, 'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $entry->festival_portal_user_id, 'status' => 'accepted',
        ]);
        $entry->activityLogs()->where('action', 'entry.withdrawn')->update(['payload' => ['from_status' => 'accepted']]);
        $this->actingAs($owner)->patch($this->restoreUrl($account, $edition, $entry), ['reason' => 'Capacity test'])->assertSessionHasErrors('festival_application');
        $this->assertSame(FestivalEntryStatus::Withdrawn, $entry->refresh()->status);
        $entry->activityLogs()->where('action', 'entry.withdrawn')->update(['payload' => ['from_status' => 'under_review']]);
        $this->patch($this->restoreUrl($account, $edition, $entry), ['reason' => 'Review only'])->assertSessionHasNoErrors();
        $this->assertSame(FestivalEntryStatus::UnderReview, $entry->refresh()->status);
    }

    public function test_prepaid_distinct_participant_quota_is_enforced(): void
    {
        [$account, $edition, $owner, $entry] = $this->festival();
        $purchase = FestivalEditionPurchase::factory()->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'status' => 'redeemed']);
        $purchase->package->update(['max_participants' => 1]);
        $otherParticipant = FestivalParticipant::factory()->create(['account_id' => $account->id, 'festival_portal_user_id' => $entry->festival_portal_user_id]);
        $other = FestivalEntry::factory()->for($entry->category)->create([
            'account_id' => $account->id, 'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $entry->festival_portal_user_id, 'status' => 'submitted',
        ]);
        $other->participants()->attach($otherParticipant, ['account_id' => $account->id]);
        $this->actingAs($owner)->patch($this->restoreUrl($account, $edition, $entry), ['reason' => 'Over package quota'])->assertSessionHasErrors('participants');
        $this->assertSame(FestivalEntryStatus::Withdrawn, $entry->refresh()->status);
    }

    #[DataProvider('reservedMusicStates')]
    public function test_submitted_music_is_reserved_again_and_a_collision_rolls_back_restoration(string $stepStatus): void
    {
        [$account, $edition, $owner, $entry] = $this->festival();
        $step = $entry->steps()->whereHas('workflowStep', fn ($query) => $query->where('type', 'form'))->firstOrFail();
        $step->update(['status' => $stepStatus, 'submitted_at' => now()->subDay()]);
        foreach (['music_artist' => 'Artist', 'music_title' => 'Song'] as $code => $value) {
            $definition = FestivalRequirementDefinition::factory()->for($edition)->create([
                'account_id' => $account->id, 'festival_workflow_step_id' => $step->festival_workflow_step_id,
                'code' => $code, 'type' => 'custom_document', 'input_type' => 'short_text', 'subject_scope' => 'entry',
            ]);
            $requirement = $step->requirements()->create([
                'account_id' => $account->id, 'festival_entry_id' => $entry->id,
                'festival_requirement_definition_id' => $definition->id, 'subject_key' => 'entry',
            ]);
            $requirement->update(['status' => 'submitted']);
            $requirement->submissions()->create([
                'account_id' => $account->id, 'festival_entry_id' => $entry->id,
                'value_json' => ['value' => $value], 'festival_portal_user_id' => $entry->festival_portal_user_id,
            ]);
        }
        $key = hash('sha256', 'artist|song');
        $conflict = FestivalEntry::factory()->for($entry->category)->create([
            'account_id' => $account->id, 'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $entry->festival_portal_user_id, 'status' => 'accepted',
            'normalized_track_key' => $key,
        ]);
        $this->actingAs($owner)->patch($this->restoreUrl($account, $edition, $entry), ['reason' => 'Music restore'])->assertSessionHasErrors('step');
        $this->assertSame(FestivalEntryStatus::Withdrawn, $entry->refresh()->status);
        $this->assertNull($entry->normalized_track_key);
        $this->assertSame(0, $entry->activityLogs()->where('action', 'entry.restored')->count());
        $conflict->update(['normalized_track_key' => null]);
        $this->patch($this->restoreUrl($account, $edition, $entry), ['reason' => 'Music available'])->assertSessionHasNoErrors();
        $this->assertSame($key, $entry->refresh()->normalized_track_key);
        $this->assertSame('Artist', $entry->track_artist);
        $this->assertSame('Song', $entry->track_title);
    }

    public static function reservedMusicStates(): array
    {
        return [['submitted'], ['approved'], ['changes_requested']];
    }

    #[DataProvider('unavailableEntries')]
    public function test_organizer_cannot_restore_ineligible_applications(string $block): void
    {
        [$account, $edition, $owner, $entry] = $this->festival();
        match ($block) {
            'rejected' => $entry->update(['rejected_at' => now()]),
            'category' => $entry->category->update(['is_active' => false]),
            'participant' => $entry->participants()->firstOrFail()->update(['archived_at' => now()]),
            'edition' => $edition->update(['status' => 'cancelled']),
            'portal' => $entry->portalUser->update(['is_active' => false]),
        };
        $this->actingAs($owner)->patch($this->restoreUrl($account, $edition, $entry), ['reason' => 'Restore'])->assertSessionHasErrors('festival_application');
        $this->assertSame(FestivalEntryStatus::Withdrawn, $entry->refresh()->status);
    }

    public static function unavailableEntries(): array
    {
        return array_map(fn (string $block): array => [$block], ['rejected', 'category', 'participant', 'edition', 'portal']);
    }

    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $edition = FestivalEdition::factory()->published()->for(FestivalSeries::factory()->for($account))->create(['account_id' => $account->id]);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $portal = FestivalPortalUser::factory()->for($account)->create();
        $workflow = app(ProvisionFestivalWorkflow::class)->execute($edition, 'Restore workflow');
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'festival_workflow_id' => $workflow->id]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_portal_user_id' => $portal->id,
            'status' => 'withdrawn', 'submitted_at' => now()->subWeek(), 'withdrawn_at' => now()->subHour(), 'qualification_status' => 'passed',
        ]);
        $participant = FestivalParticipant::factory()->for($portal)->create(['account_id' => $account->id]);
        $entry->participants()->attach($participant, ['account_id' => $account->id]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);
        $entry->steps()->whereHas('workflowStep', fn ($query) => $query->where('type', 'application'))->update(['status' => 'approved', 'submitted_at' => now()->subWeek(), 'reviewed_at' => now()->subDay()]);
        app(FestivalActivityRecorder::class)->record($entry, 'entry.withdrawn', $edition, $portal);

        return [$account, $edition, $owner, $entry->refresh()];
    }

    private function restoreUrl(Account $account, FestivalEdition $edition, FestivalEntry $entry): string
    {
        return route('dashboard.accounts.festivals.applications.restore', [$account, $edition, $entry]);
    }
}
