<?php

namespace Tests\Feature;

use App\Actions\Festivals\CreateFestivalTicketOrder;
use App\Actions\Festivals\FestivalTicketScanner;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Enums\PromoCodeDiscountType;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalPortalUser;
use App\Models\FestivalPromoCode;
use App\Models\FestivalSeat;
use App\Models\FestivalSeries;
use App\Models\FestivalTariffPackage;
use App\Models\IntegrationSetting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Festivals\FestivalSeatReservations;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FestivalSeatReservationsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_hold_protects_a_seat_and_a_mixed_free_order_consumes_it_only_once(): void
    {
        Queue::fake();
        [$account, $edition, $assigned, $seat] = $this->venue();
        $general = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'price_cents' => 0]);
        $reservations = app(FestivalSeatReservations::class);
        $key = hash('sha256', 'browser-1');
        $deadline = $reservations->hold($edition, $key, [$seat->id]);
        $this->assertSame(0, $assigned->remainingQuantity());
        $this->assertEquals($deadline, $reservations->hold($edition, $key, [$seat->id]));

        try {
            $reservations->hold($edition, hash('sha256', 'browser-2'), [$seat->id]);
            $this->fail('Two browsers cannot hold the same seat.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('seat_ids', $exception->errors());
        }

        $items = [['admission_type_id' => $general->id, 'quantity' => 1]];
        $input = $this->checkout($edition, $seat, $key, 0, $items);
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        $create = app(CreateFestivalTicketOrder::class);
        $order = $create->execute($edition, $input, $guest);
        $this->assertSame(FestivalTicketOrderStatus::Paid, $order->status);
        $this->assertTrue($order->is($create->execute($edition, $input, $guest)));
        $this->assertSame(2, $order->tickets()->count());
        $this->assertSame($seat->id, $order->tickets()->whereNotNull('festival_seat_id')->sole()->seat->id);
        $this->assertSame(0, $reservations->preholdCount($edition));
        $this->assertTrue($reservations->live($edition)->whereKey($seat->id)->exists());
    }

    public function test_an_expired_seated_payment_cannot_take_a_resold_seat_or_issue_the_general_tickets(): void
    {
        Queue::fake();
        [$account, $edition, $type, $seat] = $this->venue(10000);
        $general = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'price_cents' => 0]);
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        $this->enableMonopay($account);

        $reservations = app(FestivalSeatReservations::class);
        $create = app(CreateFestivalTicketOrder::class);
        $payments = app(FestivalPaymentService::class);
        $key = hash('sha256', 'first');
        $reservations->hold($edition, $key, [$seat->id]);
        $items = [['admission_type_id' => $general->id, 'quantity' => 1]];
        $order = $create->execute($edition, $this->checkout($edition, $seat, $key, 10000, $items), $guest);
        $this->assertTrue($reservations->activeHolds($edition)->whereKey($seat->id)->exists());

        Http::preventStrayRequests();
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'seated-invoice',
                'pageUrl' => 'https://pay.monobank.ua/invoice/seated-invoice',
                'status' => 'created',
            ]),
        ]);
        $launcher = $payments->startOrder($order);
        $this->assertSame($launcher->url, $payments->startOrder($order)->url);
        Http::assertSentCount(1);

        $this->travel(36)->minutes();
        $nextKey = hash('sha256', 'next');
        $reservations->hold($edition, $nextKey, [$seat->id]);
        $next = $create->execute($edition, $this->checkout($edition, $seat, $nextKey, 10000), $guest);
        $callback = new PaymentCallbackResult($order->order_id, PaymentCallbackStatus::Paid, amountCents: $order->amount_cents, currency: $order->currency);
        $late = $payments->completeOrder($order, $callback);
        $this->assertSame(FestivalTicketOrderStatus::PaidRequiresRefund, $late->status);
        $this->assertSame('seat_reservation_expired', $late->failure_reason);
        $this->assertSame(0, $late->tickets()->count());
        $this->assertSame(100, $general->remainingQuantity());

        $successful = new PaymentCallbackResult($next->order_id, PaymentCallbackStatus::Paid, amountCents: $next->amount_cents, currency: $next->currency);
        $payments->completeOrder($next, $successful);
        $payments->completeOrder($next, $successful);
        $this->assertSame(FestivalTicketOrderStatus::Paid, $next->refresh()->status);
        $this->assertSame($seat->id, $next->tickets()->sole()->festival_seat_id);
        $this->assertSame(1, $type->soldOrHeldQuantity());
        $this->assertSame(FestivalTicketOrderStatus::PaidRequiresRefund, $payments->completeOrder($order, $callback)->status);
    }

    public function test_a_prehold_counts_toward_package_capacity_and_a_mixed_order_applies_only_eligible_discounts(): void
    {
        Queue::fake();
        [$account, $edition, $type, $seat] = $this->venue(10000);
        $type->update(['early_bird_price_cents' => 8000, 'early_bird_ends_at' => now()->addDay(), 'early_bird_quota' => 1]);
        $plan = SubscriptionPlan::factory()->create(['currency' => $account->default_currency]);
        $package = FestivalTariffPackage::factory()->create(['subscription_plan_id' => $plan->id, 'max_tickets' => 2]);
        FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $plan->id,
            'festival_tariff_package_id' => $package->id,
            'festival_edition_id' => $edition->id,
        ]);
        $general = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'price_cents' => 5000]);
        $promo = FestivalPromoCode::factory()->for($edition)->create([
            'account_id' => $account->id,
            'currency' => $account->default_currency,
            'discount_type' => PromoCodeDiscountType::Fixed,
            'discount_value' => 2000,
        ]);
        $promo->admissionTypes()->attach($type);
        $this->enableMonopay($account);
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        $key = hash('sha256', 'with-package');
        app(FestivalSeatReservations::class)->hold($edition, $key, [$seat->id]);
        $create = app(CreateFestivalTicketOrder::class);

        $input = $this->checkout($edition, $seat, $key, 0);
        $input['seat_ids'] = [];
        $input['items'] = [['admission_type_id' => $general->id, 'quantity' => 2]];
        try {
            $create->execute($edition, $input, $guest);
            $this->fail('The active seat hold must count toward the package quota.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $mixed = $this->checkout($edition, $seat, $key, 11000, [['admission_type_id' => $general->id, 'quantity' => 1]]);
        $mixed['promo_code'] = $promo->code;
        $order = $create->execute($edition, $mixed, $guest);
        $this->assertSame(11000, $order->amount_cents);
        $this->assertSame(2000, $order->items->firstWhere('festival_admission_type_id', $type->id)->discount_cents);
        $this->assertSame(0, $order->items->firstWhere('festival_admission_type_id', $general->id)->discount_cents);
        $this->assertSame(2, (int) $order->items->sum('quantity'));
    }

    public function test_a_changed_quote_and_quantity_only_selection_of_an_assigned_type_are_rejected(): void
    {
        [$account, $edition, $type, $seat] = $this->venue();
        $reservations = app(FestivalSeatReservations::class);
        $create = app(CreateFestivalTicketOrder::class);
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        $key = hash('sha256', 'changed');
        $reservations->hold($edition, $key, [$seat->id]);
        $input = $this->checkout($edition, $seat, $key, 0);
        $type->update(['price_cents' => 2000]);

        foreach ([
            'seat_quote' => $input,
            'seat_ids' => array_replace($input, ['seat_ids' => [], 'items' => [['admission_type_id' => $type->id, 'quantity' => 1]]]),
        ] as $error => $payload) {
            try {
                $create->execute($edition, $payload, $guest);
                $this->fail('The invalid seat checkout must fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($error, $exception->errors());
            }
        }

        $this->assertSame(0, $edition->ticketOrders()->count());
        $this->assertSame([$seat->id], $reservations->held($edition, $key)->modelKeys());
    }

    public function test_refund_releases_the_seat_only_after_check_in_is_reversed(): void
    {
        Queue::fake();
        [$account, $edition, $type, $seat] = $this->venue();
        $general = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'price_cents' => 0]);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        $reservations = app(FestivalSeatReservations::class);
        $key = hash('sha256', 'refundable');
        $reservations->hold($edition, $key, [$seat->id]);
        $input = $this->checkout($edition, $seat, $key, 0, [['admission_type_id' => $general->id, 'quantity' => 1]]);
        $order = app(CreateFestivalTicketOrder::class)->execute($edition, $input, $guest);
        $ticket = $order->tickets()->whereNotNull('festival_seat_id')->sole();
        $generalTicket = $order->tickets()->whereNull('festival_seat_id')->sole();
        $order->tickets()->update(['is_checked_in' => true, 'checked_in_at' => now()]);
        $refund = route('dashboard.accounts.festivals.ticket-orders.refund', [$account, $edition, $order]);

        $void = route('dashboard.accounts.festivals.tickets.void', [$account, $edition, $ticket]);
        $this->actingAs($owner)->post($void, ['reason' => 'Guest refund'])->assertSessionHasErrors('reason');
        $this->assertTrue($ticket->refresh()->is_checked_in);
        $this->post($refund, ['reason' => 'Guest refund'])->assertSessionHasErrors('reason');
        $this->assertSame(FestivalTicketOrderStatus::Paid, $order->refresh()->status);
        $this->assertTrue($reservations->live($edition)->whereKey($seat->id)->exists());

        app(FestivalTicketScanner::class)->checkOut($edition, $ticket, $owner, 'Refund requested', '127.0.0.1');
        $this->post($void, ['reason' => 'Guest refund'])->assertSessionHasNoErrors();
        $this->assertTrue($reservations->live($edition)->whereKey($seat->id)->exists());
        $this->post($refund, ['reason' => 'Guest refund'])->assertSessionHasErrors('reason');
        app(FestivalTicketScanner::class)->checkOut($edition, $generalTicket, $owner, 'Refund requested', '127.0.0.1');
        $this->post($refund, ['reason' => 'Guest refund'])->assertSessionHasNoErrors();

        $this->assertSame(FestivalTicketOrderStatus::Refunded, $order->refresh()->status);
        $this->assertFalse($reservations->live($edition)->whereKey($seat->id)->exists());
        $this->assertSame(1, $type->remainingQuantity());
        $this->assertSame($seat->id, $ticket->refresh()->festival_seat_id);
    }

    /** @return array{Account, FestivalEdition, FestivalAdmissionType, FestivalSeat} */
    private function venue(int $price = 0): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $edition->update(['assigned_seating_enabled' => true]);
        $assigned = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'is_assigned_seating' => true,
            'inventory' => 1,
            'price_cents' => $price,
        ]);
        $seat = $edition->seats()->create([
            'account_id' => $account->id,
            'festival_admission_type_id' => $assigned->id,
            'floor_key' => 'main',
            'section' => 'Stalls',
            'group_kind' => 'row',
            'group_label' => 'A',
            'seat_number' => '1',
            'x' => 1000,
            'y' => 2000,
        ]);

        return [$account, $edition, $assigned, $seat];
    }

    /**
     * @param  list<array{admission_type_id: int, quantity: int}>  $items
     * @return array<string, mixed>
     */
    private function checkout(FestivalEdition $edition, FestivalSeat $seat, string $key, int $total, array $items = []): array
    {
        $prices = [$seat->festival_admission_type_id => ['quantity' => 1, ...$seat->admissionType->currentPrice()]];
        foreach ($items as $item) {
            $type = $edition->admissionTypes()->findOrFail($item['admission_type_id']);
            $prices[$type->id] = ['quantity' => $item['quantity'], ...$type->currentPrice($item['quantity'])];
        }

        return [
            'buyer_name' => 'Seat Buyer',
            'buyer_email' => 'seat-buyer@example.test',
            'buyer_phone' => '+380671234567',
            'provider' => 'monopay',
            'items' => $items,
            'seat_ids' => [$seat->id],
            'seat_hold_key' => $key,
            'seat_quote' => app(FestivalSeatReservations::class)->quoteFingerprint($edition->id, $edition->account->default_currency, [$seat->id], $prices),
            'seat_quote_total' => $total,
        ];
    }

    private function enableMonopay(Account $account): void
    {
        IntegrationSetting::query()->create([
            'scope_type' => IntegrationScope::Account->value,
            'scope_id' => $account->id,
            'account_id' => $account->id,
            'provider' => IntegrationProvider::Monopay->value,
            'category' => IntegrationCategory::Payment->value,
            'is_enabled' => true,
            'credentials' => ['api_token' => 'festival-seat-test-token'],
        ]);
    }
}
