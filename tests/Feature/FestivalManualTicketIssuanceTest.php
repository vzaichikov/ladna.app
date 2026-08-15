<?php

namespace Tests\Feature;

use App\Actions\Festivals\FestivalTicketScanner;
use App\Actions\Festivals\IssueManualFestivalTickets;
use App\Enums\AccountRole;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalTicketOrderSource;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FestivalTicketStatus;
use App\Enums\StudioPermission;
use App\Jobs\IssueFestivalJudgeTickets;
use App\Jobs\IssueFestivalParticipantTickets;
use App\Jobs\SendFestivalNotification;
use App\Mail\FestivalPortalMail;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalNotification;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\FestivalTariffPackage;
use App\Models\FestivalTicket;
use App\Models\FestivalTicketOrder;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class FestivalManualTicketIssuanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
    }

    public function test_manual_ticket_is_an_ordinary_paid_guest_order_with_secure_qr_and_no_payment_facts(): void
    {
        [$account, $edition, $type, $owner] = $this->festival(maxTickets: 1, inventory: 1);
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();

        $order = app(IssueManualFestivalTickets::class)->execute($edition, $guest, $type, $owner, [[
            'holder_name' => 'Invited Staff Member',
        ]]);

        $this->assertNotNull($order);
        $this->assertSame(FestivalTicketOrderSource::Manual, $order->source);
        $this->assertSame(FestivalTicketOrderStatus::Paid, $order->status);
        $this->assertSame($owner->id, $order->issued_by_user_id);
        $this->assertNotNull($order->issued_at);
        $this->assertNull($order->provider);
        $this->assertNull($order->terms_accepted_at);
        $this->assertNull($order->terms_hash);
        $this->assertSame(0, $order->amount_cents);
        $this->assertSame('manual', $order->items->first()->price_tier);
        $this->assertSame(0, $order->items->first()->unit_price_cents);
        $this->assertSame(0, $order->items->first()->total_cents);
        $this->assertSame(0, $order->fiscalReceipts()->count());

        $ticket = $order->tickets->firstOrFail();
        $this->assertSame('Invited Staff Member', $ticket->holder_name);
        $this->assertSame(hash('sha256', $ticket->token_encrypted), $ticket->token_hash);
        $this->assertNotSame($ticket->token_encrypted, DB::table('festival_tickets')->where('id', $ticket->id)->value('token_encrypted'));
        $this->assertSame('checked_in', app(FestivalTicketScanner::class)->checkIn($edition, $ticket->token_encrypted, $owner, 'qr', null, true)['state']);
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.tickets.void', [$account, $edition, $ticket]), [
            'reason' => 'Invitation withdrawn',
        ])->assertRedirect();
        $this->assertSame(FestivalTicketStatus::Voided, $ticket->refresh()->status);
        $this->assertSame('void', app(FestivalTicketScanner::class)->checkIn($edition, $ticket->token_encrypted, $owner, 'qr', null)['state']);
        $this->assertSame(0, $type->refresh()->remainingQuantity());

        $this->expectException(ValidationException::class);
        app(IssueManualFestivalTickets::class)->execute($edition, $guest, $type, $owner, [['holder_name' => 'Over capacity']]);
    }

    public function test_manual_issuance_rejects_inactive_online_cross_account_and_reversed_inputs(): void
    {
        [$account, $edition, $type, $owner, $purchase] = $this->festival();
        $guest = FestivalPortalUser::factory()->guest()->inactive()->for($account)->create();

        try {
            app(IssueManualFestivalTickets::class)->execute($edition, $guest, $type, $owner, [['holder_name' => 'Inactive']]);
            $this->fail('Inactive Guest was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('festival_portal_user_id', $exception->errors());
        }

        $activeGuest = FestivalPortalUser::factory()->guest()->for($account)->create();
        $onlineType = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'delivery_mode' => 'online_stream',
            'is_active' => true,
        ]);
        try {
            app(IssueManualFestivalTickets::class)->execute($edition, $activeGuest, $onlineType, $owner, [['holder_name' => 'Online']]);
            $this->fail('Online type was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('festival_admission_type_id', $exception->errors());
        }

        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $otherGuest = FestivalPortalUser::factory()->guest()->for($otherAccount)->create();
        try {
            app(IssueManualFestivalTickets::class)->execute($edition, $otherGuest, $type, $owner, [['holder_name' => 'Wrong account']]);
            $this->fail('A Guest from another account was accepted.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        $purchase->update(['status' => FestivalEditionPurchaseStatus::PaymentReversed]);
        $this->expectExceptionMessage(__('app.festival_payment_reversed_readonly'));
        app(IssueManualFestivalTickets::class)->execute($edition, $activeGuest, $type, $owner, [['holder_name' => 'Reversed']]);
    }

    public function test_participant_job_groups_shared_contact_deduplicates_entries_and_never_recreates_voided_tickets(): void
    {
        [$account, $edition, $type, $owner] = $this->festival(maxTickets: 10, inventory: 10);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $registrant = FestivalPortalUser::factory()->for($account)->create([
            'email' => 'accepted.owner@example.test',
            'email_normalized' => 'accepted.owner@example.test',
        ]);
        $participants = FestivalParticipant::factory()->count(2)->for($registrant)->create(['account_id' => $account->id]);
        $entries = FestivalEntry::factory()->count(2)->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $registrant->id,
            'status' => 'accepted',
        ]);
        foreach ($entries as $entry) {
            foreach ($participants as $position => $participant) {
                $entry->participants()->attach($participant->id, ['account_id' => $account->id, 'sort_order' => $position]);
            }
        }

        $job = new IssueFestivalParticipantTickets($edition->id, $registrant->id, $type->id, $owner->id);
        app()->call([$job, 'handle']);

        $guest = FestivalPortalUser::query()->whereBelongsTo($account)->forRole(FestivalPortalRole::Guest)->where('email_normalized', $registrant->email_normalized)->firstOrFail();
        $this->assertNull($guest->password);
        $order = FestivalTicketOrder::query()->where('festival_portal_user_id', $guest->id)->firstOrFail();
        $this->assertSame(2, $order->tickets()->count());
        $this->assertEqualsCanonicalizing($participants->modelKeys(), $order->tickets()->pluck('festival_participant_id')->all());
        $this->assertSame(1, FestivalNotification::query()->where('festival_ticket_order_id', $order->id)->where('channel', 'email')->count());

        $ticket = $order->tickets()->firstOrFail();
        $ticket->update(['status' => 'voided']);
        app()->call([$job, 'handle']);

        $this->assertSame(1, FestivalTicketOrder::query()->where('festival_portal_user_id', $guest->id)->count());
        $this->assertSame(2, FestivalTicket::query()->where('festival_edition_id', $edition->id)->count());
    }

    public function test_judge_job_supports_portal_and_internal_assignments_and_deduplicates_by_contact(): void
    {
        [$account, $edition, $type, $owner] = $this->festival(maxTickets: 10, inventory: 10);
        $email = 'shared.judge@example.test';
        $portalJudge = FestivalPortalUser::factory()->judge()->for($account)->create(['email' => $email, 'email_normalized' => $email]);
        $internalJudge = User::factory()->create(['name' => 'Internal Judge', 'email' => $email]);
        $portalAssignment = FestivalJudgeAssignment::factory()->for($edition)->create([
            'account_id' => $account->id,
            'user_id' => null,
            'festival_portal_user_id' => $portalJudge->id,
            'display_name' => 'Portal Judge',
        ]);
        $internalAssignment = FestivalJudgeAssignment::factory()->for($edition)->create([
            'account_id' => $account->id,
            'user_id' => $internalJudge->id,
            'festival_portal_user_id' => null,
            'display_name' => 'Internal Judge',
        ]);

        $job = new IssueFestivalJudgeTickets($edition->id, $email, [$portalAssignment->id, $internalAssignment->id], $type->id, $owner->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $ticket = FestivalTicket::query()->where('festival_edition_id', $edition->id)->firstOrFail();
        $this->assertSame('judge:'.hash('sha256', $email), $ticket->automation_key);
        $this->assertContains($ticket->festival_judge_assignment_id, [$portalAssignment->id, $internalAssignment->id]);
        $this->assertSame(1, FestivalTicket::query()->where('festival_edition_id', $edition->id)->count());
    }

    public function test_finance_can_issue_and_manage_guests_while_manual_orders_cannot_be_refunded(): void
    {
        [$account, $edition, $type, $owner] = $this->festival();
        $registrant = FestivalPortalUser::factory()->for($account)->create([
            'email' => 'role.scoped@example.test',
            'email_normalized' => 'role.scoped@example.test',
        ]);
        $createUrl = route('dashboard.accounts.festivals.users.create', [$account, $edition, 'guest']);
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.users.store', [$account, $edition, 'guest']), [
            'first_name' => 'Role',
            'last_name' => 'Scoped',
            'email' => $registrant->email,
            'phone' => null,
            'locale' => 'en',
            'password' => null,
            'password_confirmation' => null,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $guest = FestivalPortalUser::query()->whereBelongsTo($account)->forRole(FestivalPortalRole::Guest)->where('email_normalized', $registrant->email_normalized)->firstOrFail();
        $this->assertNull($guest->password);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.users.index', [$account, $edition, 'tab' => 'guests']))
            ->assertOk()
            ->assertSee($guest->email)
            ->assertSee(__('app.festival_user_tab_guests'));
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.tickets.issue', [$account, $edition, 'selected_guest_id' => $guest->id]))
            ->assertOk()
            ->assertSee(__('app.festival_handmade_ticket'));
        $this->actingAs(User::factory()->create())->get($createUrl)->assertForbidden();

        $order = app(IssueManualFestivalTickets::class)->execute($edition, $guest, $type, $owner, [['holder_name' => 'Role Scoped']]);
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.ticket-orders.refund', [$account, $edition, $order]), ['reason' => 'Not allowed'])
            ->assertUnprocessable();
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'sold', 'source' => 'manual']))
            ->assertOk()
            ->assertSee(__('app.festival_ticket_source_manual'))
            ->assertDontSee(__('app.festival_record_refund'));

        $editUrl = route('dashboard.accounts.festivals.users.edit', [$account, $edition, $guest]);
        $this->actingAs($owner)->from($editUrl)->put(route('dashboard.accounts.festivals.users.update', [$account, $edition, $guest]), [
            'first_name' => $guest->first_name,
            'last_name' => $guest->last_name,
            'email' => $guest->email,
            'phone' => null,
            'locale' => 'en',
            'password' => '',
            'password_confirmation' => '',
            'is_active' => 0,
        ])->assertSessionHasErrors('is_active');
    }

    public function test_manual_notification_resolves_bearer_only_at_delivery_and_concurrent_retries_send_once(): void
    {
        [$account, $edition, $type, $owner] = $this->festival();
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create(['locale' => 'en', 'password' => null]);
        $order = app(IssueManualFestivalTickets::class)->execute($edition, $guest, $type, $owner, [['holder_name' => 'Bearer Guest']]);
        $notification = FestivalNotification::query()->where('festival_ticket_order_id', $order->id)->where('channel', 'email')->firstOrFail();
        $expectedUrl = route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]);
        $storedPayload = (string) DB::table('festival_notifications')->where('id', $notification->id)->value('payload');

        $this->assertStringNotContainsString($order->access_token_encrypted, $storedPayload);
        app()->call([new SendFestivalNotification($notification->id), 'handle']);
        app()->call([new SendFestivalNotification($notification->id), 'handle']);

        Mail::assertSent(FestivalPortalMail::class, 1);
        Mail::assertSent(FestivalPortalMail::class, fn (FestivalPortalMail $mail): bool => $mail->actionUrl === $expectedUrl && $mail->messageLocale === 'en');
    }

    public function test_guest_directory_is_paginated_searchable_and_has_separate_management_and_issuance_permissions(): void
    {
        [$account, $edition] = $this->festival();
        $manager = $this->staff($account, [StudioPermission::ManageFestivals]);
        $finance = $this->staff($account, [StudioPermission::ManageFestivalFinance]);
        FestivalPortalUser::factory()->guest()->count(21)->for($account)->create();
        $needle = FestivalPortalUser::factory()->guest()->for($account)->create([
            'first_name' => 'Needle',
            'last_name' => 'Guest',
            'email' => 'needle.guest@example.test',
            'email_normalized' => 'needle.guest@example.test',
        ]);

        $managerPage = $this->actingAs($manager)->get(route('dashboard.accounts.festivals.users.index', [$account, $edition, 'tab' => 'guests']));
        $managerPage->assertOk()->assertSee(__('app.festival_user_tab_guests'));
        $this->assertSame(20, $managerPage->viewData('portalUsers')->count());
        $this->assertSame(22, $managerPage->viewData('portalUsers')->total());
        $this->actingAs($manager)->get(route('dashboard.accounts.festivals.users.create', [$account, $edition, 'guest']))->assertOk();
        $this->actingAs($manager)->get(route('dashboard.accounts.festivals.tickets.issue', [$account, $edition]))->assertForbidden();

        $financePage = $this->actingAs($finance)->get(route('dashboard.accounts.festivals.users.index', [
            $account,
            $edition,
            'tab' => 'guests',
            'q' => 'Needle',
            'status' => 'active',
        ]));
        $financePage->assertOk()->assertSee($needle->email);
        $this->assertSame(1, $financePage->viewData('portalUsers')->total());
        $this->actingAs($finance)->get(route('dashboard.accounts.festivals.tickets.issue', [$account, $edition]))->assertOk();
    }

    public function test_bulk_routes_dispatch_one_unique_job_per_usable_recipient_and_preview_skips_conflicts(): void
    {
        [$account, $edition, $type, $owner] = $this->festival(maxTickets: 10, inventory: 10);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $registrant = FestivalPortalUser::factory()->for($account)->create([
            'email' => 'queue.participant@example.test',
            'email_normalized' => 'queue.participant@example.test',
        ]);
        $participant = FestivalParticipant::factory()->for($registrant)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $registrant->id,
            'status' => 'accepted',
        ]);
        $entry->participants()->attach($participant->id, ['account_id' => $account->id, 'sort_order' => 0]);

        $judge = User::factory()->create(['name' => 'Queued Judge', 'email' => 'queue.judge@example.test']);
        FestivalJudgeAssignment::factory()->for($edition)->create([
            'account_id' => $account->id,
            'user_id' => $judge->id,
            'festival_portal_user_id' => null,
        ]);
        FestivalPortalUser::factory()->guest()->inactive()->for($account)->create([
            'email' => $judge->email,
            'email_normalized' => $judge->email,
        ]);

        $issuePage = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.tickets.issue', [$account, $edition]));
        $issuePage->assertOk();
        $this->assertSame(1, $issuePage->viewData('participantStats')['remaining']);
        $this->assertSame(1, $issuePage->viewData('judgeStats')['skipped']);
        $this->assertSame(0, $issuePage->viewData('judgeStats')['remaining']);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.tickets.issue.audience', [$account, $edition]), [
            'audience' => 'participants',
            'festival_admission_type_id' => $type->id,
        ])->assertRedirect();
        Queue::assertPushed(IssueFestivalParticipantTickets::class, 1);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.tickets.issue.audience', [$account, $edition]), [
            'audience' => 'judges',
            'festival_admission_type_id' => $type->id,
        ])->assertRedirect();
        Queue::assertNotPushed(IssueFestivalJudgeTickets::class);
    }

    /** @return array{Account, FestivalEdition, FestivalAdmissionType, User, FestivalEditionPurchase} */
    private function festival(int $maxTickets = 20, int $inventory = 20): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $plan = SubscriptionPlan::factory()->create(['currency' => 'UAH']);
        $package = FestivalTariffPackage::factory()->create([
            'subscription_plan_id' => $plan->id,
            'max_tickets' => $maxTickets,
        ]);
        $purchase = FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $plan->id,
            'festival_tariff_package_id' => $package->id,
            'festival_edition_id' => $edition->id,
            'status' => FestivalEditionPurchaseStatus::Redeemed,
        ]);
        $type = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'delivery_mode' => 'venue',
            'inventory' => $inventory,
            'is_active' => true,
        ]);

        return [$account, $edition, $type, $owner, $purchase];
    }

    /** @param list<StudioPermission> $permissions */
    private function staff(Account $account, array $permissions): User
    {
        $staff = User::factory()->create();
        $account->users()->attach($staff->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => array_map(fn (StudioPermission $permission): string => $permission->value, $permissions),
        ]);

        return $staff;
    }
}
