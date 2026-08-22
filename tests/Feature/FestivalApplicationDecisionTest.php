<?php

namespace Tests\Feature;

use App\Actions\Festivals\ProvisionFestivalWorkflow;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalPaymentStatus;
use App\Models\Account;
use App\Models\FestivalBattleJudgeVote;
use App\Models\FestivalBattleMatch;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalNotification;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPenalty;
use App\Models\FestivalPortalUser;
use App\Models\FestivalResult;
use App\Models\FestivalRubric;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalScoreSheet;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\User;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FestivalApplicationDecisionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_full_decline_requires_a_reason_preserves_payment_history_and_keeps_late_callbacks_refund_safe(): void
    {
        [$account, $edition, $owner, $entry] = $this->festival();
        $pending = $this->charge($entry, FestivalChargeStatus::PaymentPending, 'Pending fee');
        $paid = $this->charge($entry, FestivalChargeStatus::Paid, 'Paid fee');
        $paid->forceFill(['paid_at' => now()->subHour()])->save();
        $attempt = FestivalPaymentAttempt::query()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $pending->id,
            'provider' => 'liqpay',
            'order_id' => 'DECLINE-'.$entry->id,
            'status' => FestivalPaymentStatus::Pending,
            'amount_cents' => $pending->amount_cents,
            'currency' => $pending->currency,
        ]);
        $attempt->allocations()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $pending->id,
            'amount_cents' => $pending->amount_cents,
            'currency' => $pending->currency,
        ]);

        $route = route('dashboard.accounts.festivals.applications.fully-decline', [$account, $edition, $entry]);
        $this->actingAs($owner)->patch($route, ['reason' => '   '])
            ->assertSessionHasErrors('reason');
        $this->assertSame(FestivalEntryStatus::Accepted, $entry->refresh()->status);

        $this->actingAs($owner)->patch($route, ['reason' => 'The category rules were not met.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame(FestivalEntryStatus::Rejected, $entry->status);
        $this->assertSame('The category rules were not met.', $entry->review_notes);
        $this->assertNull($entry->accepted_at);
        $this->assertNull($entry->registration_completed_at);
        $this->assertNull($entry->track_reserved_at);
        $this->assertSame(FestivalChargeStatus::Cancelled, $pending->refresh()->status);
        $this->assertNotNull($pending->cancelled_at);
        $this->assertSame(FestivalChargeStatus::PaidRequiresRefund, $paid->refresh()->status);
        $this->assertModelExists($attempt);
        $this->assertSame(1, FestivalNotification::query()
            ->where('festival_entry_id', $entry->id)
            ->where('type', FestivalNotificationType::EntryReviewed->value)
            ->count());

        app(FestivalPaymentService::class)->completeAttempt($attempt, new PaymentCallbackResult(
            orderId: $attempt->order_id,
            status: PaymentCallbackStatus::Failed,
            amountCents: $attempt->amount_cents,
            currency: $attempt->currency,
        ));
        $this->assertSame(FestivalChargeStatus::Cancelled, $pending->refresh()->status);

        app(FestivalPaymentService::class)->completeAttempt($attempt->refresh(), new PaymentCallbackResult(
            orderId: $attempt->order_id,
            status: PaymentCallbackStatus::Paid,
            amountCents: $attempt->amount_cents,
            currency: $attempt->currency,
            paidAt: now(),
        ));
        $this->assertSame(FestivalChargeStatus::PaidRequiresRefund, $pending->refresh()->status);
    }

    public function test_full_decline_is_blocked_by_every_operational_dependency_without_cascading_it(): void
    {
        [$account, $edition, $owner, $entry, $category] = $this->festival();
        $route = route('dashboard.accounts.festivals.applications.fully-decline', [$account, $edition, $entry]);
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->create(['account_id' => $account->id]);
        $rubric = FestivalRubric::factory()->for($edition)->create(['account_id' => $account->id]);

        $dependencies = [
            fn () => FestivalScheduleSlot::query()->create([
                'account_id' => $account->id,
                'festival_edition_id' => $edition->id,
                'festival_stage_id' => $stage->id,
                'festival_entry_id' => $entry->id,
                'type' => 'performance',
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addMinutes(5),
            ]),
            fn () => FestivalScoreSheet::query()->create([
                'account_id' => $account->id,
                'festival_entry_id' => $entry->id,
                'festival_judge_assignment_id' => $assignment->id,
                'festival_rubric_id' => $rubric->id,
            ]),
            fn () => FestivalPenalty::query()->create([
                'account_id' => $account->id,
                'festival_entry_id' => $entry->id,
                'points' => 1,
                'reason' => 'Operational penalty',
            ]),
            fn () => FestivalResult::query()->create([
                'account_id' => $account->id,
                'festival_edition_id' => $edition->id,
                'festival_entry_id' => $entry->id,
                'total_score' => 10,
                'publication_details' => [],
            ]),
            function () use ($entry, $category): FestivalBattleMatch {
                $otherEntry = $this->battleEntry($category);

                return FestivalBattleMatch::query()->create([
                    'account_id' => $entry->account_id,
                    'festival_edition_id' => $entry->festival_edition_id,
                    'festival_category_id' => $category->id,
                    'round' => 1,
                    'position' => 1,
                    'entry_a_id' => $entry->id,
                    'entry_b_id' => $otherEntry->id,
                    'status' => 'ready',
                ]);
            },
            function () use ($account, $edition, $entry, $category): FestivalBattleJudgeVote {
                $match = FestivalBattleMatch::query()->create([
                    'account_id' => $account->id,
                    'festival_edition_id' => $edition->id,
                    'festival_category_id' => $category->id,
                    'round' => 1,
                    'position' => 2,
                    'entry_a_id' => $this->battleEntry($category)->id,
                    'entry_b_id' => $this->battleEntry($category)->id,
                    'status' => 'ready',
                ]);

                return FestivalBattleJudgeVote::factory()->for($match, 'match')->create([
                    'account_id' => $account->id,
                    'festival_edition_id' => $edition->id,
                    'festival_category_id' => $category->id,
                    'selected_entry_id' => $entry->id,
                ]);
            },
        ];

        foreach ($dependencies as $createDependency) {
            $dependency = $createDependency();
            $this->actingAs($owner)->patch($route, ['reason' => 'Blocked decline'])
                ->assertSessionHasErrors('festival_application');
            $this->assertSame(FestivalEntryStatus::Accepted, $entry->refresh()->status);
            $this->assertTrue(method_exists($dependency, 'exists') ? $dependency->exists() : $dependency->fresh() !== null);

            if ($dependency instanceof FestivalBattleJudgeVote) {
                $match = $dependency->match;
                $dependency->delete();
                $match->delete();
            } else {
                $dependency->delete();
            }
        }
    }

    public function test_application_decision_routes_enforce_permission_and_nested_tenancy(): void
    {
        [$account, $edition, , $entry] = $this->festival();
        $unauthorized = User::factory()->create();

        $this->actingAs($unauthorized)
            ->patch(route('dashboard.accounts.festivals.applications.fully-decline', [$account, $edition, $entry]), ['reason' => 'No access'])
            ->assertForbidden();
        $this->actingAs($unauthorized)
            ->patch(route('dashboard.accounts.festivals.applications.fully-confirm', [$account, $edition, $entry]))
            ->assertForbidden();

        [$otherAccount, $otherEdition, $otherOwner] = $this->festival();
        $this->actingAs($otherOwner)
            ->patch(route('dashboard.accounts.festivals.applications.fully-decline', [$otherAccount, $otherEdition, $entry]), ['reason' => 'Wrong tenant'])
            ->assertNotFound();
        $this->actingAs($otherOwner)
            ->patch(route('dashboard.accounts.festivals.applications.fully-confirm', [$otherAccount, $otherEdition, $entry]))
            ->assertNotFound();
    }

    /** @return array{Account, FestivalEdition, User, FestivalEntry, FestivalCategory} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $workflow = app(ProvisionFestivalWorkflow::class)->execute($edition, 'Decision workflow');
        $category = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
        ]);
        $entry = FestivalEntry::factory()->for($category)->for($portalUser, 'portalUser')->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'status' => FestivalEntryStatus::Accepted,
            'accepted_at' => now()->subDay(),
            'registration_completed_at' => now()->subDay(),
            'track_artist' => 'Artist',
            'track_title' => 'Track',
            'normalized_track_key' => 'artist|track',
            'track_reserved_at' => now()->subDay(),
        ]);

        return [$account, $edition, $owner, $entry, $category];
    }

    private function charge(FestivalEntry $entry, FestivalChargeStatus $status, string $name): FestivalCharge
    {
        return $entry->charges()->create([
            'account_id' => $entry->account_id,
            'code' => 'FCH-'.str()->upper(str()->random(12)),
            'kind' => 'participation',
            'name' => $name,
            'status' => $status,
            'amount_cents' => 10000,
            'currency' => 'UAH',
        ]);
    }

    private function battleEntry(FestivalCategory $category): FestivalEntry
    {
        return FestivalEntry::factory()->create([
            'account_id' => $category->account_id,
            'festival_edition_id' => $category->festival_edition_id,
            'festival_category_id' => $category->id,
        ]);
    }
}
