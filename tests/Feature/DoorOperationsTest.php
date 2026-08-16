<?php

namespace Tests\Feature;

use App\Actions\EventDoorTicketSale;
use App\Actions\EventTicketScanner;
use App\Actions\IssueEventTickets;
use App\Actions\RecordEventCashEntry;
use App\Enums\AccountRole;
use App\Enums\EventOrderSource;
use App\Enums\EventOrderStatus;
use App\Enums\EventTicketStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventCashEntry;
use App\Models\EventOrder;
use App\Models\EventTicketType;
use App\Models\FestivalCashEntry;
use App\Models\IntegrationSetting;
use App\Models\StudioCashEntry;
use App\Models\User;
use App\Support\Fiscalization\FiscalReceiptService;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use Tests\TestCase;

class DoorOperationsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
    }

    public function test_event_cash_sale_is_paid_issued_idempotent_and_recorded_only_in_its_event_cash_desk(): void
    {
        [$account, $event, $ticketType, $operator] = $this->eventDoor();
        $input = [
            'ticket_type_id' => $ticketType->id,
            'guest_name' => 'Lost Ticket Guest',
            'guest_email' => null,
            'idempotency_key' => (string) Str::uuid(),
        ];

        $order = app(EventDoorTicketSale::class)->execute(
            $account,
            $event,
            $operator,
            $input,
            EventDoorTicketSale::ModeCash,
            'en',
        );

        $this->assertSame(EventOrderSource::Entrance, $order->source);
        $this->assertSame(EventOrderStatus::Paid, $order->status);
        $this->assertSame('entrance_cash', $order->provider);
        $this->assertSame($ticketType->price_cents, $order->amount_cents);
        $this->assertSame($operator->id, $order->issued_by);
        $this->assertNull($order->buyer_email);
        $this->assertCount(1, $order->items);
        $this->assertSame('regular', $order->items->first()->price_tier);
        $this->assertCount(1, $order->tickets);
        $this->assertSame(EventTicketStatus::Valid, $order->tickets->first()->status);
        $this->assertFalse($order->tickets->first()->is_checked_in);

        $entry = EventCashEntry::query()->whereBelongsTo($order, 'order')->firstOrFail();
        $this->assertSame($account->id, $entry->account_id);
        $this->assertSame($event->id, $entry->event_id);
        $this->assertSame(EventCashEntry::DirectionIn, $entry->direction);
        $this->assertSame(EventCashEntry::PurposeEntranceTicketSale, $entry->purpose);
        $this->assertSame($ticketType->price_cents, $entry->amount_cents);
        $this->assertSame('UAH', $entry->currency);
        $this->assertSame($operator->id, $entry->actor_user_id);
        $this->assertSame($operator->name, $entry->actor_name);
        $this->assertSame(AccountRole::Receptionist->value, $entry->actor_role);
        $this->assertSame(0, StudioCashEntry::query()->count());
        $this->assertSame(0, FestivalCashEntry::query()->count());

        $replayed = app(EventDoorTicketSale::class)->execute(
            $account,
            $event,
            $operator,
            $input,
            EventDoorTicketSale::ModeCash,
            'en',
        );

        $this->assertTrue($order->is($replayed));
        $this->assertSame(1, EventOrder::query()->where('order_id', $order->order_id)->count());
        $this->assertSame(1, $order->tickets()->count());
        $this->assertSame(1, EventCashEntry::query()->where('event_order_id', $order->id)->count());

        $owner = User::factory()->create();
        $account->addOwner($owner);
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.events.orders.refund', [$account, $event, $order]), [
                'reason' => 'Cash returned to the guest at the entrance.',
            ])
            ->assertRedirect();

        $this->assertSame(EventOrderStatus::Refunded, $order->refresh()->status);
        $this->assertSame(EventTicketStatus::Refunded, $order->tickets()->firstOrFail()->status);
        $this->assertSame(2, $order->cashEntries()->count());
        $this->assertSame(
            [EventCashEntry::DirectionIn, EventCashEntry::DirectionOut],
            $order->cashEntries()->orderBy('id')->pluck('direction')->all(),
        );
        $this->assertSame(
            [EventCashEntry::PurposeEntranceTicketSale, EventCashEntry::PurposeEntranceTicketRefund],
            $order->cashEntries()->orderBy('id')->pluck('purpose')->all(),
        );
        $this->assertSame(0, StudioCashEntry::query()->count());
    }

    public function test_event_card_sale_holds_one_ticket_without_issuing_it_or_touching_any_cash_desk(): void
    {
        [$account, $event, $ticketType, $operator] = $this->eventDoor();
        $action = $this->eventDoorSaleWithProvider($account, 'monopay');

        $order = $action->execute($account, $event, $operator, [
            'ticket_type_id' => $ticketType->id,
            'guest_name' => 'Card Guest',
            'guest_email' => 'Card.Guest@Example.Test',
            'provider' => 'monopay',
            'idempotency_key' => (string) Str::uuid(),
            'terms_accepted' => true,
        ], EventDoorTicketSale::ModeCard, 'uk');

        $this->assertSame(EventOrderSource::Entrance, $order->source);
        $this->assertSame(EventOrderStatus::Pending, $order->status);
        $this->assertSame('monopay', $order->provider);
        $this->assertSame('card.guest@example.test', $order->buyer_email);
        $this->assertNotNull($order->expires_at);
        $this->assertNotNull($order->terms_accepted_at);
        $this->assertSame(0, $order->tickets()->count());
        $this->assertSame(0, EventCashEntry::query()->count());
        $this->assertSame(0, StudioCashEntry::query()->count());
    }

    public function test_event_door_sale_rejects_a_ticket_type_outside_the_selected_event(): void
    {
        [$account, $event, , $operator] = $this->eventDoor();
        $otherEvent = Event::factory()->published()->for($account)->create();
        $otherType = EventTicketType::factory()->for($account)->for($otherEvent)->create();

        try {
            app(EventDoorTicketSale::class)->execute($account, $event, $operator, [
                'ticket_type_id' => $otherType->id,
                'guest_name' => 'Wrong Event Guest',
                'idempotency_key' => (string) Str::uuid(),
            ], EventDoorTicketSale::ModeCash, 'uk');
            $this->fail('A ticket type from another Event was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ticket_type_id', $exception->errors());
        }

        $this->assertSame(0, EventOrder::query()->where('event_id', $event->id)->count());
        $this->assertSame(0, EventCashEntry::query()->count());
    }

    public function test_event_cash_ledger_entries_are_append_only(): void
    {
        [$account, $event, $ticketType, $operator] = $this->eventDoor();
        $order = app(EventDoorTicketSale::class)->execute($account, $event, $operator, [
            'ticket_type_id' => $ticketType->id,
            'guest_name' => 'Immutable Guest',
            'idempotency_key' => (string) Str::uuid(),
        ], EventDoorTicketSale::ModeCash, 'uk');
        $entry = $order->cashEntries()->firstOrFail();

        $this->assertThrows(
            fn () => $entry->update(['amount_cents' => 1]),
            LogicException::class,
        );
        $this->assertThrows(
            fn () => $entry->fresh()->delete(),
            LogicException::class,
        );
        $this->assertSame($ticketType->price_cents, $entry->fresh()->amount_cents);
        $this->assertModelExists($entry);
    }

    public function test_event_guest_search_monitor_and_reasoned_undo_share_the_door_staff_boundary(): void
    {
        [$account, $event, $ticketType, $operator] = $this->eventDoor();
        $order = app(EventDoorTicketSale::class)->execute($account, $event, $operator, [
            'ticket_type_id' => $ticketType->id,
            'guest_name' => 'Searchable Event Guest',
            'guest_email' => 'searchable.event@example.test',
            'idempotency_key' => (string) Str::uuid(),
        ], EventDoorTicketSale::ModeCash, 'uk');
        $ticket = $order->tickets()->firstOrFail();

        $search = $this->actingAs($operator)
            ->getJson(route('dashboard.accounts.events.entrance.search', [$account, $event, 'q' => $ticket->code]))
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('results.0.order_id', $order->id)
            ->assertJsonPath('results.0.guest.name', 'Searchable Event Guest')
            ->assertJsonPath('results.0.tickets.0.code', $ticket->code)
            ->assertJsonPath('results.0.tickets.0.can_admit', true);
        $this->assertStringContainsString('private', (string) $search->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $search->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=0', (string) $search->headers->get('Cache-Control'));
        $this->assertNotSame('searchable.event@example.test', $search->json('results.0.guest.email'));

        app(EventTicketScanner::class)->checkIn($event, $ticket->code, $operator, 'guest_search', '203.0.113.10', true);

        $this->actingAs($operator)
            ->getJson(route('dashboard.accounts.events.attendance.data', [$account, $event]))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('passed', 1)
            ->assertJsonPath('unpassed', 0)
            ->assertJsonPath('cash.amount_cents', $ticketType->price_cents)
            ->assertJsonPath('tickets.0.code', $ticket->code)
            ->assertJsonPath('tickets.0.passed', true);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
            ->postJson(route('dashboard.accounts.events.attendance.tickets.undo', [$account, $event, $ticket]), [
                'reason' => 'Operator admitted the adjacent guest by mistake.',
            ])
            ->assertOk()
            ->assertJsonPath('state', 'checked_out');

        $this->assertFalse($ticket->refresh()->is_checked_in);
        $this->assertNull($ticket->checked_in_at);
        $this->assertDatabaseHas('event_ticket_check_ins', [
            'event_ticket_id' => $ticket->id,
            'action' => 'check_out',
            'source' => 'monitor',
            'request_ip' => '203.0.113.11',
            'reason' => 'Operator admitted the adjacent guest by mistake.',
        ]);

        $scanOnlyStaff = User::factory()->create();
        $account->users()->attach($scanOnlyStaff, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::CheckInEventTickets->value],
        ]);
        $this->actingAs($scanOnlyStaff)
            ->getJson(route('dashboard.accounts.events.entrance.search', [$account, $event, 'q' => $ticket->code]))
            ->assertForbidden();
        $this->actingAs($scanOnlyStaff)
            ->getJson(route('dashboard.accounts.events.attendance.data', [$account, $event]))
            ->assertForbidden();
    }

    public function test_door_staff_permission_is_explicit_and_defaulted_only_to_operational_roles(): void
    {
        $this->assertContains(StudioPermission::DoorStaff, AccountRole::Manager->defaultPermissions());
        $this->assertContains(StudioPermission::DoorStaff, AccountRole::Receptionist->defaultPermissions());
        $this->assertNotContains(StudioPermission::DoorStaff, AccountRole::Trainer->defaultPermissions());
        $this->assertSame('events_and_tools', StudioPermission::DoorStaff->group());
        $this->assertSame('high', StudioPermission::DoorStaff->sensitivity());

        $account = Account::factory()->create();
        $scanOnlyStaff = User::factory()->create();
        $account->users()->attach($scanOnlyStaff, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::CheckInEventTickets->value],
        ]);

        $this->assertTrue(Gate::forUser($scanOnlyStaff)->allows('checkInEventTickets', $account));
        $this->assertFalse(Gate::forUser($scanOnlyStaff)->allows('doorStaff', $account));
        $this->assertFalse(Gate::forUser($scanOnlyStaff)->allows('checkInFestivalTickets', $account));
    }

    /** @return array{Account, Event, EventTicketType, User} */
    private function eventDoor(): array
    {
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $operator = User::factory()->create();
        $account->users()->attach($operator, ['role' => AccountRole::Receptionist->value]);
        $event = Event::factory()->published()->for($account)->create([
            'currency' => 'UAH',
            'capacity' => 20,
        ]);
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'inventory' => 20,
            'price_cents' => 32500,
            'is_active' => true,
        ]);

        return [$account, $event, $ticketType, $operator];
    }

    private function eventDoorSaleWithProvider(Account $account, string $provider): EventDoorTicketSale
    {
        $setting = new IntegrationSetting(['provider' => $provider, 'is_enabled' => true]);
        $setting->account_id = $account->id;
        $gateways = Mockery::mock(PaymentGatewayRegistry::class);
        $gateways->shouldReceive('availableSettingsFor')
            ->with(Mockery::on(fn (Account $candidate): bool => $candidate->is($account)))
            ->once()
            ->andReturn(collect([$setting]));

        return new EventDoorTicketSale(
            app(IssueEventTickets::class),
            app(RecordEventCashEntry::class),
            $gateways,
            app(TransactionalMailDispatcher::class),
            app(FiscalReceiptService::class),
        );
    }
}
