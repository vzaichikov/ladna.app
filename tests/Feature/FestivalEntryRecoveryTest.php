<?php

namespace Tests\Feature;

use App\Actions\Festivals\FestivalActivityRecorder;
use App\Actions\Festivals\InitializeFestivalEntryWorkflow;
use App\Actions\Festivals\ReconcileFestivalPaymentAttempt;
use App\Actions\Festivals\RestoreFestivalEntry;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalPaymentStatus;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalParticipant;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\Payments\PaymentGatewayException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FestivalEntryRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_unpaid_withdrawn_draft_can_be_restored_without_replacing_its_saved_data(): void
    {
        [$entry, $portalUser, $charge] = $this->festival();
        $originalEntry = $entry->getRawOriginal();
        $originalSteps = $entry->steps()->get()->map->getRawOriginal()->all();
        $originalCharge = $charge->refresh()->getRawOriginal();
        $originalParticipants = $entry->participants()->pluck('festival_participants.id')->all();

        $action = app(RestoreFestivalEntry::class);
        $this->assertTrue($action->eligible($entry));
        $restored = $action->execute($entry, $portalUser);

        $this->assertSame(FestivalEntryStatus::Draft, $restored->status);
        $this->assertNull($restored->withdrawn_at);
        $this->assertSame(collect($originalEntry)->except(['status', 'withdrawn_at', 'updated_at'])->all(), collect($restored->getRawOriginal())->except(['status', 'withdrawn_at', 'updated_at'])->all());
        $this->assertSame($originalCharge, $charge->refresh()->getRawOriginal());
        $this->assertSame($originalSteps, $entry->steps()->get()->map->getRawOriginal()->all());
        $this->assertSame($originalParticipants, $entry->participants()->pluck('festival_participants.id')->all());
        $this->assertNull($restored->track_artist);
        $this->assertNull($restored->normalized_track_key);
        $this->assertSame(['entry.withdrawn', 'entry.restored'], $restored->activityLogs()->orderBy('id')->pluck('action')->all());
        $this->assertSame($portalUser->id, $restored->activityLogs()->where('action', 'entry.restored')->firstOrFail()->actor_portal_user_id);
        Http::assertNothingSent();
    }

    public function test_expired_invoice_is_verified_before_restoring_and_keeps_its_payment_history(): void
    {
        [$entry, $portalUser, $charge] = $this->festival();
        $attempt = $this->attempt($charge);
        $allocation = $attempt->allocations()->firstOrFail()->getRawOriginal();
        $this->mock(ReconcileFestivalPaymentAttempt::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->once()->andReturnUsing(fn (FestivalPaymentAttempt $attempt): FestivalPaymentAttempt => $this->completeAttempt($attempt, PaymentCallbackStatus::Expired, 'expired'));
        });

        $action = app(RestoreFestivalEntry::class);
        $this->assertTrue($action->eligible($entry));
        $action->execute($entry, $portalUser);

        $this->assertSame(FestivalEntryStatus::Draft, $entry->refresh()->status);
        $this->assertSame(FestivalPaymentStatus::Expired, $attempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::Failed, $charge->refresh()->status);
        $this->assertSame(50000, $attempt->amount_cents);
        $this->assertSame(50000, $charge->amount_cents);
        $this->assertSame($allocation, $attempt->allocations()->firstOrFail()->getRawOriginal());
        $this->assertSame(1, $entry->charges()->count());
        $this->assertSame(1, $charge->paymentAttempts()->count());
    }

    #[DataProvider('waitingInvoiceStatuses')]
    public function test_waiting_or_unavailable_gateway_never_restores_a_withdrawn_entry(string $gatewayStatus): void
    {
        [$entry, $portalUser, $charge] = $this->festival();
        $attempt = $this->attempt($charge);
        $attempt->update(['status' => FestivalPaymentStatus::Expired]);
        $this->mock(ReconcileFestivalPaymentAttempt::class, function (MockInterface $mock) use ($gatewayStatus): void {
            if ($gatewayStatus === 'unavailable') {
                $mock->shouldReceive('execute')->once()->andThrow(new PaymentGatewayException('Status temporarily unavailable.'));

                return;
            }

            $mock->shouldReceive('execute')->once()->andReturnUsing(fn (FestivalPaymentAttempt $attempt): FestivalPaymentAttempt => $this->completeAttempt($attempt, PaymentCallbackStatus::Pending, $gatewayStatus));
        });

        $this->assertRestoreRejected($entry, $portalUser);
        $this->assertNull($charge->refresh()->paid_at);
    }

    /** @return array<string, array{string}> */
    public static function waitingInvoiceStatuses(): array
    {
        return ['created' => ['created'], 'processing' => ['processing'], 'hold' => ['hold'], 'gateway unavailable' => ['unavailable']];
    }

    public function test_success_discovered_at_the_bank_blocks_restoration_and_preserves_paid_evidence(): void
    {
        [$entry, $portalUser, $charge] = $this->festival();
        $attempt = $this->attempt($charge);
        $this->mock(ReconcileFestivalPaymentAttempt::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->once()->andReturnUsing(fn (FestivalPaymentAttempt $attempt): FestivalPaymentAttempt => $this->completeAttempt($attempt, PaymentCallbackStatus::Paid, 'success'));
        });

        $this->assertRestoreRejected($entry, $portalUser);
        $this->assertSame(FestivalPaymentStatus::Paid, $attempt->refresh()->status);
        $this->assertNotNull($charge->refresh()->paid_at);
    }

    public function test_every_pending_attempt_must_be_confirmed_unpaid(): void
    {
        [$entry, $portalUser, $charge] = $this->festival();
        $first = $this->attempt($charge);
        $second = $this->attempt($charge);
        $this->mock(ReconcileFestivalPaymentAttempt::class, function (MockInterface $mock) use ($first, $second): void {
            $mock->shouldReceive('execute')->once()->withArgs(fn (FestivalPaymentAttempt $attempt): bool => $attempt->is($first))
                ->andReturnUsing(fn (FestivalPaymentAttempt $attempt): FestivalPaymentAttempt => $this->completeAttempt($attempt, PaymentCallbackStatus::Expired, 'expired'));
            $mock->shouldReceive('execute')->once()->withArgs(fn (FestivalPaymentAttempt $attempt): bool => $attempt->is($second))
                ->andReturnUsing(fn (FestivalPaymentAttempt $attempt): FestivalPaymentAttempt => $this->completeAttempt($attempt, PaymentCallbackStatus::Pending, 'processing'));
        });

        $this->assertRestoreRejected($entry, $portalUser);
        $this->assertSame(FestivalPaymentStatus::Expired, $first->refresh()->status);
        $this->assertSame(FestivalPaymentStatus::Pending, $second->refresh()->status);
    }

    public function test_concurrent_paid_change_is_rechecked_after_gateway_lookup(): void
    {
        [$entry, $portalUser, $charge] = $this->festival();
        $this->attempt($charge);
        $this->mock(ReconcileFestivalPaymentAttempt::class, function (MockInterface $mock) use ($charge): void {
            $mock->shouldReceive('execute')->once()->andReturnUsing(function (FestivalPaymentAttempt $attempt) use ($charge): FestivalPaymentAttempt {
                $confirmed = $this->completeAttempt($attempt, PaymentCallbackStatus::Expired, 'expired');
                $charge->forceFill(['status' => FestivalChargeStatus::Paid, 'paid_at' => now()])->save();

                return $confirmed;
            });
        });

        $this->assertRestoreRejected($entry, $portalUser);
        $this->assertSame(FestivalChargeStatus::Paid, $charge->refresh()->status);
    }

    public function test_repeated_restore_does_not_create_duplicate_history(): void
    {
        [$entry, $portalUser] = $this->festival();
        app(RestoreFestivalEntry::class)->execute($entry, $portalUser);

        try {
            app(RestoreFestivalEntry::class)->execute($entry, $portalUser);
            $this->fail('An already restored entry must not be restored again.');
        } catch (ValidationException) {
            $this->assertSame(FestivalEntryStatus::Draft, $entry->refresh()->status);
            $this->assertSame(1, $entry->activityLogs()->where('action', 'entry.restored')->count());
        }
    }

    #[DataProvider('closedEntryFacts')]
    public function test_submitted_reviewed_and_final_entries_cannot_be_restored(array $attributes): void
    {
        [$entry, $portalUser] = $this->festival();
        foreach ($attributes as $key => $value) {
            $attributes[$key] = $value === 'timestamp' ? now() : $value;
        }
        $entry->update($attributes);

        $this->assertFalse(app(RestoreFestivalEntry::class)->eligible($entry));
        $this->assertRestoreRejected($entry, $portalUser);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function closedEntryFacts(): array
    {
        return [
            'submitted before withdrawal' => [['submitted_at' => 'timestamp']],
            'reviewed before withdrawal' => [['reviewed_at' => 'timestamp']],
            'accepted before withdrawal' => [['accepted_at' => 'timestamp']],
            'completed before withdrawal' => [['registration_completed_at' => 'timestamp']],
            'rejected before withdrawal' => [['rejected_at' => 'timestamp']],
            'rejected' => [['status' => 'rejected']],
            'accepted' => [['status' => 'accepted']],
            'submitted' => [['status' => 'submitted']],
            'under review' => [['status' => 'under_review']],
        ];
    }

    #[DataProvider('paidChargeStatuses')]
    public function test_paid_manual_or_refunded_charges_cannot_be_restored(string $status): void
    {
        [$entry, $portalUser, $charge] = $this->festival();
        $charge->update(['status' => $status]);
        $this->assertFalse(app(RestoreFestivalEntry::class)->eligible($entry->refresh()));
        $this->assertRestoreRejected($entry, $portalUser);
    }

    /** @return array<string, array{string}> */
    public static function paidChargeStatuses(): array
    {
        return ['paid or manually settled' => ['paid'], 'refund due' => ['paid_requires_refund'], 'refunded' => ['refunded']];
    }

    public function test_paid_attempt_history_blocks_restore_even_when_charge_status_is_stale(): void
    {
        [$entry, $portalUser, $charge] = $this->festival();
        $attempt = $this->attempt($charge);
        $attempt->update(['status' => FestivalPaymentStatus::Paid, 'paid_at' => now()]);
        $charge->update(['status' => FestivalChargeStatus::Pending]);

        $this->assertRestoreRejected($entry, $portalUser);
    }

    public function test_non_draft_operational_step_blocks_restore(): void
    {
        [$entry, $portalUser] = $this->festival();
        $entry->steps()->firstOrFail()->update(['status' => FestivalEntryStepStatus::Submitted, 'submitted_at' => now()]);

        $this->assertRestoreRejected($entry, $portalUser);
    }

    #[DataProvider('invalidRegistrationStates')]
    public function test_current_registration_and_participant_constraints_are_rechecked(string $condition): void
    {
        [$entry, $portalUser] = $this->festival();
        match ($condition) {
            'closed' => $entry->edition->update(['registration_status' => 'closed']),
            'deadline' => $entry->category->update(['registration_closes_at' => now()->subMinute()]),
            'inactive category' => $entry->category->update(['is_active' => false]),
            'inactive participant' => $entry->participants()->firstOrFail()->update(['archived_at' => now()]),
            'wrong participant owner' => $entry->participants()->firstOrFail()->update(['festival_portal_user_id' => FestivalPortalUser::factory()->create(['account_id' => $entry->account_id])->id]),
            'participant count' => $entry->category->update(['min_members' => 2, 'max_members' => 2]),
            'inactive owner' => $portalUser->update(['is_active' => false]),
            'disabled module' => $entry->account->update(['enable_festivals' => false]),
        };

        $this->assertRestoreRejected($entry, $portalUser);
    }

    /** @return array<string, array{string}> */
    public static function invalidRegistrationStates(): array
    {
        return collect(['closed', 'deadline', 'inactive category', 'inactive participant', 'wrong participant owner', 'participant count', 'inactive owner', 'disabled module'])
            ->mapWithKeys(fn (string $condition): array => [$condition => [$condition]])->all();
    }

    public function test_unsupported_unresolved_gateway_cannot_be_restored(): void
    {
        [$entry, $portalUser, $charge] = $this->festival();
        $this->attempt($charge)->update(['provider' => 'liqpay']);
        $this->mock(ReconcileFestivalPaymentAttempt::class, fn (MockInterface $mock) => $mock->shouldNotReceive('execute'));

        $this->assertRestoreRejected($entry, $portalUser);
    }

    #[DataProvider('ownershipBoundaries')]
    public function test_different_portal_owner_and_account_cannot_restore_entry(bool $sameAccount): void
    {
        [$entry] = $this->festival();
        $otherOwner = FestivalPortalUser::factory()->create(['account_id' => $sameAccount ? $entry->account_id : Account::factory()->create(['enable_festivals' => true])->id]);

        $this->expectException(ModelNotFoundException::class);
        app(RestoreFestivalEntry::class)->execute($entry, $otherOwner);
    }

    /** @return array<string, array{bool}> */
    public static function ownershipBoundaries(): array
    {
        return ['other owner in same studio' => [true], 'different studio' => [false]];
    }

    public function test_reversed_edition_entitlement_blocks_restore_before_gateway_lookup(): void
    {
        [$entry, $portalUser] = $this->festival();
        FestivalEditionPurchase::factory()->create(['account_id' => $entry->account_id, 'festival_edition_id' => $entry->festival_edition_id, 'status' => 'payment_reversed']);
        $this->mock(ReconcileFestivalPaymentAttempt::class, fn (MockInterface $mock) => $mock->shouldNotReceive('execute'));

        try {
            app(RestoreFestivalEntry::class)->execute($entry, $portalUser);
            $this->fail('A reversed Festival entitlement must remain read-only.');
        } catch (HttpException $exception) {
            $this->assertSame(423, $exception->getStatusCode());
            $this->assertSame(FestivalEntryStatus::Withdrawn, $entry->refresh()->status);
        }
    }

    public function test_portal_restore_route_reopens_the_owned_draft(): void
    {
        [$entry, $portalUser] = $this->festival();
        $this->actingAs($portalUser, 'festival')
            ->post(route('festival.portal.entries.restore', [$entry->account->slug, $entry]))
            ->assertRedirect(route('festival.portal.entries.show', [$entry->account->slug, $entry]))
            ->assertSessionHasNoErrors();

        $this->assertSame(FestivalEntryStatus::Draft, $entry->refresh()->status);
        $this->assertNull($entry->withdrawn_at);
    }

    public function test_portal_restore_route_returns_a_validation_error_for_submitted_entry(): void
    {
        [$entry, $portalUser] = $this->festival();
        $entry->update(['submitted_at' => now()->subHour()]);
        $url = route('festival.portal.entries.show', [$entry->account->slug, $entry]);

        $this->actingAs($portalUser, 'festival')->from($url)
            ->post(route('festival.portal.entries.restore', [$entry->account->slug, $entry]))
            ->assertRedirect($url)
            ->assertSessionHasErrors('entry');

        $this->assertSame(FestivalEntryStatus::Withdrawn, $entry->refresh()->status);
    }

    #[DataProvider('ownershipBoundaries')]
    public function test_portal_restore_route_hides_entries_owned_by_another_user(bool $sameAccount): void
    {
        [$entry] = $this->festival();
        $otherAccount = $sameAccount ? $entry->account : Account::factory()->create(['enable_festivals' => true]);
        $otherOwner = FestivalPortalUser::factory()->for($otherAccount)->create();

        $this->actingAs($otherOwner, 'festival')
            ->post(route('festival.portal.entries.restore', [$otherAccount->slug, $entry]))
            ->assertNotFound();
        $this->assertSame(FestivalEntryStatus::Withdrawn, $entry->refresh()->status);
    }

    public function test_portal_restore_route_requires_authentication(): void
    {
        [$entry] = $this->festival();
        $this->post(route('festival.portal.entries.restore', [$entry->account->slug, $entry]))
            ->assertRedirect(route('festival.login', $entry->account->slug));

        $this->assertSame(FestivalEntryStatus::Withdrawn, $entry->refresh()->status);
    }

    public function test_portal_restore_route_respects_reversed_edition_access(): void
    {
        [$entry, $portalUser] = $this->festival();
        FestivalEditionPurchase::factory()->create(['account_id' => $entry->account_id, 'festival_edition_id' => $entry->festival_edition_id, 'status' => 'payment_reversed']);

        $this->actingAs($portalUser, 'festival')
            ->post(route('festival.portal.entries.restore', [$entry->account->slug, $entry]))
            ->assertStatus(423);
        $this->assertSame(FestivalEntryStatus::Withdrawn, $entry->refresh()->status);
    }

    public function test_restored_draft_does_not_consume_the_participant_submission_quota(): void
    {
        [$entry, $portalUser] = $this->festival();
        $entry->edition->update(['max_entries_per_participant' => 1]);
        $submitted = FestivalEntry::factory()->for($entry->category)->create([
            'account_id' => $entry->account_id,
            'festival_edition_id' => $entry->festival_edition_id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => FestivalEntryStatus::Submitted,
            'submitted_at' => now(),
        ]);
        $submitted->participants()->attach($entry->participants()->firstOrFail(), ['account_id' => $entry->account_id, 'sort_order' => 0]);

        app(RestoreFestivalEntry::class)->execute($entry, $portalUser);

        $this->assertSame(FestivalEntryStatus::Draft, $entry->refresh()->status);
        $this->assertNull($entry->submitted_at);
        $this->assertSame(FestivalEntryStatus::Submitted, $submitted->refresh()->status);
    }

    public function test_full_category_blocks_draft_restoration_like_new_draft_creation(): void
    {
        [$entry, $portalUser] = $this->festival();
        $entry->category->update(['maximum_accepted_entries' => 1]);
        FestivalEntry::factory()->for($entry->category)->create([
            'account_id' => $entry->account_id,
            'festival_edition_id' => $entry->festival_edition_id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => FestivalEntryStatus::Accepted,
        ]);

        $this->assertRestoreRejected($entry, $portalUser);
    }

    /** @return array{FestivalEntry, FestivalPortalUser, FestivalCharge} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $edition = FestivalEdition::factory()->for(FestivalSeries::factory()->for($account))->published()->create(['account_id' => $account->id]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => FestivalEntryStatus::Withdrawn,
            'withdrawn_at' => now()->subHour(),
        ]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $entry->participants()->attach($participant, ['account_id' => $account->id, 'sort_order' => 0]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);
        $charge = $entry->charges()->create([
            'account_id' => $account->id,
            'festival_entry_step_id' => $entry->steps->firstOrFail()->id,
            'code' => 'RECOVER-'.$entry->id,
            'kind' => 'qualification',
            'name' => 'Qualification fee',
            'amount_cents' => 50000,
            'currency' => 'UAH',
            'due_at' => now()->addDay(),
        ]);
        app(FestivalActivityRecorder::class)->record($entry, 'entry.withdrawn', $edition, $portalUser);

        return [$entry->refresh(), $portalUser, $charge];
    }

    private function attempt(FestivalCharge $charge): FestivalPaymentAttempt
    {
        $attempt = $charge->paymentAttempts()->create([
            'account_id' => $charge->account_id,
            'provider' => 'monopay',
            'order_id' => 'RECOVER-'.str()->uuid(),
            'gateway_invoice_id' => 'invoice-'.str()->uuid(),
            'gateway_status' => 'created',
            'amount_cents' => $charge->amount_cents,
            'currency' => $charge->currency,
            'expires_at' => now()->subMinute(),
        ]);
        $attempt->allocations()->create([
            'account_id' => $charge->account_id,
            'festival_charge_id' => $charge->id,
            'amount_cents' => $charge->amount_cents,
            'currency' => $charge->currency,
        ]);
        $charge->update(['status' => FestivalChargeStatus::PaymentPending]);

        return $attempt;
    }

    private function completeAttempt(FestivalPaymentAttempt $attempt, PaymentCallbackStatus $status, string $gatewayStatus): FestivalPaymentAttempt
    {
        return app(FestivalPaymentService::class)->completeAttempt($attempt, new PaymentCallbackResult(
            orderId: $attempt->order_id,
            status: $status,
            amountCents: $attempt->amount_cents,
            currency: $attempt->currency,
            gatewayInvoiceId: $attempt->gateway_invoice_id,
            gatewayStatus: $gatewayStatus,
            paidAt: $status === PaymentCallbackStatus::Paid ? now()->subMinutes(5) : null,
        ));
    }

    private function assertRestoreRejected(FestivalEntry $entry, FestivalPortalUser $portalUser): void
    {
        $status = $entry->fresh()->status;

        try {
            app(RestoreFestivalEntry::class)->execute($entry, $portalUser);
            $this->fail('This entry must not be restored.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entry', $exception->errors());
            $this->assertSame($status, $entry->refresh()->status);
            $this->assertSame(0, $entry->activityLogs()->where('action', 'entry.restored')->count());
        }
    }
}
