<?php

namespace Tests\Feature;

use App\Actions\Festivals\InitializeFestivalEntryWorkflow;
use App\Actions\Festivals\ProvisionFestivalWorkflow;
use App\Actions\Festivals\ReconcileFestivalPaymentAttempt;
use App\Actions\Festivals\SubmitFestivalEntryStep;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalPaymentStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Models\FestivalParticipant;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\IntegrationSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FestivalDelayedPaymentSubmissionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_on_time_payment_submits_the_application_after_all_registration_deadlines_pass(): void
    {
        [$attempt, $charge, $step, $paidAt] = $this->payment();

        $this->reconcile($attempt, $paidAt);

        $this->assertSame(FestivalPaymentStatus::Paid, $attempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::Paid, $charge->refresh()->status);
        $this->assertSame(FestivalEntryStepStatus::Submitted, $step->refresh()->status);
        $this->assertSame(FestivalEntryStatus::Submitted, $step->entry->status);
        $this->assertTrue($step->submitted_at->equalTo(now()));
        $this->assertTrue($step->entry->submitted_at->equalTo(now()));
        $this->assertTrue($charge->paid_at->equalTo($paidAt));

        $this->reconcile($attempt, $paidAt);
        $this->assertTrue($step->refresh()->submitted_at->equalTo(now()));
    }

    public function test_normal_submission_still_rejects_an_expired_step(): void
    {
        [, $charge, $step, $paidAt] = $this->payment();
        $charge->update(['status' => FestivalChargeStatus::Paid, 'paid_at' => $paidAt]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage(__('app.festival_step_deadline_expired'));

        app(SubmitFestivalEntryStep::class)->execute($step->entry, $step);
    }

    #[DataProvider('currentSubmissionBlocks')]
    public function test_payment_time_does_not_bypass_current_submission_safeguards(string $block): void
    {
        [$attempt, $charge, $step, $paidAt] = $this->payment();
        $entry = $step->entry;
        match ($block) {
            'registration_closed' => $entry->edition->update(['registration_status' => 'closed']),
            'edition_cancelled' => $entry->edition->update(['status' => 'cancelled']),
            'invalid_roster' => $entry->category->update(['min_members' => 2, 'max_members' => 2]),
            'withdrawn' => $entry->update(['status' => FestivalEntryStatus::Withdrawn, 'withdrawn_at' => now()]),
            'rejected' => $entry->update(['status' => FestivalEntryStatus::Rejected, 'rejected_at' => now()]),
            'reviewed_step' => $step->update(['status' => FestivalEntryStepStatus::Rejected, 'reviewed_at' => now()]),
            'participant_limit' => $this->occupyParticipantLimit($entry),
        };

        $this->reconcile($attempt, $paidAt);

        $this->assertSame(FestivalPaymentStatus::Paid, $attempt->refresh()->status);
        $this->assertSame(
            in_array($block, ['withdrawn', 'rejected'], true) ? FestivalChargeStatus::PaidRequiresRefund : FestivalChargeStatus::Paid,
            $charge->refresh()->status,
        );
        $this->assertSame($block === 'reviewed_step' ? FestivalEntryStepStatus::Rejected : FestivalEntryStepStatus::Draft, $step->refresh()->status);
        $this->assertNull($entry->refresh()->submitted_at);
    }

    /** @return array<string, array{string}> */
    public static function currentSubmissionBlocks(): array
    {
        return array_combine(
            $blocks = ['registration_closed', 'edition_cancelled', 'invalid_roster', 'withdrawn', 'rejected', 'reviewed_step', 'participant_limit'],
            array_map(fn (string $block): array => [$block], $blocks),
        );
    }

    #[DataProvider('lateChargeTimes')]
    public function test_an_older_callback_cannot_hide_a_later_or_unknown_payment_time(bool $missingPaidAt): void
    {
        [$attempt, , $step, $paidAt] = $this->payment();
        $step->charges()->create([
            'account_id' => $step->account_id,
            'festival_entry_id' => $step->festival_entry_id,
            'code' => 'FCH-DELAY-SECOND-'.$step->id,
            'kind' => 'participation',
            'name' => 'Additional currency fee',
            'status' => FestivalChargeStatus::Paid,
            'amount_cents' => 1000,
            'currency' => 'EUR',
            'paid_at' => $missingPaidAt ? null : now()->subMinute(),
        ]);

        $this->reconcile($attempt, $paidAt);

        $this->assertSame(FestivalPaymentStatus::Paid, $attempt->refresh()->status);
        $this->assertSame(FestivalEntryStepStatus::Draft, $step->refresh()->status);
        $this->assertNull($step->entry->submitted_at);
    }

    /** @return array<string, array{bool}> */
    public static function lateChargeTimes(): array
    {
        return ['late payment' => [false], 'unknown payment time' => [true]];
    }

    public function test_payment_after_the_workflow_deadline_cannot_submit_even_with_a_valid_invoice(): void
    {
        [$attempt, , $step, $paidAt] = $this->payment();
        $step->workflowStep->update(['due_at' => $paidAt->copy()->subMinute()]);

        $this->reconcile($attempt, $paidAt);

        $this->assertSame(FestivalPaymentStatus::Paid, $attempt->refresh()->status);
        $this->assertSame(FestivalEntryStepStatus::Draft, $step->refresh()->status);
    }

    public function test_a_delayed_correction_payment_uses_its_correction_deadline(): void
    {
        [$attempt, , $step, $paidAt] = $this->payment();
        $step->update(['status' => FestivalEntryStepStatus::ChangesRequested, 'correction_due_at' => $paidAt->copy()->addMinute()]);

        $this->reconcile($attempt, $paidAt);

        $this->assertSame(FestivalEntryStepStatus::Submitted, $step->refresh()->status);
        $this->assertNull($step->correction_due_at);
    }

    private function occupyParticipantLimit(FestivalEntry $entry): void
    {
        $entry->edition->update(['max_entries_per_participant' => 1]);
        $otherEntry = FestivalEntry::factory()->for($entry->category)->create([
            'account_id' => $entry->account_id,
            'festival_edition_id' => $entry->festival_edition_id,
            'festival_portal_user_id' => $entry->festival_portal_user_id,
            'status' => FestivalEntryStatus::Submitted,
            'submitted_at' => now(),
        ]);
        $otherEntry->participants()->attach($entry->participants->first()->id, ['account_id' => $entry->account_id, 'sort_order' => 0]);
    }

    private function reconcile(FestivalPaymentAttempt $attempt, Carbon $paidAt): void
    {
        Http::fake(['https://api.monobank.ua/api/merchant/invoice/status*' => Http::response([
            'invoiceId' => $attempt->gateway_invoice_id,
            'reference' => $attempt->order_id,
            'amount' => $attempt->amount_cents,
            'ccy' => 980,
            'status' => 'success',
            'modifiedDate' => $paidAt->toIso8601String(),
        ])]);

        app(ReconcileFestivalPaymentAttempt::class)->execute($attempt);
    }

    /** @return array{FestivalPaymentAttempt, FestivalCharge, FestivalEntryStep, Carbon} */
    private function payment(): array
    {
        $paidAt = now()->subHour();
        $deadline = $paidAt->copy()->addMinutes(10);
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'registration_closes_at' => $deadline,
        ]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'registration_closes_at' => $deadline]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
        ]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $entry->participants()->attach($participant->id, ['account_id' => $account->id, 'sort_order' => 0]);
        $workflow = app(ProvisionFestivalWorkflow::class)->execute($edition, 'Delayed payment submission');
        $category->update(['festival_workflow_id' => $workflow->id]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);
        $step = $entry->steps->first(fn (FestivalEntryStep $step): bool => $step->workflowStep->code === 'application');
        $step->workflowStep->update(['opens_at' => $paidAt->copy()->subHour(), 'due_at' => $deadline]);
        $charge = $entry->charges()->create([
            'account_id' => $account->id,
            'festival_entry_step_id' => $step->id,
            'code' => 'FCH-DELAY-'.$entry->id,
            'kind' => 'participation',
            'name' => 'Participation fee',
            'status' => FestivalChargeStatus::PaymentPending,
            'amount_cents' => 50000,
            'currency' => 'UAH',
            'due_at' => $deadline,
        ]);
        $attempt = FestivalPaymentAttempt::query()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $charge->id,
            'provider' => IntegrationProvider::Monopay->value,
            'order_id' => 'FCHP-DELAY-'.$charge->id,
            'amount_cents' => $charge->amount_cents,
            'currency' => $charge->currency,
            'expires_at' => $deadline,
            'gateway_invoice_id' => 'festival-delay-'.$charge->id,
        ]);
        $attempt->allocations()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $charge->id,
            'amount_cents' => $charge->amount_cents,
            'currency' => $charge->currency,
        ]);
        IntegrationSetting::factory()->forAccountScope($account)->create([
            'provider' => IntegrationProvider::Monopay,
            'category' => IntegrationCategory::Payment,
            'is_enabled' => true,
            'credentials' => ['api_token' => 'delayed-payment-test-token'],
        ]);

        return [$attempt, $charge, $step->load(['entry.edition', 'entry.category', 'entry.participants']), $paidAt];
    }
}
