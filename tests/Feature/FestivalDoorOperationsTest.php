<?php

namespace Tests\Feature;

use App\Actions\Festivals\FestivalDoorTicketSale;
use App\Actions\Festivals\FestivalTicketIssuer;
use App\Actions\Festivals\FestivalTicketScanner;
use App\Actions\Festivals\RecordFestivalCashEntry;
use App\Actions\Festivals\ResolveFestivalEntranceGuest;
use App\Enums\AccountRole;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalTicketOrderSource;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FestivalTicketStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\Customer;
use App\Models\EventCashEntry;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCashEntry;
use App\Models\FestivalEdition;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeat;
use App\Models\FestivalSeries;
use App\Models\FestivalTicketOrder;
use App\Models\IntegrationSetting;
use App\Models\StudioCashEntry;
use App\Models\User;
use App\Support\Festivals\FestivalPromoCodePricing;
use App\Support\Festivals\FestivalSeatReservations;
use App\Support\Fiscalization\FiscalReceiptService;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Promotions\PromotionCodeNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use Tests\TestCase;

class FestivalDoorOperationsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
    }

    public function test_festival_cash_sale_uses_a_guest_identity_and_only_the_edition_cash_desk(): void
    {
        [$account, $edition, $admissionType, $operator] = $this->festivalDoor();
        $customerCount = Customer::query()->count();
        $input = [
            'ticket_type_id' => $admissionType->id,
            'guest_name' => 'Festival Door Guest',
            'guest_email' => null,
            'idempotency_key' => (string) Str::uuid(),
        ];

        $order = app(FestivalDoorTicketSale::class)->execute(
            $account,
            $edition,
            $operator,
            $input,
            FestivalDoorTicketSale::ModeCash,
            'en',
        );

        $this->assertSame(FestivalTicketOrderSource::Entrance, $order->source);
        $this->assertSame(FestivalTicketOrderStatus::Paid, $order->status);
        $this->assertSame('entrance_cash', $order->provider);
        $this->assertSame($operator->id, $order->issued_by_user_id);
        $this->assertSame($admissionType->price_cents, $order->amount_cents);
        $this->assertCount(1, $order->items);
        $this->assertSame('regular', $order->items->first()->price_tier);
        $this->assertCount(1, $order->tickets);
        $this->assertSame(FestivalTicketStatus::Valid, $order->tickets->first()->status);
        $this->assertSame('Festival Door Guest', $order->tickets->first()->holder_name);
        $this->assertFalse($order->tickets->first()->is_checked_in);

        $guest = $order->portalUser()->firstOrFail();
        $this->assertSame(FestivalPortalRole::Guest, $guest->role);
        $this->assertSame($account->id, $guest->account_id);
        $this->assertSame('Festival', $guest->first_name);
        $this->assertSame('Door Guest', $guest->last_name);
        $this->assertNull($guest->email);
        $this->assertSame($customerCount, Customer::query()->count());

        $entry = FestivalCashEntry::query()->whereBelongsTo($order, 'order')->firstOrFail();
        $this->assertSame($account->id, $entry->account_id);
        $this->assertSame($edition->id, $entry->festival_edition_id);
        $this->assertSame(FestivalCashEntry::DirectionIn, $entry->direction);
        $this->assertSame(FestivalCashEntry::PurposeEntranceTicketSale, $entry->purpose);
        $this->assertSame($admissionType->price_cents, $entry->amount_cents);
        $this->assertSame($operator->id, $entry->actor_user_id);
        $this->assertSame(0, StudioCashEntry::query()->count());
        $this->assertSame(0, EventCashEntry::query()->count());

        $replayed = app(FestivalDoorTicketSale::class)->execute(
            $account,
            $edition,
            $operator,
            $input,
            FestivalDoorTicketSale::ModeCash,
            'en',
        );

        $this->assertTrue($order->is($replayed));
        $this->assertSame(1, FestivalTicketOrder::query()->where('order_id', $order->order_id)->count());
        $this->assertSame(1, $order->tickets()->count());
        $this->assertSame(1, FestivalCashEntry::query()->where('festival_ticket_order_id', $order->id)->count());
        $this->assertSame(1, FestivalPortalUser::query()->where('account_id', $account->id)->forRole(FestivalPortalRole::Guest)->count());

        $owner = User::factory()->create();
        $account->addOwner($owner);
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.ticket-orders.refund', [$account, $edition, $order]), [
                'reason' => 'Cash returned to the guest at the entrance.',
            ])
            ->assertRedirect();

        $this->assertSame(FestivalTicketOrderStatus::Refunded, $order->refresh()->status);
        $this->assertSame(FestivalTicketStatus::Refunded, $order->tickets()->firstOrFail()->status);
        $this->assertSame(2, $order->cashEntries()->count());
        $this->assertSame(
            [FestivalCashEntry::DirectionIn, FestivalCashEntry::DirectionOut],
            $order->cashEntries()->orderBy('id')->pluck('direction')->all(),
        );
        $this->assertSame(
            [FestivalCashEntry::PurposeEntranceTicketSale, FestivalCashEntry::PurposeEntranceTicketRefund],
            $order->cashEntries()->orderBy('id')->pluck('purpose')->all(),
        );
        $this->assertSame(0, StudioCashEntry::query()->count());
    }

    public function test_festival_card_sale_holds_inventory_without_issuing_or_recording_cash(): void
    {
        [$account, $edition, $admissionType, $operator] = $this->festivalDoor();
        $action = $this->festivalDoorSaleWithProvider($account, 'monopay');

        $order = $action->execute($account, $edition, $operator, [
            'ticket_type_id' => $admissionType->id,
            'guest_name' => 'Festival Card Guest',
            'guest_email' => 'Festival.Card@Example.Test',
            'provider' => 'monopay',
            'idempotency_key' => (string) Str::uuid(),
            'terms_accepted' => true,
        ], FestivalDoorTicketSale::ModeCard, 'uk');

        $this->assertSame(FestivalTicketOrderSource::Entrance, $order->source);
        $this->assertSame(FestivalTicketOrderStatus::Pending, $order->status);
        $this->assertSame('monopay', $order->provider);
        $this->assertSame('festival.card@example.test', $order->buyer_email);
        $this->assertNotNull($order->expires_at);
        $this->assertNotNull($order->terms_accepted_at);
        $this->assertSame(0, $order->tickets()->count());
        $this->assertSame(0, FestivalCashEntry::query()->count());
        $this->assertSame(0, StudioCashEntry::query()->count());
    }

    public function test_assigned_cash_sale_replays_only_for_the_same_seat_and_scanner_shows_its_label(): void
    {
        [$account, $edition, $type, $operator] = $this->festivalDoor();
        [$seat, $otherSeat] = $this->assignedSeats($edition, $type);
        $route = route('dashboard.accounts.festivals.entrance.cash', [$account, $edition]);
        $input = [
            'ticket_type_id' => $type->id,
            'guest_name' => 'Reserved Door Guest',
            'idempotency_key' => (string) Str::uuid(),
        ];
        $this->actingAs($operator)->postJson($route, $input)
            ->assertUnprocessable();

        $response = $this->postJson($route, $input + ['seat_id' => $seat->id])
            ->assertCreated()
            ->assertJsonPath('tickets.0.seat_label', $seat->label());
        $this->getJson(route('dashboard.accounts.festivals.entrance.seats', [$account, $edition]))
            ->assertOk()
            ->assertJsonPath('unavailable', [$seat->id]);
        $this->postJson($route, $input + ['seat_id' => $seat->id])
            ->assertCreated()
            ->assertJsonPath('order.id', $response->json('order.id'));
        $this->postJson(route('dashboard.accounts.festivals.entrance.card', [$account, $edition]), $input + [
            'seat_id' => $seat->id,
            'provider' => 'monopay',
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson($route, $input + ['seat_id' => $otherSeat->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $this->postJson($route, array_replace($input, ['idempotency_key' => (string) Str::uuid(), 'seat_id' => $seat->id]))
            ->assertUnprocessable();

        $this->postJson(route('dashboard.accounts.festivals.scanner.scan', [$account, $edition]), [
            'code' => $response->json('tickets.0.code'),
        ])->assertOk()->assertJsonPath('ticket.seat_label', $seat->label());
    }

    public function test_manual_issuance_uses_the_selected_seat_and_bulk_issuance_rejects_assigned_types(): void
    {
        [$account, $edition, $type] = $this->festivalDoor();
        [$seat] = $this->assignedSeats($edition, $type);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $guest = FestivalPortalUser::factory()->for($account)->guest()->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.tickets.issue', [$account, $edition, 'selected_guest_id' => $guest->id]))
            ->assertOk()
            ->assertSee('data-festival-staff-seat-picker', false);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.festivals.tickets.issue.audience', [$account, $edition]), [
                'festival_admission_type_id' => $type->id,
                'audience' => 'judges',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('festival_admission_type_id');

        $this->post(route('dashboard.accounts.festivals.tickets.issue.store', [$account, $edition]), [
            'festival_portal_user_id' => $guest->id,
            'festival_admission_type_id' => $type->id,
            'seat_id' => $seat->id,
            'holder_name' => 'Complimentary Guest',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($edition->tickets()->firstOrFail()->seat->is($seat));
    }

    public function test_assigned_card_sale_respects_the_buyers_hold_and_reserves_its_own_seat_until_paid(): void
    {
        [$account, $edition, $type, $operator] = $this->festivalDoor();
        [$buyerSeat, $cardSeat] = $this->assignedSeats($edition, $type);
        $reservations = app(FestivalSeatReservations::class);
        $reservations->hold($edition, hash('sha256', 'buyers-seat'), [$buyerSeat->id]);
        $input = [
            'ticket_type_id' => $type->id,
            'guest_name' => 'Reserved Card Guest',
            'provider' => 'monopay',
            'idempotency_key' => (string) Str::uuid(),
        ];

        $this->assertThrows(fn () => app(FestivalDoorTicketSale::class)->execute(
            $account,
            $edition,
            $operator,
            $input + ['seat_id' => $buyerSeat->id],
            FestivalDoorTicketSale::ModeCard,
            'en',
        ), ValidationException::class);

        $order = $this->festivalDoorSaleWithProvider($account, 'monopay')->execute(
            $account,
            $edition,
            $operator,
            $input + ['seat_id' => $cardSeat->id],
            FestivalDoorTicketSale::ModeCard,
            'en',
        );

        $this->assertSame(FestivalTicketOrderStatus::Pending, $order->status);
        $this->assertSame($cardSeat->id, $order->orderSeats()->firstOrFail()->festival_seat_id);
        $this->assertSame(0, $order->tickets()->count());
        $this->assertSame([$buyerSeat->id, $cardSeat->id], $reservations->live($edition)->orderBy('id')->pluck('id')->all());
    }

    public function test_festival_door_guest_reuses_only_an_active_guest_with_the_same_account_and_role(): void
    {
        [$account, $edition, $admissionType, $operator] = $this->festivalDoor();
        $email = 'shared.identity@example.test';
        $registrant = FestivalPortalUser::factory()->for($account)->create([
            'email' => $email,
            'email_normalized' => $email,
        ]);

        $first = app(FestivalDoorTicketSale::class)->execute($account, $edition, $operator, [
            'ticket_type_id' => $admissionType->id,
            'guest_name' => 'Separate Guest',
            'guest_email' => $email,
            'idempotency_key' => (string) Str::uuid(),
        ], FestivalDoorTicketSale::ModeCash, 'uk');
        $guest = $first->portalUser()->firstOrFail();

        $this->assertSame(FestivalPortalRole::Registrant, $registrant->role);
        $this->assertSame(FestivalPortalRole::Guest, $guest->role);
        $this->assertFalse($registrant->is($guest));

        $second = app(FestivalDoorTicketSale::class)->execute($account, $edition, $operator, [
            'ticket_type_id' => $admissionType->id,
            'guest_name' => 'Separate Guest',
            'guest_email' => mb_strtoupper($email),
            'idempotency_key' => (string) Str::uuid(),
        ], FestivalDoorTicketSale::ModeCash, 'uk');

        $this->assertTrue($guest->is($second->portalUser));
        $this->assertSame(1, FestivalPortalUser::query()->where('account_id', $account->id)->forRole(FestivalPortalRole::Guest)->where('email_normalized', $email)->count());
    }

    public function test_festival_door_sale_rejects_an_admission_type_from_another_edition(): void
    {
        [$account, $edition, , $operator] = $this->festivalDoor();
        $otherEdition = FestivalEdition::factory()->published()->for(FestivalSeries::factory()->for($account))->create([
            'account_id' => $account->id,
        ]);
        $otherType = FestivalAdmissionType::factory()->for($otherEdition)->create([
            'account_id' => $account->id,
            'delivery_mode' => 'venue',
        ]);

        try {
            app(FestivalDoorTicketSale::class)->execute($account, $edition, $operator, [
                'ticket_type_id' => $otherType->id,
                'guest_name' => 'Wrong Edition Guest',
                'idempotency_key' => (string) Str::uuid(),
            ], FestivalDoorTicketSale::ModeCash, 'uk');
            $this->fail('An admission type from another Festival edition was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ticket_type_id', $exception->errors());
        }

        $this->assertSame(0, FestivalTicketOrder::query()->where('festival_edition_id', $edition->id)->count());
        $this->assertSame(0, FestivalCashEntry::query()->count());
    }

    public function test_festival_cash_ledger_entries_are_append_only(): void
    {
        [$account, $edition, $admissionType, $operator] = $this->festivalDoor();
        $order = app(FestivalDoorTicketSale::class)->execute($account, $edition, $operator, [
            'ticket_type_id' => $admissionType->id,
            'guest_name' => 'Immutable Festival Guest',
            'idempotency_key' => (string) Str::uuid(),
        ], FestivalDoorTicketSale::ModeCash, 'uk');
        $entry = $order->cashEntries()->firstOrFail();

        $this->assertThrows(
            fn () => $entry->update(['amount_cents' => 1]),
            LogicException::class,
        );
        $this->assertThrows(
            fn () => $entry->fresh()->delete(),
            LogicException::class,
        );
        $this->assertSame($admissionType->price_cents, $entry->fresh()->amount_cents);
        $this->assertModelExists($entry);
    }

    public function test_festival_guest_search_monitor_and_reasoned_undo_share_the_door_staff_boundary(): void
    {
        [$account, $edition, $admissionType, $operator] = $this->festivalDoor();
        $order = app(FestivalDoorTicketSale::class)->execute($account, $edition, $operator, [
            'ticket_type_id' => $admissionType->id,
            'guest_name' => 'Searchable Festival Guest',
            'guest_email' => 'searchable.festival@example.test',
            'idempotency_key' => (string) Str::uuid(),
        ], FestivalDoorTicketSale::ModeCash, 'uk');
        $ticket = $order->tickets()->firstOrFail();

        $search = $this->actingAs($operator)
            ->getJson(route('dashboard.accounts.festivals.entrance.search', [$account, $edition, 'q' => $ticket->code]))
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('results.0.order_id', $order->id)
            ->assertJsonPath('results.0.guest.name', 'Searchable Festival Guest')
            ->assertJsonPath('results.0.tickets.0.code', $ticket->code)
            ->assertJsonPath('results.0.tickets.0.can_admit', true);
        $this->assertStringContainsString('private', (string) $search->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $search->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=0', (string) $search->headers->get('Cache-Control'));
        $this->assertNotSame('searchable.festival@example.test', $search->json('results.0.guest.email'));

        app(FestivalTicketScanner::class)->checkIn($edition, $ticket->code, $operator, 'guest_search', '203.0.113.20', true);

        $this->actingAs($operator)
            ->getJson(route('dashboard.accounts.festivals.attendance.data', [$account, $edition]))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('passed', 1)
            ->assertJsonPath('unpassed', 0)
            ->assertJsonPath('cash.amount_cents', $admissionType->price_cents)
            ->assertJsonPath('tickets.0.code', $ticket->code)
            ->assertJsonPath('tickets.0.passed', true);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
            ->postJson(route('dashboard.accounts.festivals.attendance.tickets.undo', [$account, $edition, $ticket]), [
                'reason' => 'Operator admitted the adjacent guest by mistake.',
            ])
            ->assertOk()
            ->assertJsonPath('state', 'checked_out');

        $this->assertFalse($ticket->refresh()->is_checked_in);
        $this->assertNull($ticket->checked_in_at);
        $this->assertDatabaseHas('festival_ticket_scans', [
            'festival_ticket_id' => $ticket->id,
            'action' => 'check_out',
            'source' => 'monitor',
            'request_ip' => '203.0.113.21',
            'reason' => 'Operator admitted the adjacent guest by mistake.',
        ]);

        $scanOnlyStaff = User::factory()->create();
        $account->users()->attach($scanOnlyStaff, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::CheckInFestivalTickets->value],
        ]);
        $this->actingAs($scanOnlyStaff)
            ->getJson(route('dashboard.accounts.festivals.entrance.search', [$account, $edition, 'q' => $ticket->code]))
            ->assertOk()
            ->assertJsonPath('results.0.tickets.0.code', $ticket->code);
        $this->actingAs($scanOnlyStaff)
            ->getJson(route('dashboard.accounts.festivals.attendance.data', [$account, $edition]))
            ->assertForbidden();
    }

    /** @return array{Account, FestivalEdition, FestivalAdmissionType, User} */
    private function festivalDoor(): array
    {
        $account = Account::factory()->create([
            'enable_festivals' => true,
            'default_currency' => 'UAH',
            'default_language' => 'uk',
        ]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'currency' => 'UAH',
        ]);
        $operator = User::factory()->create();
        $account->users()->attach($operator, ['role' => AccountRole::Receptionist->value]);
        $admissionType = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'delivery_mode' => 'venue',
            'inventory' => 20,
            'price_cents' => 27500,
            'is_active' => true,
        ]);

        return [$account, $edition, $admissionType, $operator];
    }

    private function festivalDoorSaleWithProvider(Account $account, string $provider): FestivalDoorTicketSale
    {
        $setting = new IntegrationSetting(['provider' => $provider, 'is_enabled' => true]);
        $setting->account_id = $account->id;
        $gateways = Mockery::mock(PaymentGatewayRegistry::class);
        $gateways->shouldReceive('availableSettingsFor')
            ->with(Mockery::on(fn (Account $candidate): bool => $candidate->is($account)))
            ->once()
            ->andReturn(collect([$setting]));

        return new FestivalDoorTicketSale(
            app(ResolveFestivalEntranceGuest::class),
            app(FestivalTicketIssuer::class),
            app(RecordFestivalCashEntry::class),
            $gateways,
            app(FiscalReceiptService::class),
            app(FestivalPromoCodePricing::class),
            app(PromotionCodeNormalizer::class),
            app(FestivalSeatReservations::class),
        );
    }

    /** @return array{FestivalSeat, FestivalSeat} */
    private function assignedSeats(FestivalEdition $edition, FestivalAdmissionType $type): array
    {
        $edition->forceFill([
            'assigned_seating_enabled' => true,
            'seating_floors' => [['id' => 'main', 'label' => 'Main floor', 'width' => 800, 'height' => 600, 'background' => null, 'stage' => 'Stage']],
        ])->save();
        $type->forceFill(['is_assigned_seating' => true, 'inventory' => 2])->save();
        $attributes = [
            'account_id' => $edition->account_id,
            'festival_admission_type_id' => $type->id,
            'floor_key' => 'main',
            'section' => 'Stalls',
            'group_kind' => 'row',
            'group_label' => 'A',
            'y' => 1000,
        ];

        return [
            $edition->seats()->create($attributes + ['x' => 2000, 'seat_number' => '1']),
            $edition->seats()->create($attributes + ['x' => 3000, 'seat_number' => '2']),
        ];
    }
}
