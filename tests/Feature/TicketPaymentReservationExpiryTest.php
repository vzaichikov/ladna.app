<?php

namespace Tests\Feature;

use App\Actions\StartEventOrderPayment;
use App\Enums\EventOrderStatus;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\FestivalEdition;
use App\Models\FestivalSeries;
use App\Models\FestivalTicketOrder;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Payments\TicketPaymentTiming;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

class TicketPaymentReservationExpiryTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_event_and_festival_monopay_orders_use_exact_configured_and_fallback_deadlines(): void
    {
        Carbon::setTestNow('2026-08-16 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'ticket-invoice',
                'pageUrl' => 'https://pay.monobank.ua/invoice/ticket-invoice',
                'status' => 'created',
            ]),
        ]);

        $eventAccount = Account::factory()->create();
        $event = Event::factory()->published()->for($eventAccount)->create();
        $eventSetting = $this->monopaySetting($eventAccount, 7200);
        $eventOrder = EventOrder::factory()->for($eventAccount)->for($event)->create([
            'provider' => IntegrationProvider::Monopay->value,
            'amount_cents' => 100,
        ]);

        app(StartEventOrderPayment::class)->execute($eventOrder, $eventSetting);
        $eventOrder->refresh();

        $this->assertSame('2026-08-16 14:00:00', $eventOrder->payment_expires_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-16 14:05:00', $eventOrder->expires_at?->format('Y-m-d H:i:s'));

        [$festivalAccount, $edition] = $this->festival();
        $this->monopaySetting($festivalAccount);
        $festivalOrder = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $festivalAccount->id,
            'provider' => IntegrationProvider::Monopay->value,
            'amount_cents' => 100,
        ]);

        app(FestivalPaymentService::class)->startOrder($festivalOrder);
        $festivalOrder->refresh();

        $this->assertSame('2026-08-16 12:30:00', $festivalOrder->payment_expires_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-16 12:35:00', $festivalOrder->expires_at?->format('Y-m-d H:i:s'));

        $validities = collect(Http::recorded())
            ->map(fn (array $pair): mixed => $pair[0]->data()['validity'] ?? null)
            ->values()
            ->all();
        $this->assertSame([7200, 1800], $validities);

        Carbon::setTestNow();
    }

    public function test_non_monopay_ticket_timing_keeps_the_existing_thirty_minute_deadline(): void
    {
        Carbon::setTestNow('2026-08-16 12:00:00');
        $setting = new IntegrationSetting([
            'provider' => IntegrationProvider::Liqpay,
            'credentials' => [],
        ]);

        $timing = app(TicketPaymentTiming::class)->resolve($setting);

        $this->assertSame(1800, $timing['validity_seconds']);
        $this->assertSame('2026-08-16 12:30:00', $timing['payment_expires_at']->format('Y-m-d H:i:s'));
        $this->assertTrue($timing['payment_expires_at']->equalTo($timing['expires_at']));
        Carbon::setTestNow();
    }

    public function test_gateway_start_failure_immediately_releases_event_and_festival_inventory(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([], 500),
        ]);

        $eventAccount = Account::factory()->create();
        $event = Event::factory()->published()->for($eventAccount)->create();
        $eventSetting = $this->monopaySetting($eventAccount, 600);
        $eventOrder = EventOrder::factory()->for($eventAccount)->for($event)->create([
            'provider' => IntegrationProvider::Monopay->value,
            'amount_cents' => 100,
        ]);

        try {
            app(StartEventOrderPayment::class)->execute($eventOrder, $eventSetting);
            $this->fail('The Event payment start should fail.');
        } catch (Throwable) {
        }

        $eventOrder->refresh();
        $this->assertSame(EventOrderStatus::Failed, $eventOrder->status);
        $this->assertNull($eventOrder->payment_expires_at);
        $this->assertNull($eventOrder->expires_at);

        [$festivalAccount, $edition] = $this->festival();
        $this->monopaySetting($festivalAccount, 600);
        $festivalOrder = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $festivalAccount->id,
            'provider' => IntegrationProvider::Monopay->value,
            'amount_cents' => 100,
        ]);

        try {
            app(FestivalPaymentService::class)->startOrder($festivalOrder);
            $this->fail('The Festival payment start should fail.');
        } catch (Throwable) {
        }

        $festivalOrder->refresh();
        $this->assertSame(FestivalTicketOrderStatus::Failed, $festivalOrder->status);
        $this->assertNull($festivalOrder->payment_expires_at);
        $this->assertNull($festivalOrder->expires_at);
    }

    public function test_expiry_commands_only_expire_due_pending_orders_and_keep_attempts(): void
    {
        Carbon::setTestNow('2026-08-16 12:00:00');
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $eventDue = EventOrder::factory()->for($account)->for($event)->create(['expires_at' => now()]);
        $eventFuture = EventOrder::factory()->for($account)->for($event)->create(['expires_at' => now()->addSecond()]);
        $eventNull = EventOrder::factory()->for($account)->for($event)->create(['expires_at' => null]);
        $eventPaid = EventOrder::factory()->for($account)->for($event)->create([
            'status' => EventOrderStatus::Paid,
            'expires_at' => now()->subMinute(),
        ]);

        [$festivalAccount, $edition] = $this->festival();
        $festivalDue = FestivalTicketOrder::factory()->for($edition)->create(['account_id' => $festivalAccount->id, 'expires_at' => now()]);
        $festivalFuture = FestivalTicketOrder::factory()->for($edition)->create(['account_id' => $festivalAccount->id, 'expires_at' => now()->addSecond()]);
        $festivalNull = FestivalTicketOrder::factory()->for($edition)->create(['account_id' => $festivalAccount->id, 'expires_at' => null]);
        $festivalPaid = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $festivalAccount->id,
            'status' => FestivalTicketOrderStatus::Paid,
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('event-orders:expire')->assertSuccessful();
        $this->artisan('festival-ticket-orders:expire')->assertSuccessful();
        $this->artisan('event-orders:expire')->assertSuccessful();
        $this->artisan('festival-ticket-orders:expire')->assertSuccessful();

        $this->assertSame(EventOrderStatus::Expired, $eventDue->refresh()->status);
        $this->assertSame(EventOrderStatus::Pending, $eventFuture->refresh()->status);
        $this->assertSame(EventOrderStatus::Pending, $eventNull->refresh()->status);
        $this->assertSame(EventOrderStatus::Paid, $eventPaid->refresh()->status);
        $this->assertNotNull($eventDue->failed_at);
        $this->assertDatabaseHas('event_orders', ['id' => $eventDue->id]);
        $this->get(route('public.event-orders.show', [$account->slug, $eventDue->access_token_encrypted]))
            ->assertOk()
            ->assertSee(__('app.event_order_state_expired'));

        $this->assertSame(FestivalTicketOrderStatus::Expired, $festivalDue->refresh()->status);
        $this->assertSame(FestivalTicketOrderStatus::Pending, $festivalFuture->refresh()->status);
        $this->assertSame(FestivalTicketOrderStatus::Pending, $festivalNull->refresh()->status);
        $this->assertSame(FestivalTicketOrderStatus::Paid, $festivalPaid->refresh()->status);
        $this->assertNotNull($festivalDue->failed_at);
        $this->assertDatabaseHas('festival_ticket_orders', ['id' => $festivalDue->id]);
        $this->get(route('public.festival-orders.show', [$festivalAccount->slug, $festivalDue->access_token_encrypted]))
            ->assertOk()
            ->assertSee(__('app.festival_order_state_expired'));
        Carbon::setTestNow();
    }

    public function test_payment_resume_stops_at_invoice_deadline_while_callback_grace_remains_pending(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $order = EventOrder::factory()->for($account)->for($event)->create([
            'provider' => IntegrationProvider::Monopay->value,
            'payment_expires_at' => now()->subSecond(),
            'expires_at' => now()->addMinutes(5),
            'gateway_checkout_payload' => [
                '_launcher' => [
                    'type' => 'iframe',
                    'url' => 'https://pay.monobank.ua/invoice/expired-action',
                    'method' => 'GET',
                    'fields' => [],
                ],
                'request' => ['displayType' => 'iframe'],
                'response' => ['pageUrl' => 'https://pay.monobank.ua/invoice/expired-action'],
            ],
        ]);
        $returnUrl = route('public.event-orders.show', [$account->slug, $order->access_token_encrypted]);
        $paymentUrl = route('public.event-orders.payment', [$account->slug, $order->access_token_encrypted]);

        $this->get($returnUrl)
            ->assertOk()
            ->assertDontSee($paymentUrl, false)
            ->assertSee(__('app.event_order_confirming_payment'));
        $this->get($paymentUrl)->assertRedirect($returnUrl);
        $this->assertSame(EventOrderStatus::Pending, $order->refresh()->status);
    }

    public function test_admin_views_show_expired_attempts_deadlines_and_festival_order_filters(): void
    {
        $owner = User::factory()->create();
        [$account, $edition] = $this->festival();
        $account->addOwner($owner);
        $order = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'provider' => IntegrationProvider::Monopay->value,
            'status' => FestivalTicketOrderStatus::Expired,
            'source' => 'checkout',
            'order_id' => 'FTO-EXPIRED-AUDIT',
            'gateway_invoice_id' => 'mono-expired-invoice',
            'payment_expires_at' => '2026-08-16 12:30:00',
            'expires_at' => '2026-08-16 12:35:00',
        ]);
        FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'provider' => IntegrationProvider::Liqpay->value,
            'status' => FestivalTicketOrderStatus::Failed,
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.tickets', [
            $account,
            $edition,
            'tab' => 'orders',
            'q' => 'EXPIRED-AUDIT',
            'status' => FestivalTicketOrderStatus::Expired->value,
            'source' => 'checkout',
            'provider' => IntegrationProvider::Monopay->value,
        ]));

        $response->assertOk()
            ->assertSee('data-festival-order-row="'.$order->id.'"', false)
            ->assertSee('mono-expired-invoice')
            ->assertSee(__('app.festival_order_expired'))
            ->assertSee(__('app.ticket_payment_invoice_deadline'))
            ->assertSee(__('app.ticket_inventory_reservation_deadline'));
        $this->assertSame(1, $response->viewData('orders')->total());

        $event = Event::factory()->published()->for($account)->create();
        $eventOrder = EventOrder::factory()->for($account)->for($event)->create([
            'status' => EventOrderStatus::Expired,
            'payment_expires_at' => '2026-08-16 12:30:00',
            'expires_at' => '2026-08-16 12:35:00',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.orders.index', [$account, $event]))
            ->assertOk()
            ->assertSee('data-event-order-row="'.$eventOrder->id.'"', false)
            ->assertSee(__('app.event_order_status_expired'))
            ->assertSee(__('app.ticket_payment_invoice_deadline'))
            ->assertSee(__('app.ticket_inventory_reservation_deadline'));
    }

    private function monopaySetting(Account $account, ?int $validitySeconds = null): IntegrationSetting
    {
        return IntegrationSetting::query()->create([
            'scope_type' => IntegrationScope::Account->value,
            'scope_id' => $account->id,
            'account_id' => $account->id,
            'provider' => IntegrationProvider::Monopay->value,
            'category' => IntegrationCategory::Payment->value,
            'is_enabled' => true,
            'credentials' => array_filter([
                'api_token' => 'ticket-payment-token',
                'invoice_validity_seconds' => $validitySeconds,
            ], fn (mixed $value): bool => $value !== null),
        ]);
    }

    /** @return array{Account, FestivalEdition} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);

        return [$account, $edition];
    }
}
