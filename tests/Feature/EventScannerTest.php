<?php

namespace Tests\Feature;

use App\Actions\CreateEventOrder;
use App\Enums\AccountRole;
use App\Enums\EventTicketStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventTicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EventScannerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_receptionist_previews_then_confirms_once_and_duplicate_returns_ticket_details(): void
    {
        $account = Account::factory()->create();
        $receptionist = User::factory()->create();
        $account->users()->attach($receptionist, ['role' => AccountRole::Receptionist->value]);
        $event = Event::factory()->published()->for($account)->create();
        $type = EventTicketType::factory()->for($account)->for($event)->create(['price_cents' => 0]);
        $order = app(CreateEventOrder::class)->execute($event, [
            'buyer_name' => 'Door Guest',
            'buyer_email' => 'door@example.com',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
            'accept_terms' => true,
        ], 'uk');
        $ticket = $order->tickets()->firstOrFail();

        $this->actingAs($receptionist)
            ->postJson(route('dashboard.accounts.events.scanner.scan', [$account, $event]), [
                'code' => $ticket->token_encrypted,
                'source' => 'qr',
            ])
            ->assertOk()
            ->assertJsonPath('state', 'awaiting_confirmation')
            ->assertJsonPath('ticket.code', $ticket->code)
            ->assertJsonPath('ticket.type', $type->name)
            ->assertJsonPath('ticket.customer', 'Door Guest');

        $this->assertFalse($ticket->refresh()->is_checked_in);
        $this->assertSame(0, $ticket->checkIns()->count());

        $this->actingAs($receptionist)
            ->postJson(route('dashboard.accounts.events.scanner.scan', [$account, $event]), [
                'code' => $ticket->token_encrypted,
                'source' => 'qr',
                'confirm' => true,
            ])
            ->assertOk()
            ->assertJsonPath('state', 'checked_in');

        $this->actingAs($receptionist)
            ->postJson(route('dashboard.accounts.events.scanner.scan', [$account, $event]), [
                'code' => $ticket->code,
                'source' => 'manual',
            ])
            ->assertConflict()
            ->assertJsonPath('state', 'already_checked_in')
            ->assertJsonPath('operator', $receptionist->name)
            ->assertJsonPath('ticket.customer', 'Door Guest')
            ->assertJsonPath('ticket.code', $ticket->code)
            ->assertJsonPath('checked_in_at_label', $ticket->refresh()->checked_in_at?->timezone($event->timezone)->format('d.m.Y H:i'));

        $this->assertDatabaseHas('event_ticket_check_ins', [
            'event_ticket_id' => $ticket->id,
            'action' => 'check_in',
            'actor_name' => $receptionist->name,
        ]);
    }

    public function test_scanner_rejects_ticket_from_another_event(): void
    {
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $event = Event::factory()->published()->for($account)->create();
        $other = Event::factory()->published()->for($account)->create();
        $type = EventTicketType::factory()->for($account)->for($other)->create(['price_cents' => 0]);
        $order = app(CreateEventOrder::class)->execute($other, [
            'buyer_name' => 'Other Guest',
            'buyer_email' => 'other@example.com',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
            'accept_terms' => true,
        ], 'uk');

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.events.scanner.scan', [$account, $event]), [
                'code' => $order->tickets()->firstOrFail()->token_encrypted,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('state', 'wrong_event');
    }

    public function test_receptionist_cannot_void_ticket_and_manager_void_is_reasoned_and_audited(): void
    {
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $receptionist = User::factory()->create();
        $account->addOwner($owner);
        $account->users()->attach($receptionist, ['role' => AccountRole::Receptionist->value]);
        $event = Event::factory()->published()->for($account)->create();
        $type = EventTicketType::factory()->for($account)->for($event)->create(['price_cents' => 0]);
        $order = app(CreateEventOrder::class)->execute($event, [
            'buyer_name' => 'Door Guest',
            'buyer_email' => 'door-list@example.com',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
            'accept_terms' => true,
        ], 'uk');
        $ticket = $order->tickets()->firstOrFail();

        $this->actingAs($receptionist)
            ->post(route('dashboard.accounts.events.orders.tickets.void', [$account, $event, $order, $ticket]), [
                'reason' => 'Not allowed',
            ])
            ->assertForbidden();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.events.orders.tickets.void', [$account, $event, $order, $ticket]), [
                'reason' => 'Duplicate purchase resolved with buyer.',
            ])
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame(EventTicketStatus::Voided, $ticket->status);
        $this->assertSame('Duplicate purchase resolved with buyer.', $ticket->void_reason);
        $this->assertSame($owner->id, $ticket->voided_by);
    }

    public function test_scanner_shows_only_ten_latest_current_entries(): void
    {
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $event = Event::factory()->published()->for($account)->create();
        $type = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 0,
            'inventory' => 100,
            'max_per_order' => 100,
        ]);
        $order = app(CreateEventOrder::class)->execute($event, [
            'buyer_name' => 'Scanner Guest',
            'buyer_email' => 'scanner-list@example.com',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 12]],
            'accept_terms' => true,
        ], 'uk');
        $tickets = $order->tickets()->orderBy('id')->get();

        foreach ($tickets as $index => $ticket) {
            $ticket->forceFill([
                'is_checked_in' => true,
                'checked_in_at' => now()->subMinutes(12 - $index),
            ])->save();
        }

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.scanner', [$account, $event]))
            ->assertOk()
            ->assertSee(__('app.event_latest_entries'))
            ->assertSee('data-scanner-modal', false)
            ->assertSee(__('app.ticket_scanner_confirm_pass'))
            ->assertDontSee('<h1 class="crm-page-title">'.$event->title.'</h1>', false)
            ->assertDontSee('data-door-checkout', false)
            ->assertDontSee('name="search"', false);

        $latestTickets = $response->viewData('tickets');
        $this->assertCount(10, $latestTickets);
        $this->assertSame($tickets->last()->id, $latestTickets->first()->id);
        $this->assertSame($tickets->get(2)->id, $latestTickets->last()->id);
        $response->assertDontSee($tickets->first()->code);
    }

    public function test_attendance_overview_polls_all_ticket_states_with_compact_data(): void
    {
        $account = Account::factory()->create();
        $receptionist = User::factory()->create();
        $account->users()->attach($receptionist, ['role' => AccountRole::Receptionist->value]);
        $event = Event::factory()->published()->for($account)->create();
        $type = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 0,
            'inventory' => 10,
            'max_per_order' => 10,
        ]);
        $order = app(CreateEventOrder::class)->execute($event, [
            'buyer_name' => 'Live Guest',
            'buyer_email' => 'live@example.com',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 3]],
            'accept_terms' => true,
        ], 'uk');
        $tickets = $order->tickets()->orderBy('id')->get();
        $tickets->take(2)->each(fn ($ticket) => $ticket->update([
            'is_checked_in' => true,
            'checked_in_at' => now(),
        ]));

        $this->actingAs($receptionist)
            ->get(route('dashboard.accounts.events.attendance', [$account, $event]))
            ->assertOk()
            ->assertSee('data-event-attendance', false)
            ->assertSee('data-poll-interval="5000"', false)
            ->assertSee('data-attendance-total>3</strong>', false)
            ->assertSee('data-attendance-passed>2</strong>', false)
            ->assertSee('Live Guest')
            ->assertSee($tickets->first()->code)
            ->assertSee('data-passed="true"', false)
            ->assertSee('data-passed="false"', false);

        $dataUrl = route('dashboard.accounts.events.attendance.data', [$account, $event]);
        $this->actingAs($receptionist)
            ->getJson($dataUrl)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('total', 3)
            ->assertJsonPath('passed', 2)
            ->assertJsonCount(3, 'tickets')
            ->assertJsonStructure(['tickets' => ['*' => ['id', 'customer_name', 'code', 'passed']]]);

        $tickets->last()->update(['is_checked_in' => true, 'checked_in_at' => now()]);

        $this->actingAs($receptionist)
            ->getJson($dataUrl)
            ->assertOk()
            ->assertJsonPath('passed', 3)
            ->assertJsonPath('tickets.0.customer_name', 'Live Guest');
    }

    public function test_attendance_overview_preserves_permission_and_tenant_boundaries(): void
    {
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $trainer = User::factory()->create();
        $owner = User::factory()->create();
        $account->users()->attach($trainer, ['role' => AccountRole::Trainer->value]);
        $account->addOwner($owner);
        $event = Event::factory()->published()->for($account)->create();
        $otherEvent = Event::factory()->published()->for($otherAccount)->create();

        $this->actingAs($trainer)
            ->get(route('dashboard.accounts.events.attendance', [$account, $event]))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.attendance', [$account, $otherEvent]))
            ->assertNotFound();
    }
}
