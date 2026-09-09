<?php

namespace Tests\Feature;

use App\Actions\Festivals\InitializeFestivalEntryWorkflow;
use App\Actions\Festivals\ProvisionFestivalWorkflow;
use App\Actions\Festivals\ReconcileFestivalPaymentAttempt;
use App\Actions\Festivals\StoreFestivalResponse;
use App\Enums\AccountMode;
use App\Enums\AccountRole;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalPaymentStatus;
use App\Enums\FestivalRequirementStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\StudioPermission;
use App\Http\Middleware\PreventExpiredSubscriptionMutations;
use App\Models\Account;
use App\Models\FestivalActivityLog;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Models\FestivalNotification;
use App\Models\FestivalParticipant;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\Payments\PaymentGatewayException;
use App\Support\ScheduledTaskRegistry;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FestivalPaymentRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_bank_expiry_releases_every_allocated_charge_and_uses_the_owning_studios_credentials(): void
    {
        [$attempt, $charge] = $this->payment();
        $secondCharge = $this->charge($charge->entry, 15000);
        $attempt->allocations()->create([
            'account_id' => $charge->account_id,
            'festival_charge_id' => $secondCharge->id,
            'amount_cents' => $secondCharge->amount_cents,
            'currency' => $secondCharge->currency,
        ]);
        $attempt->update(['amount_cents' => 65000, 'expires_at' => now()->subHour()]);
        $payload = $this->statusPayload($attempt, ['status' => 'expired']);
        $this->fakeStatus($payload);

        $reconciled = app(ReconcileFestivalPaymentAttempt::class)->execute($attempt);

        $this->assertSame(FestivalPaymentStatus::Expired, $reconciled->status);
        $this->assertSame(FestivalChargeStatus::Failed, $charge->refresh()->status);
        $this->assertSame(FestivalChargeStatus::Failed, $secondCharge->refresh()->status);
        $this->assertSame($payload, $attempt->refresh()->last_callback_payload);
        $this->assertSame(2, $attempt->allocations()->count());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.monobank.ua/api/merchant/invoice/status?invoiceId='.$this->invoiceId($attempt)
            && $request->hasHeader('X-Token', 'festival-recovery-token-'.$charge->account_id));
        Http::assertSentCount(1);
    }

    public function test_bank_processing_keeps_payment_pending_after_the_local_checkout_window(): void
    {
        [$attempt, $charge] = $this->payment();
        $attempt->update(['expires_at' => now()->subHour()]);
        $this->fakeStatus($this->statusPayload($attempt, ['status' => 'processing']));

        app(ReconcileFestivalPaymentAttempt::class)->execute($attempt);

        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
        $this->assertSame('processing', $attempt->gateway_status);
        $this->assertSame(FestivalChargeStatus::PaymentPending, $charge->refresh()->status);
    }

    public function test_missing_on_time_success_callback_is_recovered_after_both_deadlines_without_a_refund(): void
    {
        [$attempt, $charge] = $this->payment();
        $paidAt = now()->subHours(2);
        $attempt->update(['expires_at' => $paidAt->copy()->addMinutes(10)]);
        $charge->update(['due_at' => $paidAt->copy()->addMinutes(5)]);
        $this->fakeStatus($this->statusPayload($attempt, [
            'status' => 'success',
            'modifiedDate' => $paidAt->toIso8601String(),
        ]));

        app(ReconcileFestivalPaymentAttempt::class)->execute($attempt);

        $this->assertSame(FestivalPaymentStatus::Paid, $attempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::Paid, $charge->refresh()->status);
        $this->assertTrue($attempt->paid_at->equalTo($paidAt));
        $this->assertTrue($charge->paid_at->equalTo($paidAt));
    }

    #[DataProvider('refundRequiredPayments')]
    public function test_actual_late_or_closed_entry_payments_still_require_refund(string $reason): void
    {
        [$attempt, $charge] = $this->payment();
        if ($reason === 'invoice_expired') {
            $attempt->update(['expires_at' => now()->subMinute()]);
        } elseif ($reason === 'charge_overdue') {
            $charge->update(['due_at' => now()->subMinute()]);
        } elseif ($reason === 'cancelled_charge') {
            $charge->update(['status' => FestivalChargeStatus::Cancelled, 'cancelled_at' => now()->subMinute()]);
        } elseif ($reason === 'refunded_charge') {
            $charge->update(['status' => FestivalChargeStatus::Refunded, 'refunded_at' => now()->subMinute()]);
        } elseif ($reason === 'refund_required_charge') {
            $charge->update(['status' => FestivalChargeStatus::PaidRequiresRefund]);
        } else {
            $charge->entry->update(['status' => FestivalEntryStatus::from($reason)]);
        }
        $this->fakeStatus($this->statusPayload($attempt, ['status' => 'success']));

        app(ReconcileFestivalPaymentAttempt::class)->execute($attempt);

        $this->assertSame(FestivalPaymentStatus::Paid, $attempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::PaidRequiresRefund, $charge->refresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function refundRequiredPayments(): array
    {
        return [
            'payment after checkout deadline' => ['invoice_expired'],
            'payment after charge deadline' => ['charge_overdue'],
            'cancelled charge' => ['cancelled_charge'],
            'refunded charge' => ['refunded_charge'],
            'already requires refund' => ['refund_required_charge'],
            'withdrawn application' => ['withdrawn'],
            'rejected application' => ['rejected'],
        ];
    }

    #[DataProvider('invalidProviderPayloads')]
    public function test_reconciliation_rejects_incomplete_or_mismatched_provider_facts(string $field, mixed $value): void
    {
        [$attempt, $charge] = $this->payment();
        $this->fakeStatus($this->statusPayload($attempt, ['status' => 'success', $field => $value]));

        try {
            app(ReconcileFestivalPaymentAttempt::class)->execute($attempt);
            $this->fail('Provider facts belonging to another invoice must not settle this payment.');
        } catch (InvalidPaymentCallbackException) {
            $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
            $this->assertSame(FestivalChargeStatus::PaymentPending, $charge->refresh()->status);
            $this->assertNull($attempt->last_callback_payload);
            $this->assertNull($attempt->paid_at);
        }
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidProviderPayloads(): array
    {
        return [
            'wrong invoice' => ['invoiceId', 'another-invoice'],
            'missing invoice' => ['invoiceId', null],
            'wrong reference' => ['reference', 'another-order'],
            'missing reference' => ['reference', null],
            'wrong amount' => ['amount', 49999],
            'missing amount' => ['amount', null],
            'wrong currency' => ['ccy', 840],
            'missing currency' => ['ccy', null],
        ];
    }

    public function test_provider_failure_preserves_the_unresolved_attempt_and_allocation(): void
    {
        [$attempt, $charge] = $this->payment();
        $attempt->update(['expires_at' => now()->subHour()]);
        Http::fake(['https://api.monobank.ua/api/merchant/invoice/status*' => Http::response(['error' => 'Unavailable'], 503)]);

        try {
            app(ReconcileFestivalPaymentAttempt::class)->execute($attempt);
            $this->fail('Unavailable provider status must not release an unresolved payment.');
        } catch (PaymentGatewayException) {
            $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
            $this->assertSame(FestivalChargeStatus::PaymentPending, $charge->refresh()->status);
            $this->assertNull($attempt->last_callback_payload);
            $this->assertSame(1, $attempt->allocations()->count());
        }
    }

    public function test_status_recovery_and_a_duplicate_callback_only_settle_and_notify_once(): void
    {
        [$attempt, $charge] = $this->payment();
        $payload = $this->statusPayload($attempt, ['status' => 'success']);
        $this->fakeStatus($payload);

        app(ReconcileFestivalPaymentAttempt::class)->execute($attempt);
        $notificationCount = FestivalNotification::query()->where('festival_entry_id', $charge->festival_entry_id)->count();
        $paidAt = $attempt->refresh()->paid_at;
        $this->travel(5)->minutes();
        app(FestivalPaymentService::class)->completeAttempt($attempt, new PaymentCallbackResult(
            orderId: $attempt->order_id,
            status: PaymentCallbackStatus::Paid,
            amountCents: $attempt->amount_cents,
            currency: $attempt->currency,
            gatewayInvoiceId: $this->invoiceId($attempt),
            paidAt: now(),
            payload: $payload,
        ));

        $this->assertSame(FestivalChargeStatus::Paid, $charge->refresh()->status);
        $this->assertTrue($attempt->refresh()->paid_at->equalTo($paidAt));
        $this->assertSame($notificationCount, FestivalNotification::query()->where('festival_entry_id', $charge->festival_entry_id)->count());
        $this->assertSame(1, FestivalActivityLog::query()
            ->where('subject_type', $attempt->getMorphClass())
            ->where('subject_id', $attempt->id)
            ->where('action', 'payment.status_changed')
            ->count());
    }

    public function test_expiring_an_old_attempt_does_not_release_a_new_pending_payment(): void
    {
        [$attempt, $charge] = $this->payment();
        $newAttempt = $attempt->replicate(['order_id', 'gateway_checkout_payload']);
        $newAttempt->order_id = $attempt->order_id.'-RETRY';
        $newAttempt->save();
        $newAttempt->allocations()->create([
            'account_id' => $charge->account_id,
            'festival_charge_id' => $charge->id,
            'amount_cents' => $charge->amount_cents,
            'currency' => $charge->currency,
        ]);
        $this->fakeStatus($this->statusPayload($attempt, ['status' => 'expired']));

        app(ReconcileFestivalPaymentAttempt::class)->execute($attempt);

        $this->assertSame(FestivalPaymentStatus::Expired, $attempt->refresh()->status);
        $this->assertSame(FestivalPaymentStatus::Pending, $newAttempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::PaymentPending, $charge->refresh()->status);
    }

    public function test_expiry_with_an_unchanged_provider_timestamp_clears_the_pending_charge(): void
    {
        [$attempt, $charge] = $this->payment();
        $created = $this->statusPayload($attempt, ['status' => 'created', 'modifiedDate' => now()->subHour()->toIso8601String()]);
        $attempt->update(['last_callback_payload' => $created, 'gateway_status' => 'created']);
        $this->fakeStatus([...$created, 'status' => 'expired']);

        app(ReconcileFestivalPaymentAttempt::class)->execute($attempt);

        $this->assertSame(FestivalPaymentStatus::Expired, $attempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::Failed, $charge->refresh()->status);
    }

    public function test_an_older_provider_response_does_not_overwrite_more_recent_processing(): void
    {
        [$attempt, $charge] = $this->payment();
        $processing = $this->statusPayload($attempt, ['status' => 'processing']);
        $attempt->update(['last_callback_payload' => $processing, 'gateway_status' => 'processing']);
        $this->fakeStatus($this->statusPayload($attempt, ['status' => 'expired', 'modifiedDate' => now()->subMinute()->toIso8601String()]));

        app(ReconcileFestivalPaymentAttempt::class)->execute($attempt);

        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
        $this->assertSame($processing, $attempt->last_callback_payload);
        $this->assertSame(FestivalChargeStatus::PaymentPending, $charge->refresh()->status);
    }

    public function test_bank_processing_cannot_be_mistaken_for_a_locally_expired_payment(): void
    {
        [$attempt, $charge] = $this->payment();
        $attempt->update(['status' => FestivalPaymentStatus::Expired, 'expires_at' => now()->subHour()]);
        $charge->update(['status' => FestivalChargeStatus::Failed]);
        $this->fakeStatus($this->statusPayload($attempt, ['status' => 'processing']));

        try {
            app(ReconcileFestivalPaymentAttempt::class)->execute($attempt);
            $this->fail('A conflicting bank status must not confirm that a previous payment is unpaid.');
        } catch (PaymentGatewayException) {
            $this->assertSame(FestivalPaymentStatus::Expired, $attempt->refresh()->status);
            $this->assertSame(FestivalChargeStatus::Failed, $charge->refresh()->status);
            $this->assertNull($attempt->last_callback_payload);
        }
    }

    public function test_starting_another_checkout_cannot_bypass_a_bank_payment_still_processing(): void
    {
        [$attempt, $charge] = $this->payment();
        $attempt->update(['expires_at' => now()->subHour()]);
        $this->fakeStatus($this->statusPayload($attempt, ['status' => 'processing']));

        try {
            app(FestivalPaymentService::class)->startCharge($charge, IntegrationProvider::Monopay->value);
            $this->fail('A processing bank payment must block creation of a second invoice.');
        } catch (ValidationException) {
            $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
            $this->assertSame(FestivalChargeStatus::PaymentPending, $charge->refresh()->status);
            $this->assertSame(1, $charge->paymentAttempts()->count());
            Http::assertSentCount(1);
            Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        }
    }

    public function test_the_owned_status_endpoint_only_reads_local_state_and_exposes_no_gateway_details(): void
    {
        [$attempt, $charge] = $this->payment();
        $entry = $charge->entry;

        $response = $this->actingAs($entry->portalUser, 'festival')
            ->getJson(route('festival.portal.charges.status', [$entry->account->slug, $entry, $charge]));

        $response->assertOk()
            ->assertJsonStructure(['state', 'message', 'payment_html'])
            ->assertDontSee($this->invoiceId($attempt))
            ->assertDontSee('festival-recovery-token-')
            ->assertDontSee('gateway_checkout_payload')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_owner_can_check_expiry_then_retry_payment_without_creating_a_duplicate_invoice(): void
    {
        [$attempt, $charge] = $this->payment();
        $entry = $charge->entry;
        $attempt->update(['expires_at' => now()->subHour()]);
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/status*' => Http::response($this->statusPayload($attempt, ['status' => 'expired'])),
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'festival-recovery-new',
                'pageUrl' => 'https://pay.monobank.ua/invoice/festival-recovery-new',
            ]),
        ]);

        $this->actingAs($entry->portalUser, 'festival')
            ->postJson(route('festival.portal.charges.check', [$entry->account->slug, $entry, $charge]))
            ->assertOk();
        $this->assertSame(FestivalChargeStatus::Failed, $charge->refresh()->status);
        $this->post(route('festival.portal.charges.pay', [$entry->account->slug, $entry, $charge]), [
            'provider' => IntegrationProvider::Monopay->value,
            'festival_rules_accepted' => '1',
        ])->assertRedirect('https://pay.monobank.ua/invoice/festival-recovery-new');

        $this->assertSame(FestivalPaymentStatus::Expired, $attempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::PaymentPending, $charge->refresh()->status);
        $this->assertSame(2, $charge->paymentAttempts()->count());
        Http::assertSentCount(2);
    }

    public function test_owner_can_resume_the_same_unpaid_checkout_after_checking_the_bank(): void
    {
        [$attempt, $charge] = $this->payment();
        $entry = $charge->entry;
        $this->fakeStatus($this->statusPayload($attempt));

        $this->actingAs($entry->portalUser, 'festival')
            ->post(route('festival.portal.charges.resume', [$entry->account->slug, $entry, $charge]))
            ->assertRedirect('https://pay.monobank.ua/invoice/'.$this->invoiceId($attempt));

        $this->assertSame(1, $charge->paymentAttempts()->count());
        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_resume_never_redirects_to_an_untrusted_stored_checkout_url(): void
    {
        [$attempt, $charge] = $this->payment();
        $entry = $charge->entry;
        $attempt->update(['gateway_checkout_payload' => ['response' => [
            'invoiceId' => $this->invoiceId($attempt),
            'pageUrl' => 'https://checkout.example.test/phishing',
        ]]]);
        $this->fakeStatus($this->statusPayload($attempt));

        $this->actingAs($entry->portalUser, 'festival')
            ->post(route('festival.portal.charges.resume', [$entry->account->slug, $entry, $charge]))
            ->assertRedirect(route('festival.portal.entries.show', [$entry->account->slug, $entry]));

        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
    }

    public function test_resume_refuses_a_withdrawn_entry_without_contacting_the_bank(): void
    {
        [$attempt, $charge] = $this->payment();
        $entry = $charge->entry;
        $entry->update(['status' => FestivalEntryStatus::Withdrawn]);

        $this->actingAs($entry->portalUser, 'festival')
            ->post(route('festival.portal.charges.resume', [$entry->account->slug, $entry, $charge]))
            ->assertStatus(409);

        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_check_endpoint_returns_a_recoverable_error_if_the_bank_is_unavailable(): void
    {
        [$attempt, $charge] = $this->payment();
        $entry = $charge->entry;
        Http::fake(['https://api.monobank.ua/api/merchant/invoice/status*' => Http::response([], 503)]);

        $this->actingAs($entry->portalUser, 'festival')
            ->postJson(route('festival.portal.charges.check', [$entry->account->slug, $entry, $charge]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider');

        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::PaymentPending, $charge->refresh()->status);
    }

    #[DataProvider('recoveryEndpoints')]
    public function test_payment_recovery_endpoints_reject_another_registrant_in_the_same_account(string $action, string $method): void
    {
        [$attempt, $charge] = $this->payment();
        $entry = $charge->entry;
        $otherPortalUser = FestivalPortalUser::factory()->for($entry->account)->create();

        $this->actingAs($otherPortalUser, 'festival')
            ->json($method, route('festival.portal.charges.'.$action, [$entry->account->slug, $entry, $charge]))
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
    }

    #[DataProvider('recoveryEndpoints')]
    public function test_payment_recovery_endpoints_reject_an_entry_from_another_account(string $action, string $method): void
    {
        [$attempt, $charge] = $this->payment();
        [, $otherCharge] = $this->payment();
        $otherEntry = $otherCharge->entry;

        $this->actingAs($otherEntry->portalUser, 'festival')
            ->json($method, route('festival.portal.charges.'.$action, [$otherEntry->account->slug, $charge->entry, $charge]))
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
    }

    #[DataProvider('recoveryEndpoints')]
    public function test_payment_recovery_endpoints_reject_a_charge_from_another_owned_entry(string $action, string $method): void
    {
        [$attempt, $charge] = $this->payment();
        $entry = $charge->entry;
        $otherEntry = FestivalEntry::factory()->for($entry->category)->create([
            'account_id' => $entry->account_id,
            'festival_edition_id' => $entry->festival_edition_id,
            'festival_portal_user_id' => $entry->festival_portal_user_id,
        ]);

        $this->actingAs($entry->portalUser, 'festival')
            ->json($method, route('festival.portal.charges.'.$action, [$entry->account->slug, $otherEntry, $charge]))
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
    }

    /** @return array<string, array{string, string}> */
    public static function recoveryEndpoints(): array
    {
        return [
            'check provider state' => ['check', 'POST'],
            'resume existing checkout' => ['resume', 'POST'],
            'poll local state' => ['status', 'GET'],
        ];
    }

    public function test_reconciliation_command_only_checks_stale_pending_monopay_payments_in_writable_festival_accounts(): void
    {
        [$staleAttempt, $staleCharge] = $this->payment();
        $staleAttempt->forceFill(['updated_at' => now()->subMinutes(5)])->save();
        [$freshAttempt] = $this->payment();
        [$paidAttempt] = $this->payment();
        $paidAttempt->forceFill(['status' => FestivalPaymentStatus::Paid, 'updated_at' => now()->subHour()])->save();
        [$otherProviderAttempt] = $this->payment();
        $otherProviderAttempt->forceFill(['provider' => IntegrationProvider::Liqpay->value, 'updated_at' => now()->subHour()])->save();
        [$demoAttempt] = $this->payment();
        $demoAttempt->account->update(['mode' => AccountMode::DemoReadonly]);
        $demoAttempt->forceFill(['updated_at' => now()->subHour()])->save();
        [$disabledAttempt] = $this->payment();
        $disabledAttempt->account->update(['enable_festivals' => false]);
        $disabledAttempt->forceFill(['updated_at' => now()->subHour()])->save();
        $this->fakeStatus($this->statusPayload($staleAttempt, ['status' => 'expired']));

        $this->artisan('festival-payments:reconcile')
            ->expectsOutput('Checked 1 Festival payment(s); 0 unavailable.')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(FestivalPaymentStatus::Expired, $staleAttempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::Failed, $staleCharge->refresh()->status);
        $this->assertSame(FestivalPaymentStatus::Paid, $paidAttempt->refresh()->status);
        foreach ([$freshAttempt, $otherProviderAttempt, $demoAttempt, $disabledAttempt] as $ignoredAttempt) {
            $this->assertSame(FestivalPaymentStatus::Pending, $ignoredAttempt->refresh()->status);
            $this->assertNull($ignoredAttempt->last_callback_payload);
        }
    }

    public function test_reconciliation_command_honors_its_limit_and_checks_the_oldest_attempt_first(): void
    {
        [$olderAttempt] = $this->payment();
        [$newerAttempt] = $this->payment();
        $olderAttempt->forceFill(['updated_at' => now()->subHour()])->save();
        $newerAttempt->forceFill(['updated_at' => now()->subMinutes(10)])->save();
        $this->fakeStatus($this->statusPayload($olderAttempt, ['status' => 'expired']));

        $this->artisan('festival-payments:reconcile', ['--limit' => 1])
            ->expectsOutput('Checked 1 Festival payment(s); 0 unavailable.')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(FestivalPaymentStatus::Expired, $olderAttempt->refresh()->status);
        $this->assertSame(FestivalPaymentStatus::Pending, $newerAttempt->refresh()->status);
        $this->assertNull($newerAttempt->last_callback_payload);
    }

    public function test_reconciliation_command_reports_provider_failure_without_mutating_payment_facts(): void
    {
        [$attempt, $charge] = $this->payment();
        $attempt->forceFill(['updated_at' => now()->subMinutes(5), 'expires_at' => now()->subMinute()])->save();
        $originalAttempt = $attempt->refresh()->getRawOriginal();
        $originalCharge = $charge->refresh()->getRawOriginal();
        Http::fake(['https://api.monobank.ua/api/merchant/invoice/status*' => Http::response([], 503)]);

        $this->artisan('festival-payments:reconcile')
            ->expectsOutput('Checked 0 Festival payment(s); 1 unavailable.')
            ->assertFailed();

        $this->assertSame(Arr::except($originalAttempt, ['updated_at']), Arr::except($attempt->refresh()->getRawOriginal(), ['updated_at']));
        $this->assertTrue($attempt->updated_at->equalTo(now()));
        $this->assertSame($originalCharge, $charge->refresh()->getRawOriginal());
    }

    #[DataProvider('unresolvedReconciliationResponses')]
    public function test_each_reconciliation_batch_reaches_the_next_oldest_payment_after_an_unresolved_lookup(bool $providerUnavailable): void
    {
        [$olderAttempt, $olderCharge] = $this->payment();
        [$nextAttempt] = $this->payment();
        $processing = $this->statusPayload($olderAttempt, ['status' => 'processing']);
        $olderAttempt->forceFill([
            'updated_at' => now()->subHour(),
            'gateway_status' => 'processing',
            'last_callback_payload' => $processing,
        ])->save();
        $nextAttempt->forceFill(['updated_at' => now()->subMinutes(10)])->save();
        Http::fake(function (Request $request) use ($olderAttempt, $nextAttempt, $processing, $providerUnavailable): PromiseInterface {
            if ($request['invoiceId'] === $this->invoiceId($olderAttempt)) {
                return $providerUnavailable ? Http::response([], 503) : Http::response($processing);
            }

            return Http::response($this->statusPayload($nextAttempt, ['status' => 'expired']));
        });

        $this->artisan('festival-payments:reconcile', ['--limit' => 1])
            ->assertExitCode($providerUnavailable ? 1 : 0);

        $this->assertSame(FestivalPaymentStatus::Pending, $olderAttempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::PaymentPending, $olderCharge->refresh()->status);
        $this->assertSame($processing, $olderAttempt->last_callback_payload);
        $this->assertTrue($olderAttempt->updated_at->equalTo(now()));
        $this->travel(5)->minutes();

        $this->artisan('festival-payments:reconcile', ['--limit' => 1])
            ->expectsOutput('Checked 1 Festival payment(s); 0 unavailable.')
            ->assertSuccessful();

        $this->assertSame(FestivalPaymentStatus::Expired, $nextAttempt->refresh()->status);
        $this->assertSame(FestivalPaymentStatus::Pending, $olderAttempt->refresh()->status);
        Http::assertSent(fn (Request $request): bool => $request['invoiceId'] === $this->invoiceId($nextAttempt));
    }

    /** @return array<string, array{bool}> */
    public static function unresolvedReconciliationResponses(): array
    {
        return [
            'unchanged bank processing' => [false],
            'provider unavailable' => [true],
        ];
    }

    public function test_reconciliation_is_scheduled_every_five_minutes_without_overlapping_across_servers(): void
    {
        $registry = app(ScheduledTaskRegistry::class);
        $definition = collect($registry->definitions())->firstWhere('key', 'festival_payments_reconcile');

        $this->assertNotNull($definition);
        $this->assertSame('festival-payments:reconcile --limit=50', $definition['command']);
        $this->assertSame('*/5 * * * *', $definition['expression']);
        $this->assertSame('scheduled_task_frequency_every_five_minutes', $definition['frequency_key']);
        $this->assertSame(30, $definition['overlap_minutes']);
        $this->assertTrue($definition['single_server']);
        $registry->schedule();
        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'festival-payments:reconcile'));
        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_pending_payment_step_offers_continue_and_check_without_another_payment_form(): void
    {
        [$attempt, $charge, $step] = $this->paymentStep();
        $entry = $charge->entry;

        $this->actingAs($entry->portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$entry->account->slug, $entry, $step]))
            ->assertOk()
            ->assertSee(__('app.festival_payment_resume'))
            ->assertSee(__('app.festival_payment_check'))
            ->assertSee(route('festival.portal.charges.resume', [$entry->account->slug, $entry, $charge]), false)
            ->assertSee(route('festival.portal.charges.check', [$entry->account->slug, $entry, $charge]), false)
            ->assertSee('data-festival-payment-status-url', false)
            ->assertDontSee(route('festival.portal.charges.pay', [$entry->account->slug, $entry, $charge]), false);

        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_expired_local_checkout_step_requires_checking_the_bank_before_retry_is_offered(): void
    {
        [$attempt, $charge, $step] = $this->paymentStep();
        $entry = $charge->entry;
        $attempt->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($entry->portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$entry->account->slug, $entry, $step]))
            ->assertOk()
            ->assertSee(__('app.festival_payment_check'))
            ->assertSee(__('app.festival_payment_window_ended'))
            ->assertDontSee(route('festival.portal.charges.resume', [$entry->account->slug, $entry, $charge]), false)
            ->assertDontSee(route('festival.portal.charges.pay', [$entry->account->slug, $entry, $charge]), false);

        $this->assertSame(FestivalChargeStatus::PaymentPending, $charge->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_withdrawn_entry_step_explains_payment_is_closed_and_has_no_continue_or_pay_action(): void
    {
        [$attempt, $charge, $step] = $this->paymentStep();
        $entry = $charge->entry;
        $entry->update(['status' => FestivalEntryStatus::Withdrawn, 'withdrawn_at' => now()]);

        $this->actingAs($entry->portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$entry->account->slug, $entry, $step]))
            ->assertOk()
            ->assertSee(__('app.festival_entry_closed_payment'))
            ->assertDontSee(__('app.festival_payment_waiting_checkout'))
            ->assertDontSee(route('festival.portal.charges.resume', [$entry->account->slug, $entry, $charge]), false)
            ->assertDontSee(route('festival.portal.charges.pay', [$entry->account->slug, $entry, $charge]), false);

        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_checking_expiry_returns_a_payment_fragment_and_step_with_the_retry_button(): void
    {
        [$attempt, $charge, $step] = $this->paymentStep();
        $entry = $charge->entry;
        $attempt->update(['expires_at' => now()->subMinute()]);
        $this->fakeStatus($this->statusPayload($attempt, ['status' => 'expired']));
        $payUrl = route('festival.portal.charges.pay', [$entry->account->slug, $entry, $charge]);

        $response = $this->actingAs($entry->portalUser, 'festival')
            ->postJson(route('festival.portal.charges.check', [$entry->account->slug, $entry, $charge]))
            ->assertOk();

        $this->assertStringContainsString($payUrl, $response->json('payment_html'));
        $this->assertStringContainsString(__('app.festival_submit_and_pay'), $response->json('payment_html'));
        $this->get(route('festival.portal.entry-steps.show', [$entry->account->slug, $entry, $step]))
            ->assertOk()
            ->assertSee($payUrl, false)
            ->assertSee(__('app.festival_payment_failed_retry'))
            ->assertDontSee(route('festival.portal.charges.resume', [$entry->account->slug, $entry, $charge]), false);
        Http::assertSentCount(1);
    }

    public function test_only_finance_staff_can_check_an_entry_payment(): void
    {
        $this->withoutMiddleware(PreventExpiredSubscriptionMutations::class);
        [$attempt, $charge] = $this->payment();
        $entry = $charge->entry;
        $registrationStaff = $this->staff($entry->account, StudioPermission::ManageFestivalRegistrations);
        $financeStaff = $this->staff($entry->account, StudioPermission::ManageFestivalFinance);
        $url = route('dashboard.accounts.festivals.charges.check', [$entry->account, $entry->edition, $charge]);

        $this->actingAs($registrationStaff)->postJson($url)->assertForbidden();
        Http::assertNothingSent();
        $this->fakeStatus($this->statusPayload($attempt, ['status' => 'expired']));
        $this->actingAs($financeStaff)->post($url)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(FestivalPaymentStatus::Expired, $attempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::Failed, $charge->refresh()->status);
        Http::assertSentCount(1);
    }

    public function test_finance_staff_cannot_check_another_accounts_charge(): void
    {
        $this->withoutMiddleware(PreventExpiredSubscriptionMutations::class);
        [$attempt, $charge] = $this->payment();
        [, $otherCharge] = $this->payment();
        $otherEntry = $otherCharge->entry;
        $financeStaff = $this->staff($otherEntry->account, StudioPermission::ManageFestivalFinance);

        $this->actingAs($financeStaff)
            ->postJson(route('dashboard.accounts.festivals.charges.check', [$otherEntry->account, $otherEntry->edition, $charge]))
            ->assertNotFound();

        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
        Http::assertNothingSent();
    }

    #[DataProvider('manualPaymentDecisions')]
    public function test_manual_charge_review_cannot_override_an_overdue_unresolved_monopay_attempt(string $decision): void
    {
        $this->withoutMiddleware(PreventExpiredSubscriptionMutations::class);
        [$attempt, $charge] = $this->payment();
        $entry = $charge->entry;
        $attempt->update(['expires_at' => now()->subHour()]);
        $originalAttempt = $attempt->refresh()->getRawOriginal();
        $originalCharge = $charge->refresh()->getRawOriginal();
        $financeStaff = $this->staff($entry->account, StudioPermission::ManageFestivalFinance);

        $this->actingAs($financeStaff)
            ->patchJson(route('dashboard.accounts.festivals.charges.manual-review', [$entry->account, $entry->edition, $charge]), [
                'decision' => $decision,
                'notes' => 'Try to resolve an elapsed checkout manually',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision');

        $this->assertSame($originalAttempt, $attempt->refresh()->getRawOriginal());
        $this->assertSame($originalCharge, $charge->refresh()->getRawOriginal());
        $this->assertSame(0, FestivalActivityLog::query()
            ->where('subject_type', $charge->getMorphClass())
            ->where('subject_id', $charge->id)
            ->where('action', 'charge.manual_reviewed')
            ->count());
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function manualPaymentDecisions(): array
    {
        return ['approve' => ['approve'], 'reject' => ['reject']];
    }

    public function test_resume_requires_current_agreement_and_is_hidden_until_requirements_are_complete(): void
    {
        [$attempt, $charge, $step] = $this->paymentStep(agreementRequired: true);
        $entry = $charge->entry;
        $resumeUrl = route('festival.portal.charges.resume', [$entry->account->slug, $entry, $charge]);

        $this->actingAs($entry->portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$entry->account->slug, $entry, $step]))
            ->assertOk()
            ->assertDontSee($resumeUrl, false)
            ->assertSee(__('app.festival_payment_check'));
        $this->postJson($resumeUrl)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider');

        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::PaymentPending, $charge->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_resume_rechecks_current_requirements_after_the_bank_response_before_redirecting(): void
    {
        [$attempt, $charge, $step] = $this->paymentStep(agreementRequired: true);
        $entry = $charge->entry;
        $requirement = $step->requirements()->sole();
        app(StoreFestivalResponse::class)->execute($requirement, $entry->portalUser, true);
        Http::fake(function (Request $request) use ($attempt, $requirement, $entry): PromiseInterface {
            app(StoreFestivalResponse::class)->execute($requirement->refresh(), $entry->portalUser, false);

            return Http::response($this->statusPayload($attempt));
        });

        $this->actingAs($entry->portalUser, 'festival')
            ->postJson(route('festival.portal.charges.resume', [$entry->account->slug, $entry, $charge]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider');

        $this->assertSame(FestivalRequirementStatus::Missing, $requirement->refresh()->status);
        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::PaymentPending, $charge->refresh()->status);
        Http::assertSentCount(1);
    }

    public function test_resume_cannot_bypass_runtime_steps_with_an_unassigned_legacy_charge(): void
    {
        [$attempt, $charge] = $this->paymentStep();
        $entry = $charge->entry;
        $charge->update(['festival_entry_step_id' => null]);

        $this->actingAs($entry->portalUser, 'festival')
            ->postJson(route('festival.portal.charges.resume', [$entry->account->slug, $entry, $charge]))
            ->assertStatus(409);

        $this->assertSame(FestivalPaymentStatus::Pending, $attempt->refresh()->status);
        Http::assertNothingSent();
    }

    /** @return array{FestivalPaymentAttempt, FestivalCharge, FestivalEntryStep} */
    private function paymentStep(bool $agreementRequired = false): array
    {
        [$attempt, $charge] = $this->payment();
        $entry = $charge->entry;
        $participant = FestivalParticipant::factory()->for($entry->portalUser)->create(['account_id' => $entry->account_id]);
        $entry->participants()->sync([$participant->id => ['account_id' => $entry->account_id, 'sort_order' => 0]]);
        $workflow = app(ProvisionFestivalWorkflow::class)->execute($entry->edition, 'Payment recovery');
        if ($agreementRequired) {
            FestivalRequirementDefinition::factory()->for($entry->edition)->create([
                'account_id' => $entry->account_id,
                'festival_workflow_step_id' => $workflow->steps->firstWhere('code', 'application')->id,
                'code' => 'payment-conditions',
                'name' => 'Participation conditions',
                'type' => 'custom_document',
                'subject_scope' => 'entry',
                'input_type' => 'agreement',
                'pricing' => ['mode' => 'none'],
                'is_required' => true,
                'is_active' => true,
            ]);
        }
        $entry->category->update(['festival_workflow_id' => $workflow->id]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry->refresh());
        $step = $entry->steps->first(fn (FestivalEntryStep $step): bool => $step->workflowStep->code === 'application');
        $charge->update(['festival_entry_step_id' => $step->id]);

        return [$attempt, $charge, $step];
    }

    private function staff(Account $account, StudioPermission $permission): User
    {
        $staff = User::factory()->create();
        $account->users()->attach($staff->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [$permission->value],
        ]);

        return $staff;
    }

    /** @return array{FestivalPaymentAttempt, FestivalCharge} */
    private function payment(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_currency' => 'UAH']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
        ]);
        IntegrationSetting::factory()->forAccountScope($account)->create([
            'provider' => IntegrationProvider::Monopay,
            'category' => IntegrationCategory::Payment,
            'is_enabled' => true,
            'credentials' => ['api_token' => 'festival-recovery-token-'.$account->id],
        ]);
        $charge = $this->charge($entry, 50000);
        $attempt = FestivalPaymentAttempt::query()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $charge->id,
            'provider' => IntegrationProvider::Monopay->value,
            'order_id' => 'FCHP-RECOVERY-'.$charge->id,
            'amount_cents' => $charge->amount_cents,
            'currency' => $charge->currency,
            'expires_at' => now()->addMinutes(30),
            'gateway_checkout_payload' => ['response' => [
                'invoiceId' => 'festival-recovery-'.$charge->id,
                'pageUrl' => 'https://pay.monobank.ua/invoice/festival-recovery-'.$charge->id,
            ]],
        ]);
        $attempt->allocations()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $charge->id,
            'amount_cents' => $charge->amount_cents,
            'currency' => $charge->currency,
        ]);

        return [$attempt, $charge];
    }

    private function charge(FestivalEntry $entry, int $amountCents): FestivalCharge
    {
        return $entry->charges()->create([
            'account_id' => $entry->account_id,
            'code' => 'FCH-RECOVERY-'.$entry->id.'-'.$amountCents,
            'kind' => 'participation',
            'name' => 'Participation fee',
            'status' => FestivalChargeStatus::PaymentPending,
            'amount_cents' => $amountCents,
            'currency' => 'UAH',
            'due_at' => now()->addDay(),
        ]);
    }

    private function invoiceId(FestivalPaymentAttempt $attempt): string
    {
        return 'festival-recovery-'.$attempt->festival_charge_id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function statusPayload(FestivalPaymentAttempt $attempt, array $overrides = []): array
    {
        return [
            'invoiceId' => $this->invoiceId($attempt),
            'reference' => $attempt->order_id,
            'amount' => $attempt->amount_cents,
            'ccy' => 980,
            'status' => 'created',
            'modifiedDate' => now()->toIso8601String(),
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function fakeStatus(array $payload): void
    {
        Http::fake(['https://api.monobank.ua/api/merchant/invoice/status*' => Http::response($payload)]);
    }
}
