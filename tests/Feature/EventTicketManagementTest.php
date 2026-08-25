<?php

namespace Tests\Feature;

use App\Actions\CreateEventOrder;
use App\Actions\IssueEventTickets;
use App\Actions\IssueManualEventTickets;
use App\Enums\AccountRole;
use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Enums\EventTicketStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Mail\TransactionalMail;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventTicketType;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EventTicketManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ticket_type_pages_persist_update_and_retain_invalid_input(): void
    {
        [$owner, $account, $event] = $this->managedEvent();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.events.ticket-types.store', [$account, $event]), [
                ...$this->ticketTypePayload(),
                'name' => 'Retained ticket name',
                'price' => '12.345',
            ])
            ->assertSessionHasErrors('price')
            ->assertSessionHasInput('name', 'Retained ticket name');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.events.ticket-types.store', [$account, $event]), [
                ...$this->ticketTypePayload(),
                'name' => 'General admission',
                'price' => '125.50',
            ])
            ->assertRedirect(route('dashboard.accounts.events.ticket-types.index', [$account, $event]));

        $ticketType = $event->ticketTypes()->where('name', 'General admission')->sole();
        $this->assertSame(12550, $ticketType->price_cents);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.ticket-types.edit', [$account, $event, $ticketType]))
            ->assertOk()
            ->assertSee('value="125.50"', false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.events.ticket-types.update', [$account, $event, $ticketType]), [
                ...$this->ticketTypePayload(),
                'name' => 'Updated admission',
                'price' => '140.00',
            ])
            ->assertRedirect(route('dashboard.accounts.events.ticket-types.index', [$account, $event]));

        $this->assertDatabaseHas('event_ticket_types', [
            'id' => $ticketType->id,
            'name' => 'Updated admission',
            'price_cents' => 14000,
        ]);
    }

    public function test_ticket_type_routes_enforce_authorization_and_account_scope(): void
    {
        $owner = User::factory()->create();
        $receptionist = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $account->users()->attach($receptionist, ['role' => AccountRole::Receptionist->value]);
        $otherEvent = Event::factory()->for($otherAccount)->create();
        $otherType = EventTicketType::factory()->for($otherAccount)->for($otherEvent)->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.ticket-types.edit', [$account, $otherEvent, $otherType]))
            ->assertNotFound();

        $event = Event::factory()->for($account)->create();

        $this->actingAs($receptionist)
            ->get(route('dashboard.accounts.events.ticket-types.index', [$account, $event]))
            ->assertForbidden();

        $scannerResponse = $this->actingAs($receptionist)
            ->get(route('dashboard.accounts.events.scanner', [$account, $event]))
            ->assertOk()
            ->assertSee(__('app.event_scanner'))
            ->assertSee(route('dashboard.accounts.events.attendance', [$account, $event]), false)
            ->assertDontSee(__('app.event_ticket_types'));

        $scannerResponse->assertSeeInOrder([
            route('dashboard.accounts.events.scanner', [$account, $event]),
            route('dashboard.accounts.events.attendance', [$account, $event]),
        ], false);
    }

    public function test_published_event_protects_used_and_last_active_ticket_types(): void
    {
        [$owner, $account, $event] = $this->managedEvent(published: true);
        $usedType = EventTicketType::factory()->for($account)->for($event)->create(['price_cents' => 0]);
        $order = $this->createOnlineOrder($event, $usedType, 'Used ticket guest');

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.events.ticket-types.destroy', [$account, $event, $usedType]))
            ->assertUnprocessable();

        $lastUnusedType = EventTicketType::factory()->for($account)->for($event)->create();
        $usedType->update(['is_active' => false]);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.events.ticket-types.destroy', [$account, $event, $lastUnusedType]))
            ->assertUnprocessable();

        $this->assertDatabaseHas('event_order_items', [
            'event_order_id' => $order->id,
            'event_ticket_type_id' => $usedType->id,
        ]);
    }

    public function test_publish_requires_a_persisted_active_ticket_type_in_ui_and_server(): void
    {
        [$owner, $account, $event] = $this->managedEvent();
        $publishUrl = route('dashboard.accounts.events.publish', [$account, $event]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.edit', [$account, $event]))
            ->assertOk()
            ->assertDontSee('action="'.$publishUrl.'"', false)
            ->assertSee(__('app.event_publish_needs_ticket_type'));

        $this->actingAs($owner)
            ->post($publishUrl)
            ->assertSessionHasErrors('tickets');

        $ticketType = EventTicketType::factory()->for($account)->for($event)->create(['is_active' => false]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.edit', [$account, $event]))
            ->assertDontSee('action="'.$publishUrl.'"', false);

        $ticketType->update(['is_active' => true]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.edit', [$account, $event]))
            ->assertSee('action="'.$publishUrl.'"', false);

        $this->actingAs($owner)->post($publishUrl)->assertRedirect();
        $this->assertSame(EventStatus::Published, $event->refresh()->status);
    }

    #[DataProvider('offlinePaymentMethods')]
    public function test_paid_manual_issuance_uses_regular_price_and_records_offline_method(string $paymentMethod): void
    {
        Mail::fake();
        [$owner, $account, $event] = $this->managedEvent(published: true);
        $event->update(['capacity' => 10]);
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 50000,
            'early_bird_price_cents' => 30000,
            'early_bird_ends_at' => now()->addDay(),
            'early_bird_quota' => 10,
            'sales_starts_at' => now()->addMonth(),
            'max_per_order' => 1,
            'inventory' => 10,
        ]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.events.tickets.issue.store', [$account, $event]), [
                'ticket_type_id' => $ticketType->id,
                'quantity' => 2,
                'buyer_name' => 'Door guest',
                'buyer_email' => null,
                'buyer_phone' => '+380501234567',
                'payment_kind' => 'paid',
                'payment_method' => $paymentMethod,
            ])
            ->assertRedirect(route('dashboard.accounts.events.tickets.index', [$account, $event]));

        $order = EventOrder::query()->where('issued_by', $owner->id)->latest('id')->firstOrFail();
        $this->assertSame(EventOrderStatus::Paid, $order->status);
        $this->assertSame('manual_'.$paymentMethod, $order->provider);
        $this->assertSame(100000, $order->amount_cents);
        $this->assertNull($order->buyer_email);
        $this->assertNull($order->terms_accepted_at);
        $this->assertNull($order->terms_hash);
        $this->assertSame(2, $order->tickets()->count());
        $this->assertSame('regular', $order->items()->sole()->price_tier);
        $this->assertSame(50000, $order->items()->sole()->unit_price_cents);
        $this->assertSame(2, $order->tickets()->distinct('code')->count('code'));
        Mail::assertNothingQueued();
    }

    public function test_complimentary_manual_issuance_sends_existing_ticket_email_when_email_is_present(): void
    {
        Mail::fake();
        $this->enableMailDelivery();
        [$owner, $account, $event] = $this->managedEvent(published: true);
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 50000,
            'inventory' => 10,
        ]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.events.tickets.issue.store', [$account, $event]), [
                'ticket_type_id' => $ticketType->id,
                'quantity' => 1,
                'buyer_name' => 'Complimentary guest',
                'buyer_email' => 'guest@example.com',
                'payment_kind' => 'complimentary',
            ])
            ->assertRedirect();

        $order = EventOrder::query()->where('buyer_email', 'guest@example.com')->sole();
        $this->assertSame(0, $order->amount_cents);
        $this->assertNull($order->provider);
        $this->assertSame($owner->id, $order->issued_by);
        $this->assertSame('complimentary', $order->items()->sole()->price_tier);
        Mail::assertQueued(TransactionalMail::class);
    }

    public function test_manual_issuance_enforces_event_capacity_and_inventory(): void
    {
        [$owner, $account, $event] = $this->managedEvent(published: true);
        $event->update(['capacity' => 1]);
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 0,
            'inventory' => 1,
        ]);
        $this->createOnlineOrder($event, $ticketType, 'Capacity guest');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.events.tickets.issue.store', [$account, $event]), $this->manualPayload($ticketType))
            ->assertSessionHasErrors('quantity');

        $this->assertSame(1, $event->orders()->count());
        $this->assertSame(1, $event->tickets()->count());
    }

    public function test_manual_issuance_rejects_inactive_types_drafts_and_ended_events(): void
    {
        [$owner, $account, $draft] = $this->managedEvent();
        $draftType = EventTicketType::factory()->for($account)->for($draft)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.events.tickets.issue.store', [$account, $draft]), $this->manualPayload($draftType))
            ->assertSessionHasErrors('event');

        $ended = Event::factory()->published()->for($account)->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);
        $endedType = EventTicketType::factory()->for($account)->for($ended)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.events.tickets.issue.store', [$account, $ended]), $this->manualPayload($endedType))
            ->assertSessionHasErrors('event');

        $active = Event::factory()->published()->for($account)->create();
        $inactiveType = EventTicketType::factory()->for($account)->for($active)->create(['is_active' => false]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.events.tickets.issue.store', [$account, $active]), $this->manualPayload($inactiveType))
            ->assertSessionHasErrors('ticket_type_id');
    }

    public function test_manual_issuance_rolls_back_order_and_items_when_ticket_generation_fails(): void
    {
        [$owner, $account, $event] = $this->managedEvent(published: true);
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create();
        $ticketIssuer = Mockery::mock(IssueEventTickets::class);
        $ticketIssuer->shouldReceive('execute')->once()->andThrow(new RuntimeException('Ticket generation failed.'));
        $action = new IssueManualEventTickets($ticketIssuer);

        try {
            $action->execute($account, $event, $owner, $this->manualPayload($ticketType), 'en');
            $this->fail('The ticket generation exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Ticket generation failed.', $exception->getMessage());
        }

        $this->assertSame(0, $event->orders()->count());
        $this->assertSame(0, $event->tickets()->count());
        $this->assertDatabaseMissing('event_order_items', ['event_id' => $event->id]);
    }

    public function test_issued_ticket_list_filters_by_every_supported_field_and_source(): void
    {
        [$owner, $account, $event] = $this->managedEvent(published: true);
        $onlineType = EventTicketType::factory()->for($account)->for($event)->create(['price_cents' => 0]);
        $manualType = EventTicketType::factory()->for($account)->for($event)->create(['price_cents' => 0]);
        $onlineOrder = $this->createOnlineOrder($event, $onlineType, 'Online Alice', 'alice@example.com');
        $manualOrder = app(IssueManualEventTickets::class)->execute($account, $event, $owner, [
            ...$this->manualPayload($manualType),
            'buyer_name' => 'Manual Bob',
            'buyer_phone' => '+380991112233',
        ], 'uk');
        $onlineTicket = $onlineOrder->tickets()->sole();
        $manualTicket = $manualOrder->tickets()->sole();
        $manualTicket->update(['is_checked_in' => true, 'checked_in_at' => now()]);
        $onlineTicket->update(['status' => EventTicketStatus::Voided]);

        $this->assertTicketFilter($owner, $account, $event, ['q' => $manualTicket->code], [$manualTicket->id]);
        $this->assertTicketFilter($owner, $account, $event, ['q' => $onlineOrder->order_id], [$onlineTicket->id]);
        $this->assertTicketFilter($owner, $account, $event, ['q' => 'alice@example.com'], [$onlineTicket->id]);
        $this->assertTicketFilter($owner, $account, $event, ['q' => '+380991112233'], [$manualTicket->id]);
        $this->assertTicketFilter($owner, $account, $event, ['ticket_type' => $manualType->id], [$manualTicket->id]);
        $this->assertTicketFilter($owner, $account, $event, ['status' => 'voided'], [$onlineTicket->id]);
        $this->assertTicketFilter($owner, $account, $event, ['check_in' => 'checked_in'], [$manualTicket->id]);
        $this->assertTicketFilter($owner, $account, $event, ['source' => 'manual'], [$manualTicket->id]);
        $this->assertTicketFilter($owner, $account, $event, ['source' => 'checkout'], [$onlineTicket->id]);

        $otherAccount = Account::factory()->create();
        $otherEvent = Event::factory()->published()->for($otherAccount)->create();
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.tickets.index', [$account, $otherEvent]))
            ->assertNotFound();
    }

    public function test_issued_ticket_list_is_newest_first_paginated_and_retains_query_string(): void
    {
        [$owner, $account, $event] = $this->managedEvent(published: true);
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 0,
            'inventory' => 100,
            'max_per_order' => 100,
        ]);
        $order = app(CreateEventOrder::class)->execute($event, [
            'buyer_name' => 'Bulk Guest',
            'buyer_email' => 'bulk@example.com',
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 21]],
            'accept_terms' => true,
        ], 'uk');
        $newestTicketId = $order->tickets()->max('id');

        $response = $this->actingAs($owner)->get(route('dashboard.accounts.events.tickets.index', [
            $account,
            $event,
            'q' => 'Bulk Guest',
            'source' => 'checkout',
        ]))->assertOk();

        $tickets = $response->viewData('tickets');
        $this->assertSame(20, $tickets->count());
        $this->assertSame($newestTicketId, $tickets->first()->id);
        $this->assertStringContainsString('q=Bulk%20Guest', $tickets->url(2));
        $this->assertStringContainsString('source=checkout', $tickets->url(2));
    }

    public function test_issued_ticket_list_displays_each_ticket_unit_price_instead_of_the_order_total(): void
    {
        [$owner, $account, $event] = $this->managedEvent(published: true);
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 60000,
            'inventory' => 10,
        ]);
        $order = EventOrder::factory()->for($account)->for($event)->create([
            'status' => EventOrderStatus::Paid,
            'amount_cents' => 120000,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'account_id' => $account->id,
            'event_id' => $event->id,
            'event_ticket_type_id' => $ticketType->id,
            'ticket_type_name' => $ticketType->name,
            'price_tier' => 'regular',
            'unit_price_cents' => 60000,
            'quantity' => 2,
            'total_cents' => 120000,
        ]);
        app(IssueEventTickets::class)->execute($order);

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.tickets.index', [$account, $event]))
            ->assertOk()
            ->assertDontSee(MoneyFormatter::format(120000, 'UAH'));

        $this->assertSame(2, substr_count($response->getContent(), MoneyFormatter::format(60000, 'UAH')));
    }

    public function test_email_less_manual_order_renders_without_resend_action(): void
    {
        [$owner, $account, $event] = $this->managedEvent(published: true);
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create();
        $order = app(IssueManualEventTickets::class)->execute(
            $account,
            $event,
            $owner,
            $this->manualPayload($ticketType),
            'uk',
        );

        $resendUrl = route('dashboard.accounts.events.orders.resend', [$account, $event, $order]);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.orders.index', [$account, $event]))
            ->assertOk()
            ->assertSee($order->buyer_name)
            ->assertDontSee('action="'.$resendUrl.'"', false);

        $this->actingAs($owner)->post($resendUrl)->assertUnprocessable();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function offlinePaymentMethods(): array
    {
        return [
            'cash' => ['cash'],
            'card' => ['card'],
            'bank transfer' => ['bank_transfer'],
            'other' => ['other'],
        ];
    }

    /**
     * @return array{User, Account, Event}
     */
    private function managedEvent(bool $published = false): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $eventFactory = Event::factory()->for($account);
        $event = $published ? $eventFactory->published()->create() : $eventFactory->create();

        return [$owner, $account, $event];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketTypePayload(): array
    {
        return [
            'name' => 'Admission',
            'description' => 'Ticket description',
            'inventory' => 20,
            'price' => '100.00',
            'early_bird_price' => null,
            'early_bird_ends_at' => null,
            'early_bird_quota' => null,
            'sales_starts_at' => null,
            'sales_ends_at' => null,
            'max_per_order' => 10,
            'is_active' => 1,
            'sort_order' => 10,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manualPayload(EventTicketType $ticketType): array
    {
        return [
            'ticket_type_id' => $ticketType->id,
            'quantity' => 1,
            'buyer_name' => 'Manual guest',
            'buyer_email' => null,
            'buyer_phone' => null,
            'payment_kind' => 'complimentary',
            'payment_method' => null,
        ];
    }

    private function createOnlineOrder(
        Event $event,
        EventTicketType $ticketType,
        string $buyerName,
        string $buyerEmail = 'online@example.com',
    ): EventOrder {
        return app(CreateEventOrder::class)->execute($event, [
            'buyer_name' => $buyerName,
            'buyer_email' => $buyerEmail,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
            'accept_terms' => true,
        ], 'uk');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $expectedIds
     */
    private function assertTicketFilter(
        User $owner,
        Account $account,
        Event $event,
        array $filters,
        array $expectedIds,
    ): void {
        $response = $this->actingAs($owner)->get(route('dashboard.accounts.events.tickets.index', [
            $account,
            $event,
            ...$filters,
        ]))->assertOk();

        $this->assertSame($expectedIds, $response->viewData('tickets')->pluck('id')->all());
    }

    private function enableMailDelivery(): void
    {
        IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::MailDelivery->value,
            'category' => IntegrationCategory::Email->value,
            'is_enabled' => true,
            'credentials' => [
                'engine' => 'log',
                'fallback_engine' => 'log',
                'mail_from_email' => 'events@ladna.test',
                'mail_from_name' => 'Ladna Events',
            ],
        ]);
    }
}
