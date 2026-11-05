<?php

namespace Tests\Feature;

use App\Actions\Festivals\FestivalTicketIssuer;
use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalNotification;
use App\Models\FestivalOnlineStream;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeat;
use App\Models\FestivalSeries;
use App\Models\FestivalTicketOrder;
use App\Models\User;
use App\Support\Festivals\FestivalHallOccupancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FestivalHallOccupancyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_projection_uses_current_claims_and_keeps_admission_separate_without_writing(): void
    {
        Queue::fake();
        [$account, $edition, $type] = $this->venue();
        $seats = [];
        foreach (range(1, 10) as $number) {
            $seats[$number] = FestivalSeat::query()->create([
                'account_id' => $account->id, 'festival_edition_id' => $edition->id,
                'festival_admission_type_id' => $type->id, 'floor_key' => $number === 10 ? 'balcony' : 'main',
                'section' => 'VIP', 'group_kind' => 'table', 'group_label' => 'A', 'seat_number' => (string) $number,
                'x' => $number * 500, 'y' => 5000,
            ]);
        }
        $seats[2]->update(['hold_key' => 'secret-prehold', 'hold_expires_at' => now()->addMinutes(5)]);
        $seats[3]->update(['hold_key' => 'expired-secret', 'hold_expires_at' => now()->subMinute()]);
        $pending = $this->claim($edition, $type, $seats[4], 'pending');
        $expired = $this->claim($edition, $type, $seats[5], 'pending');
        $expired->update(['expires_at' => now()->subMinute()]);
        $paid = $this->claim($edition, $type, $seats[6]);
        $paid->tickets()->update(['is_checked_in' => true, 'checked_in_at' => now()]);
        $void = $this->claim($edition, $type, $seats[7]);
        $void->tickets()->update(['status' => 'voided']);
        $refund = $this->claim($edition, $type, $seats[8]);
        $refund->update(['status' => 'refunded']);
        $refund->tickets()->update(['status' => 'refunded']);
        $resold = $this->claim($edition, $type, $seats[8]);
        $review = $this->claim($edition, $type, $seats[8], 'paid_requires_refund');
        $seats[9]->update(['is_blocked' => true]);
        $snapshot = $seats[3]->fresh()->getRawOriginal();
        $hall = app(FestivalHallOccupancy::class)->present($edition);
        $states = collect($hall['seats'])->keyBy('id');
        foreach ([1 => 'free', 2 => 'held', 3 => 'free', 4 => 'held', 5 => 'free', 6 => 'assigned', 7 => 'assigned', 8 => 'assigned', 9 => 'blocked', 10 => 'free'] as $number => $state) {
            $this->assertSame($state, $states[$seats[$number]->id]['reservation']);
        }
        $this->assertSame('checked_in', $states[$seats[6]->id]['admission']);
        $this->assertSame('invalid', $states[$seats[7]->id]['admission']);
        $this->assertSame($resold->tickets()->sole()->id, $states[$seats[8]->id]['ticket_id']);
        $this->assertSame(10, array_sum(array_intersect_key($hall['counts'], array_flip(['free', 'held', 'assigned', 'blocked']))));
        $this->assertSame(1, $hall['counts']['checked_in']);
        $this->assertSame($snapshot, $seats[3]->fresh()->getRawOriginal());
        $json = json_encode($hall);
        foreach (['secret-prehold', 'expired-secret', 'buyer_email', 'buyer_phone', 'qr_token', 'access_token', 'gateway_', 'hold_key', 'amount_cents'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $type->update(['is_active' => false]);
        $this->assertFalse(collect(app(FestivalHallOccupancy::class)->present($edition)['seats'])->firstWhere('id', $seats[1]->id)['sellable']);
        $edition->update(['assigned_seating_enabled' => false]);
        $this->assertCount(10, app(FestivalHallOccupancy::class)->present($edition)['seats']);
        $this->assertFalse(collect(app(FestivalHallOccupancy::class)->present($edition)['seats'])->contains('sellable', true));
    }

    public function test_sales_reads_are_private_finance_scoped_and_off_drafts_have_no_map(): void
    {
        [$account, $edition] = $this->venue();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $url = route('dashboard.accounts.festivals.tickets.seat-map', [$account, $edition]);
        $this->actingAs($owner)->getJson($url.'?state_only=1')->assertOk()->assertJsonPath('floors', null)->assertHeader('Cache-Control', 'no-store, private');
        $staff = User::factory()->create();
        $account->users()->attach($staff, ['role' => AccountRole::Trainer->value, 'permissions' => [StudioPermission::CheckInFestivalTickets->value]]);
        $this->actingAs($staff)->getJson($url)->assertForbidden();
        foreach (['admission-types.index', 'tickets.sold', 'ticket-orders.index'] as $destination) {
            $this->get(route('dashboard.accounts.festivals.'.$destination, [$account, $edition]))->assertForbidden();
        }

        [, $other] = $this->venue();
        $this->actingAs($owner)->getJson(route('dashboard.accounts.festivals.tickets.seat-map', [$account, $other]))->assertNotFound();
        $edition->update(['assigned_seating_enabled' => false]);
        $hall = app(FestivalHallOccupancy::class)->present($edition);
        $this->assertFalse($hall['applicable']);
        $this->assertSame([], $hall['seats']);
    }

    public function test_sales_page_defaults_to_map_with_list_and_historical_fallback(): void
    {
        [$account, $edition] = $this->venue();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $url = route('dashboard.accounts.festivals.tickets.sold', [$account, $edition]);
        $this->actingAs($owner)->get($url)->assertOk()->assertSee('data-festival-seat-map-view', false)->assertViewHas('ticketView', 'map');
        $this->get($url.'?view=list&q=needle')->assertOk()->assertViewHas('ticketView', 'list')->assertSee('value="list"', false);
        $edition->update(['assigned_seating_enabled' => false]);
        $this->get($url)->assertOk()->assertViewHas('ticketView', 'list')->assertDontSee('data-festival-seat-map-view', false);
    }

    public function test_seat_first_issuance_keeps_guest_context_and_rejects_conflicts(): void
    {
        Queue::fake();
        [$account, $edition, $type] = $this->venue();
        $seat = FestivalSeat::query()->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_admission_type_id' => $type->id, 'floor_key' => 'balcony', 'section' => 'VIP', 'group_kind' => 'table', 'group_label' => 'B', 'seat_number' => '1', 'x' => 5000, 'y' => 5000]);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        $url = route('dashboard.accounts.festivals.tickets.issue', [$account, $edition, 'seat_id' => $seat->id, 'selected_guest_id' => $guest->id, 'q' => 'Guest']);
        $this->actingAs($owner)->get($url)->assertOk()->assertViewHas('selectedSeatId', $seat->id)->assertViewHas('selectedTypeId', $type->id)->assertSee('data-old-seat="'.$seat->id.'"', false);
        $this->assertNull($seat->fresh()->hold_key);
        [, $other] = $this->venue();
        $this->get(route('dashboard.accounts.festivals.tickets.issue', [$account, $other, 'seat_id' => $seat->id]))->assertNotFound();
        $seat->update(['hold_key' => 'competing-buyer', 'hold_expires_at' => now()->addMinute()]);
        $input = ['festival_portal_user_id' => $guest->id, 'festival_admission_type_id' => $type->id, 'seat_id' => $seat->id, 'holder_name' => 'Keep Guest Name'];
        $this->from($url)->post(route('dashboard.accounts.festivals.tickets.issue.store', [$account, $edition]), $input)->assertSessionHasErrors('seat_ids')->assertSessionHasInput('holder_name', 'Keep Guest Name');
        $seat->update(['hold_key' => null, 'hold_expires_at' => null]);
        $this->post(route('dashboard.accounts.festivals.tickets.issue.store', [$account, $edition]), $input)->assertRedirect(route('dashboard.accounts.festivals.tickets.sold', [$account, $edition]));
        $this->assertSame($seat->id, $edition->tickets()->sole()->festival_seat_id);
        $order = $edition->ticketOrders()->sole();
        $notification = FestivalNotification::query()->where('festival_ticket_order_id', $order->id)->where('channel', 'email')->sole();
        $this->assertSame([$seat->fresh()->setRelation('edition', $edition)->label($order->locale)], $notification->payload['seat_labels']);
        $this->assertSame(0, $notification->payload['unassigned_tickets_count']);
        $payload = $notification->payload;
        app(FestivalTicketIssuer::class)->execute($order);
        $this->assertSame(1, FestivalNotification::query()->where('festival_ticket_order_id', $order->id)->where('channel', 'email')->count());
        $this->assertSame($payload, $notification->fresh()->payload);

    }

    public function test_protected_wayfinding_contains_only_own_ticket_assignments_and_neutral_geometry(): void
    {
        Queue::fake();
        [$account, $edition, $type] = $this->venue();
        $seat = FestivalSeat::query()->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_admission_type_id' => $type->id, 'floor_key' => 'main', 'section' => 'VIP', 'group_kind' => 'row', 'group_label' => 'R', 'seat_number' => '1', 'x' => 5000, 'y' => 5000]);
        $otherSeat = $seat->replicate();
        $otherSeat->seat_number = '2';
        $otherSeat->save();
        $order = $this->claim($edition, $type, $seat);
        $other = $this->claim($edition, $type, $otherSeat);
        $url = route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]);
        $response = $this->get($url)->assertOk()->assertSee('data-mode="own"', false)->assertViewHas('ownSeatMap');
        $projection = $response->viewData('ownSeatMap');
        $this->assertCount(1, collect($projection['seats'])->where('own', true));
        foreach ($projection['seats'] as $s) {
            foreach (['reservation', 'admission', 'ticket_id', 'ticket_href', 'issue_href', 'sellable', 'hold_key'] as $private) {
                $this->assertArrayNotHasKey($private, $s);
            }
        }
        $response->assertDontSee($other->buyer_email)->assertDontSee($other->tickets()->sole()->code)->assertDontSee($other->access_token_encrypted);
        $edition->update(['assigned_seating_enabled' => false]);
        $this->get($url)->assertOk()->assertSee('data-mode="own"', false);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $salesUrl = route('dashboard.accounts.festivals.tickets.sold', [$account, $edition]);
        $this->actingAs($owner)->get($salesUrl)->assertOk()->assertViewHas('ticketView', 'list');
        $this->get($salesUrl.'?view=map')->assertOk()->assertViewHas('ticketView', 'map')->assertSee(__('app.festival_journey.sales_off'));

        $order->update(['status' => 'refunded']);
        $this->get($url)->assertOk()->assertDontSee('data-mode="own"', false);
    }

    public function test_hall_monitor_has_a_safe_projection_and_preserves_guests_default(): void
    {
        [$account, $edition] = $this->venue();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $url = route('dashboard.accounts.festivals.attendance', [$account, $edition]);
        $this->actingAs($owner)->get($url)->assertOk()->assertSee('data-event-attendance', false);
        $this->get($url.'?view=hall')->assertOk()->assertSee('data-festival-seat-map-view', false)->assertDontSee('data-event-attendance', false);
        $this->getJson(route('dashboard.accounts.festivals.attendance.data', [$account, $edition, 'view' => 'hall', 'state_only' => 1]))->assertOk()->assertJsonPath('hall.floors', null)->assertJsonMissingPath('cash')->assertJsonMissingPath('credentials')->assertJsonMissingPath('tickets');
    }

    public function test_guest_monitor_renders_a_separate_full_seat_label(): void
    {
        Queue::fake();
        [$account, $edition, $type] = $this->venue();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $seat = FestivalSeat::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_admission_type_id' => $type->id,
            'floor_key' => 'main',
            'section' => 'VIP',
            'group_kind' => 'table',
            'group_label' => 'A',
            'seat_number' => '1',
            'x' => 5000,
            'y' => 5000,
        ]);
        $this->claim($edition, $type, $seat);
        $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.attendance', [$account, $edition]))->assertOk();
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $labels = (new \DOMXPath($document))->query('//*[@data-attendance-seat]');
        $this->assertSame(1, $labels->length);
        $this->assertSame($seat->setRelation('edition', $edition)->label(), trim($labels->item(0)->textContent));
    }

    public function test_online_ticket_in_staff_list_is_never_labeled_as_general_venue_admission(): void
    {
        Queue::fake();
        [$account, $edition] = $this->venue();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->online($stream)->create(['name' => 'Online only']);
        $order = FestivalTicketOrder::factory()->for($edition)->create(['account_id' => $account->id, 'status' => 'paid', 'festival_portal_user_id' => $guest->id]);
        $order->items()->create(['account_id' => $account->id, 'festival_admission_type_id' => $type->id, 'admission_name' => $type->name, 'quantity' => 1, 'unit_price_cents' => 0, 'total_cents' => 0]);
        app(FestivalTicketIssuer::class)->execute($order);
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.tickets.sold', [$account, $edition, 'view' => 'list']))
            ->assertOk()->assertSee(__('app.festival_admission_delivery_online_stream'))->assertDontSee(__('app.festival_journey.general_admission'));
        $this->get(route('dashboard.accounts.festivals.attendance', [$account, $edition, 'view' => 'hall']))
            ->assertOk()->assertViewHas('generalAdmissionCount', fn ($count): bool => $count === 0);

    }

    public function test_a_voided_ticket_keeps_its_paid_order_refund_action_available(): void
    {
        Queue::fake();
        [$account, $edition, $type] = $this->venue();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $seat = FestivalSeat::query()->create([
            'account_id' => $account->id, 'festival_edition_id' => $edition->id,
            'festival_admission_type_id' => $type->id, 'floor_key' => 'main',
            'section' => 'VIP', 'group_kind' => 'table', 'group_label' => 'A',
            'seat_number' => '1', 'x' => 5000, 'y' => 5000,
        ]);
        $order = $this->claim($edition, $type, $seat, 'pending');
        $standing = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'price_cents' => 0]);
        $order->items()->create(['account_id' => $account->id, 'festival_admission_type_id' => $standing->id, 'admission_name' => $standing->name, 'quantity' => 1, 'unit_price_cents' => 0, 'total_cents' => 0]);
        $order->update(['status' => 'paid']);
        app(FestivalTicketIssuer::class)->execute($order);
        $ticket = $order->tickets()->where('festival_seat_id', $seat->id)->firstOrFail();
        $ticket->update(['status' => 'voided']);
        $url = route('dashboard.accounts.festivals.tickets.sold', [$account, $edition, 'view' => 'list', 'ticket' => $ticket->id]);
        $action = route('dashboard.accounts.festivals.ticket-orders.refund', [$account, $edition, $order]);
        foreach ([$url, route('dashboard.accounts.festivals.tickets.sold', [$account, $edition, 'view' => 'list'])] as $pageUrl) {
            $response = $this->actingAs($owner)->get($pageUrl)->assertOk();
            $document = new \DOMDocument;
            $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $this->assertSame(1, (new \DOMXPath($document))->query('//form[@action="'.$action.'"]')->length);
        }
    }

    private function venue(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id, 'assigned_seating_enabled' => true, 'seating_floors' => [
            ['id' => 'main', 'label' => 'Main floor', 'width' => 1000, 'height' => 700, 'blocks' => []],
            ['id' => 'balcony', 'label' => 'Balcony', 'width' => 1000, 'height' => 700, 'blocks' => []],
        ]]);
        $type = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'is_assigned_seating' => true, 'price_cents' => 0]);

        return [$account, $edition, $type];
    }

    private function claim(FestivalEdition $edition, FestivalAdmissionType $type, FestivalSeat $seat, string $status = 'paid'): FestivalTicketOrder
    {
        $order = FestivalTicketOrder::factory()->for($edition)->create(['account_id' => $edition->account_id, 'status' => $status, 'expires_at' => now()->addMinutes(10)]);
        $item = $order->items()->create(['account_id' => $edition->account_id, 'festival_admission_type_id' => $type->id, 'admission_name' => $type->name, 'quantity' => 1, 'unit_price_cents' => 0, 'total_cents' => 0]);
        $item->orderSeats()->create(['festival_seat_id' => $seat->id]);
        if ($status === 'paid') {
            app(FestivalTicketIssuer::class)->execute($order);
        }

        return $order;
    }
}
