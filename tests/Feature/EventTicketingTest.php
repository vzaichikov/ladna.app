<?php

namespace Tests\Feature;

use App\Actions\CompleteEventOrder;
use App\Actions\CreateEventOrder;
use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailRecipientKind;
use App\Enums\EmailScenario;
use App\Enums\EventOrderStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Mail\TransactionalMail;
use App\Models\Account;
use App\Models\Customer;
use App\Models\EmailDelivery;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventOrderItem;
use App\Models\EventTicket;
use App\Models\EventTicketType;
use App\Models\FiscalReceipt;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EventTicketingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_event_order_page_translates_the_latest_ticket_email_status(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $event = Event::factory()->published()->for($account)->create();
        $order = EventOrder::factory()->for($account)->for($event)->create();
        EmailDelivery::factory()->for($account)->create([
            'event_order_id' => $order->id,
            'scenario' => EmailScenario::EventTicketsIssued,
            'status' => EmailDeliveryStatus::Sent,
            'recipient_kind' => EmailRecipientKind::EventBuyer,
        ]);

        $this->withSession(['locale' => 'uk'])
            ->actingAs($owner)
            ->get(route('dashboard.accounts.events.orders.index', [$account, $event]))
            ->assertOk()
            ->assertSee(__('app.email_delivery_status_sent'))
            ->assertDontSee(' · sent · ', false);
    }

    public function test_event_order_page_renders_ticket_rows_payment_fiscalization_and_modal_actions(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create();
        $order = EventOrder::factory()->for($account)->for($event)->create([
            'provider' => IntegrationProvider::Liqpay->value,
            'status' => EventOrderStatus::Paid->value,
            'amount_cents' => 65000,
            'gateway_invoice_id' => 'invoice-event-123',
            'gateway_payment_id' => 'payment-event-456',
            'gateway_status' => 'success',
            'paid_at' => now(),
            'expires_at' => null,
        ]);
        $item = EventOrderItem::factory()->create([
            'account_id' => $account->id,
            'event_id' => $event->id,
            'event_order_id' => $order->id,
            'event_ticket_type_id' => $ticketType->id,
            'ticket_type_name' => 'Front row',
            'unit_price_cents' => 65000,
            'total_cents' => 65000,
        ]);
        $ticket = EventTicket::factory()->create([
            'account_id' => $account->id,
            'event_id' => $event->id,
            'event_order_id' => $order->id,
            'event_order_item_id' => $item->id,
            'event_ticket_type_id' => $ticketType->id,
            'code' => 'EVT-FRONT-ROW',
        ]);
        FiscalReceipt::factory()
            ->forAccountScope($account)
            ->fiscalized('FN-EVENT-LIST-1')
            ->create([
                'payment_type' => $order->getMorphClass(),
                'payment_id' => $order->id,
                'attempts' => 2,
            ]);

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.orders.index', [$account, $event]))
            ->assertOk()
            ->assertSee('data-event-order-row="'.$order->id.'"', false)
            ->assertSee('data-event-ticket-row="'.$ticket->id.'"', false)
            ->assertSee('Front row')
            ->assertSee('EVT-FRONT-ROW')
            ->assertSee('invoice-event-123')
            ->assertSee('payment-event-456')
            ->assertSee('FN-EVENT-LIST-1')
            ->assertSee(route('dashboard.accounts.events.orders.resend', [$account, $event, $order]), false)
            ->assertSee(route('dashboard.accounts.events.orders.refund', [$account, $event, $order]), false)
            ->assertSee(route('dashboard.accounts.events.orders.tickets.void', [$account, $event, $order, $ticket]), false);

        $html = $response->getContent();
        $this->assertStringNotContainsString('<details', $html);
        $this->assertSame(3, substr_count($html, 'data-confirm-action'));
        $this->assertSame(2, substr_count($html, 'data-confirm-reason-output'));
        $this->assertSame(2, substr_count($html, 'data-confirm-reason-maxlength="2000"'));
        $this->assertLessThan(strpos($html, 'EVT-FRONT-ROW'), strpos($html, $order->order_id));
    }

    public function test_event_order_list_is_paginated_twenty_per_page_and_retains_query_string(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $event = Event::factory()->published()->for($account)->create();
        EventOrder::factory()->count(21)->for($account)->for($event)->create();

        $response = $this->actingAs($owner)->get(route('dashboard.accounts.events.orders.index', [
            $account,
            $event,
            'audit' => 'payments',
        ]))->assertOk();

        $orders = $response->viewData('orders');
        $this->assertSame(20, $orders->count());
        $this->assertSame(20, $orders->perPage());
        $this->assertStringContainsString('audit=payments', $orders->url(2));
    }

    public function test_public_checkout_uses_the_person_name_label(): void
    {
        $account = Account::factory()->create([
            'name' => 'Studio Chrome',
            'default_language' => 'uk',
            'logo_path' => 'brand/charmpole-icon.svg',
            'studio_slogan' => 'Studio-owned public experience.',
            'studio_rules_html' => '<p>Studio rules</p>',
            'public_offer_html' => '<p>Studio offer</p>',
        ]);
        $event = Event::factory()->published()->for($account)->create();
        EventTicketType::factory()->for($account)->for($event)->create();

        $this->get(route('public.events.show', [$account->slug, $event->slug]))
            ->assertOk()
            ->assertSee('data-public-studio-header', false)
            ->assertSee('data-public-studio-footer-identity', false)
            ->assertSee(route('public.studio', $account->slug), false)
            ->assertSee($account->logoUrl(), false)
            ->assertSee('data-customer-footer-legal-links', false)
            ->assertSee(__('app.powered_by_ladna'))
            ->assertDontSee(route('api-docs.show'), false)
            ->assertSee('<span class="crm-label">'.__('app.person_name').'</span>', false)
            ->assertDontSee('<span class="crm-label">'.__('app.name').'</span>', false);
    }

    public function test_free_mixed_order_issues_one_secure_ticket_per_admission_without_customer(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create(['capacity' => 10]);
        $standard = EventTicketType::factory()->for($account)->for($event)->create(['price_cents' => 0, 'inventory' => 10]);
        $vip = EventTicketType::factory()->for($account)->for($event)->create(['price_cents' => 0, 'inventory' => 10]);
        $customerCount = Customer::query()->count();

        $order = app(CreateEventOrder::class)->execute($event, [
            'buyer_name' => 'Guest Buyer',
            'buyer_email' => 'guest@example.com',
            'items' => [
                ['ticket_type_id' => $standard->id, 'quantity' => 2],
                ['ticket_type_id' => $vip->id, 'quantity' => 1],
            ],
            'accept_terms' => true,
        ], 'en');

        $this->assertSame(EventOrderStatus::Paid, $order->status);
        $this->assertSame(3, $order->tickets()->count());
        $this->assertSame($customerCount, Customer::query()->count());
        $this->assertSame(3, $order->tickets()->distinct('token_hash')->count('token_hash'));

        $this->get(route('public.event-orders.show', [$account->slug, $order->access_token_encrypted]))
            ->assertOk()
            ->assertSee('data-public-studio-header', false)
            ->assertSee('data-public-studio-footer-identity', false)
            ->assertDontSee(route('api-docs.show'), false);
    }

    public function test_early_bird_price_is_snapshotted_until_quota_is_reached(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $type = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 50000,
            'early_bird_price_cents' => 30000,
            'early_bird_ends_at' => now()->addDay(),
            'early_bird_quota' => 2,
        ]);

        $first = app(CreateEventOrder::class)->execute($event, $this->payload($type->id, 2), 'uk');
        $second = app(CreateEventOrder::class)->execute($event, $this->payload($type->id, 1), 'uk');

        $this->assertSame('early_bird', $first->items->first()->price_tier);
        $this->assertSame(30000, $first->items->first()->unit_price_cents);
        $this->assertSame('regular', $second->items->first()->price_tier);
        $this->assertSame(50000, $second->items->first()->unit_price_cents);
    }

    public function test_tighter_event_capacity_prevents_overselling(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create(['capacity' => 2]);
        $type = EventTicketType::factory()->for($account)->for($event)->create(['price_cents' => 0, 'inventory' => 20]);
        app(CreateEventOrder::class)->execute($event, $this->payload($type->id, 2), 'uk');

        $this->expectException(ValidationException::class);
        app(CreateEventOrder::class)->execute($event, $this->payload($type->id, 1), 'uk');
    }

    public function test_late_paid_callback_requires_refund_when_expired_capacity_was_resold(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create(['capacity' => 1]);
        $type = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 50000,
            'inventory' => 1,
        ]);
        $lateOrder = app(CreateEventOrder::class)->execute($event, $this->payload($type->id, 1), 'uk');
        $lateOrder->update(['expires_at' => now()->subMinute()]);
        $type->update(['price_cents' => 0]);
        $replacementOrder = app(CreateEventOrder::class)->execute($event, $this->payload($type->id, 1), 'uk');

        $completed = app(CompleteEventOrder::class)->execute($lateOrder, new PaymentCallbackResult(
            orderId: $lateOrder->order_id,
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: 50000,
            currency: 'UAH',
            payload: ['status' => 'success'],
        ));

        $this->assertSame(EventOrderStatus::Paid, $replacementOrder->status);
        $this->assertSame(EventOrderStatus::PaidRequiresRefund, $completed->status);
        $this->assertSame(0, $completed->tickets()->count());
    }

    public function test_ticket_email_displays_and_attaches_each_qr_without_persisting_qr_payloads(): void
    {
        Mail::fake();
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
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $type = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 0,
            'inventory' => 10,
        ]);
        $order = app(CreateEventOrder::class)->execute($event, $this->payload($type->id, 2), 'en');

        app(TransactionalMailDispatcher::class)->eventTicketsIssued($order);

        Mail::assertQueued(TransactionalMail::class, function (TransactionalMail $mail) use ($account, $order): bool {
            return count($mail->attachmentData) === 2
                && count($mail->data['tickets']) === 2
                && collect($mail->data['tickets'])->every(function (array $ticket) use ($account, $order): bool {
                    return filled($ticket['qr_data'] ?? null)
                        && ($ticket['qr_url'] ?? null) === route('public.event-tickets.qr', [
                            $account->slug,
                            $order->access_token_encrypted,
                            $ticket['code'],
                        ]);
                })
                && ! str_contains($mail->render(), 'cid:');
        });
        $delivery = EmailDelivery::query()->where('event_order_id', $order->id)->sole();
        $this->assertCount(2, $delivery->payload['tickets']);
        $this->assertArrayNotHasKey('qr_data', $delivery->payload['tickets'][0]);
    }

    public function test_ticket_qr_image_is_private_to_its_order_and_tenant(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $type = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 0,
            'inventory' => 10,
        ]);
        $order = app(CreateEventOrder::class)->execute($event, $this->payload($type->id, 1), 'en');
        $ticket = $order->tickets()->sole();
        $qrUrl = route('public.event-tickets.qr', [
            $account->slug,
            $order->access_token_encrypted,
            $ticket->code,
        ]);

        $this->get($qrUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename="'.$ticket->code.'.png"')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->get(route('public.event-tickets.qr', [
            $account->slug,
            'wrong-order-token',
            $ticket->code,
        ]))->assertNotFound();

        $otherAccount = Account::factory()->create();

        $this->get(route('public.event-tickets.qr', [
            $otherAccount->slug,
            $order->access_token_encrypted,
            $ticket->code,
        ]))->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $ticketTypeId, int $quantity): array
    {
        return [
            'buyer_name' => 'Buyer',
            'buyer_email' => fake()->unique()->safeEmail(),
            'provider' => 'liqpay',
            'items' => [['ticket_type_id' => $ticketTypeId, 'quantity' => $quantity]],
            'accept_terms' => true,
        ];
    }
}
