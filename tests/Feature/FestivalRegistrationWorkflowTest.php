<?php

namespace Tests\Feature;

use App\Actions\Festivals\StoreFestivalSubmission;
use App\Actions\Festivals\SubmitFestivalEntry;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalSubmissionStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Event;
use App\Models\FestivalCategory;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalParticipant;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FestivalRegistrationWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_solo_and_group_rules_use_age_reference_date_and_submission_freezes_configuration(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'min_members' => 2,
            'max_members' => 3,
            'min_age' => 12,
            'max_age' => 17,
            'workflow' => 'qualification',
        ]);
        $participants = collect([
            FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id, 'date_of_birth' => $edition->age_reference_date->copy()->subYears(13)]),
            FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id, 'date_of_birth' => $edition->age_reference_date->copy()->subYears(15)]),
        ]);
        $requirement = FestivalRequirementDefinition::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Qualification video', 'type' => 'qualification_video']);
        $chargeDefinition = FestivalChargeDefinition::factory()->for($edition)->create(['account_id' => $account->id, 'amount_cents' => 50000]);
        $entry = FestivalEntry::factory()->for($category)->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_portal_user_id' => $portalUser->id]);
        $entry->participants()->sync($participants->values()->mapWithKeys(fn ($participant, $index): array => [$participant->id => ['account_id' => $account->id, 'sort_order' => $index, 'age_snapshot' => $participant->date_of_birth->diffInYears($edition->age_reference_date), 'name_snapshot' => $participant->displayName()]])->all());

        $submitted = app(SubmitFestivalEntry::class)->execute($entry);

        $this->assertSame(FestivalEntryStatus::Submitted, $submitted->status);
        $this->assertSame('qualification', $submitted->category_snapshot['workflow']);
        $this->assertSame([13, 15], $submitted->participants->pluck('pivot.age_snapshot')->sort()->values()->all());
        $this->assertNotNull($category->refresh()->locked_at);
        $this->assertNotNull($requirement->refresh()->locked_at);
        $this->assertNotNull($chargeDefinition->refresh()->locked_at);
        $this->assertSame('Qualification video', $submitted->requirements->first()->definition_snapshot['name']);
        $this->assertSame(50000, $submitted->charges->first()->amount_cents);
    }

    public function test_invalid_member_count_and_age_are_rejected(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'min_members' => 2, 'max_members' => 2, 'min_age' => 18]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id, 'date_of_birth' => $edition->age_reference_date->copy()->subYears(12)]);
        $entry = FestivalEntry::factory()->for($category)->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_portal_user_id' => $portalUser->id]);
        $entry->participants()->sync([$participant->id => ['account_id' => $account->id, 'sort_order' => 0, 'age_snapshot' => 12, 'name_snapshot' => $participant->displayName()]]);

        $this->expectException(ValidationException::class);
        app(SubmitFestivalEntry::class)->execute($entry);
    }

    public function test_private_submissions_keep_versions_and_enforce_portal_tenancy(): void
    {
        Storage::fake('local');
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        FestivalRequirementDefinition::factory()->for($edition)->create(['account_id' => $account->id, 'type' => 'custom_document', 'allowed_extensions' => ['png'], 'allowed_mime_types' => ['image/png']]);
        $entry = FestivalEntry::factory()->for($category)->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_portal_user_id' => $portalUser->id]);
        $entry->participants()->sync([$participant->id => ['account_id' => $account->id, 'sort_order' => 0, 'age_snapshot' => 18, 'name_snapshot' => $participant->displayName()]]);
        $entry = app(SubmitFestivalEntry::class)->execute($entry);
        $requirement = $entry->requirements->first();

        $first = app(StoreFestivalSubmission::class)->execute($requirement, $portalUser, UploadedFile::fake()->image('proof.png'));
        $second = app(StoreFestivalSubmission::class)->execute($requirement, $portalUser, UploadedFile::fake()->image('replacement.png'));

        $this->assertSame(FestivalSubmissionStatus::Superseded, $first->refresh()->status);
        $this->assertSame(2, $second->version);
        $this->assertSame(FestivalRequirementStatus::Submitted, $requirement->refresh()->status);
        Storage::disk('local')->assertExists($first->path);
        Storage::disk('local')->assertExists($second->path);

        $this->actingAs($portalUser, 'festival')->get(route('festival.portal.submissions.download', [$account->slug, $second]))->assertOk();
        $otherPortalUser = FestivalPortalUser::factory()->for($account)->create();
        $this->actingAs($otherPortalUser, 'festival')->get(route('festival.portal.submissions.download', [$account->slug, $second]))->assertNotFound();
    }

    public function test_payment_callback_is_idempotent_and_rejects_wrong_amount(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($category)->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_portal_user_id' => $portalUser->id]);
        $charge = $entry->charges()->create(['account_id' => $account->id, 'code' => 'FCH-TEST', 'kind' => 'participation', 'name' => 'Fee', 'amount_cents' => 10000, 'currency' => 'UAH']);
        $attempt = FestivalPaymentAttempt::query()->create(['account_id' => $account->id, 'festival_charge_id' => $charge->id, 'provider' => 'monopay', 'order_id' => 'FCHP-TEST', 'amount_cents' => 10000, 'currency' => 'UAH', 'expires_at' => now()->addMinutes(30)]);
        $callback = new PaymentCallbackResult(orderId: 'FCHP-TEST', status: PaymentCallbackStatus::Paid, amountCents: 10000, currency: 'UAH', gatewayPaymentId: 'pay-1');

        app(FestivalPaymentService::class)->completeAttempt($attempt, $callback);
        app(FestivalPaymentService::class)->completeAttempt($attempt->refresh(), $callback);
        $this->assertSame(FestivalChargeStatus::Paid, $charge->refresh()->status);
        $this->assertSame('pay-1', $attempt->refresh()->gateway_payment_id);

        $otherAttempt = FestivalPaymentAttempt::query()->create(['account_id' => $account->id, 'festival_charge_id' => $charge->id, 'provider' => 'monopay', 'order_id' => 'FCHP-WRONG', 'amount_cents' => 10000, 'currency' => 'UAH']);
        $this->expectException(InvalidPaymentCallbackException::class);
        app(FestivalPaymentService::class)->completeAttempt($otherAttempt, new PaymentCallbackResult(orderId: 'FCHP-WRONG', status: PaymentCallbackStatus::Paid, amountCents: 9999, currency: 'UAH'));
    }

    public function test_registration_flow_never_creates_customer_or_event_records(): void
    {
        $customers = Customer::query()->count();
        $events = Event::query()->count();
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);

        $this->actingAs($portalUser, 'festival')->post(route('festival.portal.entries.store', [$account->slug, $edition->slug]), [
            'festival_category_id' => $category->id,
            'participant_ids' => [$participant->id],
            'performer_name' => 'Independent act',
        ])->assertRedirect();

        $this->assertSame($customers, Customer::query()->count());
        $this->assertSame($events, Event::query()->count());
        $this->assertDatabaseHas('festival_entries', ['account_id' => $account->id, 'festival_portal_user_id' => $portalUser->id]);
    }

    /** @return array{Account, FestivalEdition, FestivalPortalUser} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id, 'age_reference_date' => now()->addMonth()->toDateString()]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();

        return [$account, $edition, $portalUser];
    }
}
