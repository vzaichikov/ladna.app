<?php

namespace Tests\Feature;

use App\Actions\CreateEventOrder;
use App\Actions\EventDoorTicketSale;
use App\Enums\EventOrderStatus;
use App\Enums\PromoCodeDiscountType;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventPromoCode;
use App\Models\EventTicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EventPromoCodeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_percent_promo_uses_early_bird_subtotal_and_snapshots_every_amount(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 50000,
            'early_bird_price_cents' => 30000,
            'early_bird_ends_at' => now()->addDay(),
            'early_bird_quota' => 10,
        ]);
        $promoCode = $this->promoCode($account, $event, $ticketType, [
            'discount_type' => PromoCodeDiscountType::Percent,
            'discount_value' => 25,
        ]);

        $order = app(CreateEventOrder::class)->execute($event, $this->orderInput($ticketType, [
            'promo_code' => strtolower($promoCode->code),
            'provider' => 'monopay',
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ]), 'uk');

        $item = $order->items->sole();
        $this->assertSame(EventOrderStatus::Pending, $order->status);
        $this->assertSame($promoCode->id, $order->event_promo_code_id);
        $this->assertSame($promoCode->name, $order->promo_name);
        $this->assertSame($promoCode->code, $order->promo_code);
        $this->assertSame(60000, $order->subtotal_cents);
        $this->assertSame(15000, $order->discount_cents);
        $this->assertSame(45000, $order->amount_cents);
        $this->assertSame('early_bird', $item->price_tier);
        $this->assertSame(60000, $item->subtotal_cents);
        $this->assertSame(15000, $item->discount_cents);
        $this->assertSame(45000, $item->final_total_cents);
        $this->assertNotNull($order->promo_email_hash);
        $this->assertNotNull($order->promo_phone_hash);
    }

    public function test_fixed_promo_is_capped_to_eligible_lines_and_not_applied_to_other_tickets(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $eligible = EventTicketType::factory()->for($account)->for($event)->create(['price_cents' => 10000]);
        $other = EventTicketType::factory()->for($account)->for($event)->create(['price_cents' => 30000]);
        $promoCode = $this->promoCode($account, $event, $eligible, [
            'discount_type' => PromoCodeDiscountType::Fixed,
            'discount_value' => 15000,
        ]);

        $order = app(CreateEventOrder::class)->execute($event, $this->orderInput($eligible, [
            'promo_code' => $promoCode->code,
            'provider' => 'liqpay',
            'items' => [
                ['ticket_type_id' => $eligible->id, 'quantity' => 1],
                ['ticket_type_id' => $other->id, 'quantity' => 1],
            ],
        ]), 'en');

        $this->assertSame(40000, $order->subtotal_cents);
        $this->assertSame(10000, $order->discount_cents);
        $this->assertSame(30000, $order->amount_cents);
        $this->assertSame(0, $order->items->firstWhere('event_ticket_type_id', $eligible->id)->final_total_cents);
        $this->assertSame(30000, $order->items->firstWhere('event_ticket_type_id', $other->id)->final_total_cents);
    }

    public function test_pending_order_reserves_total_quota_and_expiry_releases_it(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create(['price_cents' => 20000]);
        $promoCode = $this->promoCode($account, $event, $ticketType, [
            'max_total_uses' => 1,
            'max_uses_per_identity' => null,
        ]);

        $first = app(CreateEventOrder::class)->execute($event, $this->orderInput($ticketType, [
            'promo_code' => $promoCode->code,
            'provider' => 'wayforpay',
        ]), 'uk');

        try {
            app(CreateEventOrder::class)->execute($event, $this->orderInput($ticketType, [
                'promo_code' => $promoCode->code,
                'buyer_email' => 'other@example.com',
                'buyer_phone' => '+380671111111',
                'provider' => 'wayforpay',
            ]), 'uk');
            $this->fail('The last promotion use was not reserved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('promo_code', $exception->errors());
        }

        $first->update(['status' => EventOrderStatus::Expired, 'expires_at' => now()->subMinute()]);

        $second = app(CreateEventOrder::class)->execute($event, $this->orderInput($ticketType, [
            'promo_code' => $promoCode->code,
            'buyer_email' => 'other@example.com',
            'buyer_phone' => '+380671111111',
            'provider' => 'wayforpay',
        ]), 'uk');

        $this->assertSame($promoCode->id, $second->event_promo_code_id);
    }

    public function test_refunded_order_still_counts_against_identity_limit(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create(['price_cents' => 10000]);
        $promoCode = $this->promoCode($account, $event, $ticketType, [
            'discount_type' => PromoCodeDiscountType::Percent,
            'discount_value' => 100,
            'max_uses_per_identity' => 1,
        ]);
        $first = app(CreateEventOrder::class)->execute($event, $this->orderInput($ticketType, [
            'promo_code' => $promoCode->code,
        ]), 'uk');
        $first->update(['status' => EventOrderStatus::Refunded, 'refunded_at' => now()]);

        $this->expectException(ValidationException::class);

        app(CreateEventOrder::class)->execute($event, $this->orderInput($ticketType, [
            'promo_code' => $promoCode->code,
            'buyer_email' => 'different@example.com',
        ]), 'uk');
    }

    public function test_public_quote_returns_authoritative_discount_breakdown(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create(['price_cents' => 25000]);
        $promoCode = $this->promoCode($account, $event, $ticketType, [
            'discount_type' => PromoCodeDiscountType::Percent,
            'discount_value' => 20,
        ]);

        $this->postJson(route('public.events.promo-codes.quote', [$account->slug, $event->slug]), [
            'promo_code' => strtolower($promoCode->code),
            'buyer_email' => 'quote@example.com',
            'buyer_phone' => '+380671234567',
            'items' => [$ticketType->id => 2],
        ])->assertOk()->assertJson([
            'subtotal_cents' => 50000,
            'eligible_subtotal_cents' => 50000,
            'discount_cents' => 10000,
            'total_cents' => 40000,
            'currency' => 'UAH',
            'promo_name' => $promoCode->name,
            'promo_code' => $promoCode->code,
            'requires_payment' => true,
            'line_discounts' => [(string) $ticketType->id => 10000],
        ]);
    }

    public function test_public_quotes_reject_a_ticket_type_without_remaining_inventory(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'inventory' => 0,
            'price_cents' => 25000,
        ]);
        $promoCode = $this->promoCode($account, $event, $ticketType);

        $this->postJson(route('public.events.promo-codes.quote', [$account->slug, $event->slug]), [
            'promo_code' => $promoCode->code,
            'buyer_email' => 'quote@example.com',
            'items' => [$ticketType->id => 1],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->postJson(route('public.events.entrance.promo-codes.quote', [$account->slug, $event->slug]), [
            'promo_code' => $promoCode->code,
            'guest_email' => 'door@example.com',
            'ticket_type_id' => $ticketType->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('ticket_type_id');
    }

    public function test_public_entrance_card_checkout_can_complete_for_free_with_promo(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create(['price_cents' => 12000]);
        $promoCode = $this->promoCode($account, $event, $ticketType, [
            'discount_type' => PromoCodeDiscountType::Fixed,
            'discount_value' => 12000,
        ]);

        $order = app(EventDoorTicketSale::class)->execute($account, $event, null, [
            'ticket_type_id' => $ticketType->id,
            'guest_name' => 'Entrance Guest',
            'guest_email' => 'entrance@example.com',
            'provider' => null,
            'promo_code' => $promoCode->code,
            'idempotency_key' => (string) Str::uuid(),
            'terms_accepted' => true,
        ], EventDoorTicketSale::ModeCard, 'uk');

        $this->assertSame(EventOrderStatus::Paid, $order->status);
        $this->assertSame(0, $order->amount_cents);
        $this->assertSame(12000, $order->discount_cents);
        $this->assertNull($order->provider);
        $this->assertSame(1, $order->tickets()->count());
    }

    public function test_public_entrance_quote_requires_email_and_uses_regular_door_price(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 20000,
            'early_bird_price_cents' => 10000,
            'early_bird_ends_at' => now()->addDay(),
        ]);
        $promoCode = $this->promoCode($account, $event, $ticketType, [
            'discount_type' => PromoCodeDiscountType::Percent,
            'discount_value' => 25,
        ]);
        $url = route('public.events.entrance.promo-codes.quote', [$account->slug, $event->slug]);

        $this->postJson($url, [
            'promo_code' => $promoCode->code,
            'ticket_type_id' => $ticketType->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('guest_email');

        $this->postJson($url, [
            'promo_code' => $promoCode->code,
            'guest_email' => 'door@example.com',
            'ticket_type_id' => $ticketType->id,
        ])->assertOk()->assertJson([
            'subtotal_cents' => 20000,
            'discount_cents' => 5000,
            'total_cents' => 15000,
        ]);
    }

    public function test_owner_can_create_scoped_code_and_code_with_history_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create();

        $this->actingAs($owner)->post(route('dashboard.accounts.events.promo-codes.store', [$account, $event]), [
            'name' => 'Launch offer',
            'code' => ' launch_20 ',
            'discount_type' => 'percent',
            'discount_amount' => 20,
            'starts_at' => now($event->timezone)->subHour()->format('Y-m-d\TH:i'),
            'ends_at' => now($event->timezone)->addDay()->format('Y-m-d\TH:i'),
            'max_total_uses' => 100,
            'max_uses_per_identity' => 1,
            'ticket_type_ids' => [$ticketType->id],
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.events.promo-codes.index', [$account, $event]));

        $promoCode = EventPromoCode::query()->where('event_id', $event->id)->sole();
        $this->assertSame('LAUNCH_20', $promoCode->code);
        $this->assertTrue($promoCode->ticketTypes()->whereKey($ticketType)->exists());
        EventOrder::factory()->for($account)->for($event)->create(['event_promo_code_id' => $promoCode->id]);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.events.promo-codes.destroy', [$account, $event, $promoCode]))
            ->assertSessionHasErrors('promo_code');
        $this->assertModelExists($promoCode);
    }

    /** @param array<string, mixed> $overrides */
    private function promoCode(Account $account, Event $event, EventTicketType $ticketType, array $overrides = []): EventPromoCode
    {
        $promoCode = EventPromoCode::factory()->for($account)->for($event)->create($overrides);
        $promoCode->ticketTypes()->attach($ticketType, [
            'account_id' => $account->id,
            'event_id' => $event->id,
        ]);

        return $promoCode;
    }

    /** @param array<string, mixed> $overrides */
    private function orderInput(EventTicketType $ticketType, array $overrides = []): array
    {
        return [
            'buyer_name' => 'Promo Buyer',
            'buyer_email' => 'promo@example.com',
            'buyer_phone' => '+380671234567',
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
            'accept_terms' => true,
            ...$overrides,
        ];
    }
}
