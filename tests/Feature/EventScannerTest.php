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

    public function test_receptionist_can_scan_once_and_duplicate_returns_operator_audit(): void
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
            ->assertJsonPath('state', 'checked_in');

        $this->actingAs($receptionist)
            ->postJson(route('dashboard.accounts.events.scanner.scan', [$account, $event]), [
                'code' => $ticket->code,
                'source' => 'manual',
            ])
            ->assertConflict()
            ->assertJsonPath('state', 'already_checked_in')
            ->assertJsonPath('operator', $receptionist->name);

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

    public function test_door_list_check_out_and_manager_void_are_reasoned_and_audited(): void
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
            ->postJson(route('dashboard.accounts.events.scanner.scan', [$account, $event]), [
                'code' => $ticket->code,
                'source' => 'door_list',
            ])
            ->assertOk();

        $this->actingAs($receptionist)
            ->postJson(route('dashboard.accounts.events.scanner.check-out', [$account, $event, $ticket]), [
                'reason' => 'Guest needs to re-enter later.',
            ])
            ->assertOk()
            ->assertJsonPath('state', 'checked_out');

        $this->assertDatabaseHas('event_ticket_check_ins', [
            'event_ticket_id' => $ticket->id,
            'action' => 'check_out',
            'reason' => 'Guest needs to re-enter later.',
            'actor_name' => $receptionist->name,
        ]);

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
}
