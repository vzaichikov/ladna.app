<?php

namespace Tests\Feature;

use App\Actions\Festivals\ActivateFestivalParticipationCharges;
use App\Actions\Festivals\InitializeFestivalEntryWorkflow;
use App\Actions\Festivals\ProvisionFestivalWorkflow;
use App\Actions\Festivals\StoreFestivalResponse;
use App\Enums\AccountRole;
use App\Enums\FestivalEntryStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalActivityLog;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalParticipant;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FestivalPaidCategoryReassignmentTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('settledPayments')]
    public function test_staff_moves_approved_entries_without_replacing_settled_payment_facts(string $status, bool $online, int $targetAmount, string $entryStatus = 'accepted'): void
    {
        [$account, $edition, $entry, $target, $owner, $charge] = $this->approvedEntry($status, $online, $targetAmount);
        $entry->forceFill(['status' => $entryStatus])->save();
        $entryFacts = $entry->getRawOriginal();
        $chargeFacts = $charge->getRawOriginal();
        $steps = $entry->steps()->orderBy('id')->get()->map->getRawOriginal()->all();
        $requirements = $entry->requirements()->orderBy('id')->get()->map->getRawOriginal()->all();
        $responses = $entry->requirements->flatMap->submissions->map->getRawOriginal()->all();
        $attempts = $charge->paymentAttempts()->get()->map->getRawOriginal()->all();
        $allocations = $charge->paymentAllocations()->get()->map->getRawOriginal()->all();

        $response = $this->actingAs($owner)->patchJson($this->changeUrl($account, $edition, $entry), [
            'festival_category_id' => $target->id,
            'reason' => 'Correct the approved performance category.',
        ]);

        $response->assertOk()->assertJsonCount(2, 'fragments_html');
        $this->assertStringContainsString(__('app.festival_category_manual_payment_copy'), $response->json('message'));
        $this->assertSame($target->id, $entry->refresh()->festival_category_id);
        $this->assertSame(
            collect($entryFacts)->except(['festival_category_id', 'updated_at'])->all(),
            collect($entry->getRawOriginal())->except(['festival_category_id', 'updated_at'])->all(),
        );
        $this->assertSame($chargeFacts, $charge->refresh()->getRawOriginal());
        $this->assertSame(1, $entry->charges()->count());
        $this->assertSame(0, $entry->chargeAdjustments()->count());
        $this->assertSame($steps, $entry->steps()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($requirements, $entry->requirements()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($responses, $entry->requirements()->with('submissions')->get()->flatMap->submissions->map->getRawOriginal()->all());
        $this->assertSame($attempts, $charge->paymentAttempts()->get()->map->getRawOriginal()->all());
        $this->assertSame($allocations, $charge->paymentAllocations()->get()->map->getRawOriginal()->all());
        $this->assertDatabaseHas('festival_activity_logs', [
            'festival_entry_id' => $entry->id,
            'action' => 'entry.category_reassigned',
        ]);
    }

    public static function settledPayments(): array
    {
        return [
            'manual higher' => ['paid', false, 320000],
            'manual lower' => ['paid', false, 260000],
            'manual equal' => ['paid', false, 290000],
            'online higher' => ['paid', true, 320000],
            'online lower' => ['paid', true, 260000],
            'online equal' => ['paid', true, 290000],
            'refund required' => ['paid_requires_refund', false, 320000],
            'refunded' => ['refunded', true, 260000],
            'pending reapproval' => ['paid', true, 320000, 'changes_pending'],
        ];
    }

    public function test_manual_payment_guidance_is_visible_before_the_move_and_after_a_redirect(): void
    {
        [$account, $edition, $entry, $target, $owner] = $this->approvedEntry();
        $url = route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]);

        foreach (['en', 'uk'] as $locale) {
            app()->setLocale($locale);
            $this->actingAs($owner)->get($url)->assertOk()
                ->assertSee(__('app.festival_category_manual_payment_copy'))
                ->assertSee('data-festival-category-payment-warning', false);
        }

        $this->actingAs($owner)->from($url)->patch($this->changeUrl($account, $edition, $entry), [
            'festival_category_id' => $target->id,
            'reason' => 'A category correction.',
        ])->assertRedirect($url)->assertSessionHas('status', fn (string $message): bool => str_contains($message, __('app.festival_category_manual_payment_copy')));
        $this->get($url)->assertOk()->assertSee(__('app.festival_category_manual_payment_copy'));
    }

    public function test_a_registration_manager_can_move_a_paid_entry_without_receiving_financial_details(): void
    {
        [$account, $edition, $entry, $target] = $this->approvedEntry();
        $staff = $this->staff($account, [StudioPermission::ManageFestivalRegistrations]);
        $response = $this->actingAs($staff)->patchJson($this->changeUrl($account, $edition, $entry), [
            'festival_category_id' => $target->id,
            'reason' => 'Registration correction.',
        ]);

        $response->assertOk()->assertJsonCount(1, 'fragments_html');
        $this->assertStringContainsString(__('app.festival_category_manual_payment_copy'), $response->json('message'));
        $this->assertStringNotContainsString('2900', json_encode($response->json(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('data-festival-application-fragment-key="charges-', $response->json('fragments_html.0'));
    }

    #[DataProvider('conflictingTrackStatuses')]
    public function test_target_track_conflicts_roll_back_category_payment_and_activity_changes(string $conflictingStatus, string $entryStatus = 'accepted'): void
    {
        [$account, $edition, $entry, $target, $owner, $charge] = $this->approvedEntry();
        $entry->forceFill(['status' => $entryStatus])->save();
        FestivalEntry::factory()->for($target)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $entry->festival_portal_user_id,
            'status' => $conflictingStatus,
            'normalized_track_key' => $entry->normalized_track_key,
            'track_reserved_at' => now(),
        ]);
        $entryFacts = $entry->getRawOriginal();
        $chargeFacts = $charge->getRawOriginal();
        $activityCount = FestivalActivityLog::query()->where('festival_entry_id', $entry->id)->count();

        $this->actingAs($owner)->patchJson($this->changeUrl($account, $edition, $entry), [
            'festival_category_id' => $target->id,
            'reason' => 'Move conflicting music.',
        ])->assertUnprocessable()->assertJsonValidationErrors('festival_category_id')
            ->assertJsonPath('errors.festival_category_id.0', __('app.festival_track_already_reserved'));

        $this->assertSame($entryFacts, $entry->refresh()->getRawOriginal());
        $this->assertSame($chargeFacts, $charge->refresh()->getRawOriginal());
        $this->assertSame($activityCount, FestivalActivityLog::query()->where('festival_entry_id', $entry->id)->count());
    }

    public static function conflictingTrackStatuses(): array
    {
        return [
            'active reservation' => ['accepted'],
            'database uniqueness conflict' => ['withdrawn'],
            'pending reapproval' => ['accepted', 'changes_pending'],
        ];
    }

    public function test_live_checkout_still_blocks_a_paid_entry_move_even_after_local_expiry(): void
    {
        [$account, $edition, $entry, $target, $owner, $charge] = $this->approvedEntry('paid', true);
        $charge->paymentAttempts()->firstOrFail()->forceFill(['status' => 'pending', 'expires_at' => now()->subMinute()])->save();

        $this->actingAs($owner)->patchJson($this->changeUrl($account, $edition, $entry), [
            'festival_category_id' => $target->id,
            'reason' => 'Unresolved bank checkout.',
        ])->assertUnprocessable()->assertJsonValidationErrors('festival_category_id');
        $this->assertNotSame($target->id, $entry->refresh()->festival_category_id);
    }

    public function test_finance_permission_alone_cannot_move_an_entry_and_foreign_categories_are_rejected(): void
    {
        [$account, $edition, $entry, $target, $owner] = $this->approvedEntry();
        $finance = $this->staff($account, [StudioPermission::ManageFestivalFinance]);
        $this->actingAs($finance)->patchJson($this->changeUrl($account, $edition, $entry), [
            'festival_category_id' => $target->id,
            'reason' => 'No registration permission.',
        ])->assertForbidden();

        $foreignAccount = Account::factory()->create(['enable_festivals' => true]);
        $foreignSeries = FestivalSeries::factory()->for($foreignAccount)->create();
        $foreignEdition = FestivalEdition::factory()->for($foreignSeries)->create(['account_id' => $foreignAccount->id]);
        $foreignCategory = FestivalCategory::factory()->for($foreignEdition)->create(['account_id' => $foreignAccount->id]);
        $this->actingAs($owner)->patchJson($this->changeUrl($account, $edition, $entry), [
            'festival_category_id' => $foreignCategory->id,
            'reason' => 'Foreign category.',
        ])->assertUnprocessable()->assertJsonValidationErrors('festival_category_id');
        $this->assertNotSame($target->id, $entry->refresh()->festival_category_id);
    }

    /** @return array{Account, FestivalEdition, FestivalEntry, FestivalCategory, User, FestivalCharge} */
    private function approvedEntry(string $chargeStatus = 'paid', bool $online = false, int $targetAmount = 320000): array
    {
        Queue::fake();
        $account = Account::factory()->create(['enable_festivals' => true, 'default_currency' => 'UAH']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $workflow = app(ProvisionFestivalWorkflow::class)->execute($edition, 'Paid reassignment');
        [$source, $target] = FestivalCategory::factory()->count(2)->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
        ])->all();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        foreach ([[$source, 290000], [$target, $targetAmount]] as [$category, $amount]) {
            FestivalChargeDefinition::factory()->for($edition)->create([
                'account_id' => $account->id,
                'festival_category_id' => $category->id,
                'festival_workflow_step_id' => $workflow->steps->firstWhere('code', 'participation_payment')->id,
                'amount_cents' => $amount,
                'due_policy' => 'approval_relative',
                'due_days_after_approval' => 5,
            ]);
        }
        FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $workflow->steps->firstWhere('code', 'technical_form')->id,
            'code' => 'approved-response',
            'type' => 'custom_document',
            'input_type' => 'short_text',
            'subject_scope' => 'entry',
        ]);
        $entry = FestivalEntry::factory()->for($source)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
        ]);
        $entry->participants()->attach($participant, ['account_id' => $account->id, 'sort_order' => 0]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);
        $reviewedAt = now()->subDay();
        app(ActivateFestivalParticipationCharges::class)->execute($entry, $reviewedAt);
        $charge = $entry->charges()->firstOrFail();
        $charge->forceFill([
            'status' => $chargeStatus,
            'paid_at' => $reviewedAt,
            'refunded_at' => $chargeStatus === 'refunded' ? now() : null,
            'approved_by' => $owner->id,
        ])->save();
        if ($online) {
            $attempt = FestivalPaymentAttempt::query()->create([
                'account_id' => $account->id,
                'festival_charge_id' => $charge->id,
                'provider' => 'monopay',
                'order_id' => 'FCHP-'.str()->uuid(),
                'status' => 'paid',
                'amount_cents' => 290000,
                'currency' => 'UAH',
                'paid_at' => $reviewedAt,
                'expires_at' => $reviewedAt->copy()->addHour(),
            ]);
            $attempt->allocations()->create([
                'account_id' => $account->id,
                'festival_charge_id' => $charge->id,
                'amount_cents' => 290000,
                'currency' => 'UAH',
            ]);
        }
        $entry->steps()->whereIn('festival_workflow_step_id', $workflow->steps->whereIn('code', ['application', 'participation_payment'])->modelKeys())
            ->update(['status' => 'approved', 'reviewed_at' => $reviewedAt, 'reviewed_by' => $owner->id]);
        app(StoreFestivalResponse::class)->execute($entry->requirements->first(), $portalUser, 'Keep this approved response.');
        $entry->steps()->update(['status' => 'approved', 'reviewed_at' => $reviewedAt, 'reviewed_by' => $owner->id]);
        $entry->requirements()->update(['status' => 'accepted', 'reviewed_at' => $reviewedAt, 'reviewed_by' => $owner->id]);
        $entry->forceFill([
            'status' => FestivalEntryStatus::Accepted,
            'qualification_status' => 'passed',
            'submitted_at' => $reviewedAt,
            'accepted_at' => $reviewedAt,
            'registration_completed_at' => $reviewedAt,
            'reviewed_at' => $reviewedAt,
            'reviewed_by' => $owner->id,
            'track_artist' => 'Approved artist',
            'track_title' => 'Approved song',
            'normalized_track_key' => hash('sha256', 'approved artist|approved song'),
            'track_reserved_at' => $reviewedAt,
        ])->save();

        return [$account, $edition, $entry->refresh()->load('requirements.submissions'), $target, $owner, $charge->refresh()];
    }

    /** @param array<int, StudioPermission> $permissions */
    private function staff(Account $account, array $permissions): User
    {
        $staff = User::factory()->create();
        $account->users()->attach($staff, [
            'role' => AccountRole::Trainer->value,
            'permissions' => array_map(fn (StudioPermission $permission): string => $permission->value, $permissions),
        ]);

        return $staff;
    }

    private function changeUrl(Account $account, FestivalEdition $edition, FestivalEntry $entry): string
    {
        return route('dashboard.accounts.festivals.entries.reassign-category', [$account, $edition, $entry]);
    }
}
