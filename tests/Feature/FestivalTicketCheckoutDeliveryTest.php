<?php

namespace Tests\Feature;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FestivalTicketStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\FestivalTicket;
use App\Models\FestivalTicketOrder;
use App\Models\FestivalTicketOrderItem;
use App\Models\IntegrationSetting;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Payments\MonopayCheckoutSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FestivalTicketCheckoutDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_festival_monopay_ticket_checkout_uses_the_shared_iframe_flow_when_enabled(): void
    {
        app(MonopayCheckoutSettings::class)->saveEventIframeV2Enabled(true);
        [$account, $edition, $type, $guest] = $this->paidFestivalCheckoutContext();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'festival-mono-v2',
                'pageUrl' => 'https://pay.monobank.ua/invoice/festival-mono-v2',
                'status' => 'created',
            ]),
        ]);

        $checkout = $this->actingAs($guest, 'festival')->post(
            route('public.festivals.admission.store', [$account->slug, $edition->slug]),
            $this->festivalCheckoutPayload($guest, $type),
        );
        $order = FestivalTicketOrder::query()->whereBelongsTo($account)->sole();
        $paymentUrl = route('public.festival-orders.payment', [$account->slug, $order->access_token_encrypted]);
        $returnUrl = route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]);

        $checkout->assertRedirect($paymentUrl);
        $this->assertSame('iframe', data_get($order->gateway_checkout_payload, 'request.displayType'));
        $this->assertSame($returnUrl, data_get($order->gateway_checkout_payload, 'request.redirectUrl'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/invoice/create'
            && $request->data()['displayType'] === 'iframe');

        $this->get($paymentUrl)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "frame-src https://pay.monobank.ua; frame-ancestors 'self'")
            ->assertHeader('Permissions-Policy', 'payment=(self "https://pay.monobank.ua")')
            ->assertSee('data-ticket-monopay-iframe', false)
            ->assertSee('data-ticket-order-poll', false)
            ->assertSee('src="https://pay.monobank.ua/invoice/festival-mono-v2"', false)
            ->assertSee('width="100%"', false)
            ->assertSee('allow="payment *"', false)
            ->assertSee(__('app.festival_monopay_open_direct'))
            ->assertSee('alt="Visa"', false)
            ->assertSee('alt="Mastercard"', false)
            ->assertDontSee('alt="Google Pay"', false)
            ->assertDontSee('alt="Apple Pay"', false);

        $this->get($returnUrl)
            ->assertOk()
            ->assertSee('data-ticket-order-poll', false)
            ->assertSee($paymentUrl, false)
            ->assertSee(route('festival.portal.guest.dashboard', $account->slug), false)
            ->assertSee(__('app.festival_order_return_to_tickets'));

        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $this->get(route('public.festival-orders.payment', [$otherAccount->slug, $order->access_token_encrypted]))
            ->assertNotFound();

        $order->update(['status' => FestivalTicketOrderStatus::Failed]);
        $this->get($paymentUrl)->assertRedirect($returnUrl);
    }

    public function test_festival_monopay_ticket_checkout_keeps_the_redirect_when_the_shared_flag_is_disabled(): void
    {
        app(MonopayCheckoutSettings::class)->saveEventIframeV2Enabled(false);
        [$account, $edition, $type, $guest] = $this->paidFestivalCheckoutContext();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'festival-mono-v1',
                'pageUrl' => 'https://pay.monobank.ua/invoice/festival-mono-v1',
                'status' => 'created',
            ]),
        ]);

        $this->actingAs($guest, 'festival')
            ->post(
                route('public.festivals.admission.store', [$account->slug, $edition->slug]),
                $this->festivalCheckoutPayload($guest, $type),
            )
            ->assertRedirect('https://pay.monobank.ua/invoice/festival-mono-v1');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/invoice/create'
            && ! array_key_exists('displayType', $request->data()));
    }

    public function test_festival_participant_charge_remains_redirected_when_ticket_iframe_is_enabled(): void
    {
        app(MonopayCheckoutSettings::class)->saveEventIframeV2Enabled(true);
        [$account, $edition] = $this->festival();
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($category)->for($portalUser, 'portalUser')->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
        ]);
        $charge = $entry->charges()->create([
            'account_id' => $account->id,
            'code' => 'FCH-REDIRECT',
            'kind' => 'participation',
            'name' => 'Participation fee',
            'amount_cents' => 50000,
            'currency' => 'UAH',
            'due_at' => now()->addDay(),
        ]);
        $this->accountPaymentIntegration($account, IntegrationProvider::Monopay, ['api_token' => 'festival-charge-token']);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'festival-charge-redirect',
                'pageUrl' => 'https://pay.monobank.ua/invoice/festival-charge-redirect',
                'status' => 'created',
            ]),
        ]);

        $checkout = app(FestivalPaymentService::class)->startCharge($charge, IntegrationProvider::Monopay->value);

        $this->assertTrue($checkout->isRedirect());
        $this->assertSame('https://pay.monobank.ua/invoice/festival-charge-redirect', $checkout->url);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/invoice/create'
            && ! array_key_exists('displayType', $request->data()));
    }

    public function test_paid_festival_venue_tickets_have_private_qr_pdf_share_and_print_delivery(): void
    {
        [$account, $edition] = $this->festival();
        $venueType = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'delivery_mode' => FestivalAdmissionDeliveryMode::Venue,
            'name' => 'Festival floor',
        ]);
        $onlineType = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'delivery_mode' => FestivalAdmissionDeliveryMode::OnlineStream,
            'name' => 'Online stream',
        ]);
        $order = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'status' => FestivalTicketOrderStatus::Paid,
            'buyer_email' => 'private-festival-buyer@example.com',
            'paid_at' => now(),
            'expires_at' => null,
        ]);
        $venueItem = $this->orderItem($order, $venueType, 3);
        $onlineItem = $this->orderItem($order, $onlineType, 1);
        $first = $this->ticket($order, $venueItem, $venueType, 'FST-ONE1-ONE1');
        $second = $this->ticket($order, $venueItem, $venueType, 'FST-TWO2-TWO2');
        $voided = $this->ticket($order, $venueItem, $venueType, 'FST-VOID-VOID', FestivalTicketStatus::Voided);
        $online = $this->ticket($order, $onlineItem, $onlineType, 'FST-ONLN-ONLN');
        $token = $order->access_token_encrypted;

        $this->get(route('public.festival-orders.status', [$account->slug, $token]))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertJson([
                'status' => FestivalTicketOrderStatus::Paid->value,
                'terminal' => true,
                'paid' => true,
                'tickets_ready' => true,
                'festival_cancelled' => false,
            ]);

        $page = $this->get(route('public.festival-orders.show', [$account->slug, $token]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertSee('data-ticket-pdf-share', false)
            ->assertSee('data-ticket-pdf-download', false)
            ->assertSee('data-print-button', false)
            ->assertSee('data-ticket-print-page', false)
            ->assertSee($first->code)
            ->assertSee($second->code)
            ->assertDontSee($voided->code)
            ->assertDontSee($online->code)
            ->assertSee(__('app.festival_order_tickets_emailed', ['address' => $order->buyer_email]));
        $this->assertSame(2, substr_count((string) $page->getContent(), 'data-festival-ticket-row'));

        $pdf = $this->get(route('public.festival-orders.pdf', [$account->slug, $token]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertMatchesRegularExpression('/attachment; filename=festival-tickets-'.preg_quote($order->order_id, '/').'\.pdf/', (string) $pdf->headers->get('Content-Disposition'));
        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page\b/', $pdf->getContent()));
        $this->assertStringNotContainsString($order->buyer_email, $pdf->getContent());

        $this->get(route('public.festival-tickets.qr', [$account->slug, $token, $first->code]))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('public.festival-tickets.qr', [$account->slug, $token, $voided->code]))->assertNotFound();
        $this->get(route('public.festival-tickets.qr', [$account->slug, $token, $online->code]))->assertNotFound();

        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $this->get(route('public.festival-orders.show', [$otherAccount->slug, $token]))->assertNotFound();
        $this->get(route('public.festival-orders.status', [$otherAccount->slug, $token]))->assertNotFound();
        $this->get(route('public.festival-orders.pdf', [$otherAccount->slug, $token]))->assertNotFound();
    }

    public function test_non_success_festival_order_states_never_expose_ticket_delivery_controls(): void
    {
        [$account, $edition] = $this->festival();
        $type = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'delivery_mode' => FestivalAdmissionDeliveryMode::Venue,
        ]);
        $order = FestivalTicketOrder::factory()->for($edition)->create(['account_id' => $account->id]);
        $item = $this->orderItem($order, $type, 1);
        $ticket = $this->ticket($order, $item, $type, 'FST-STAT-STAT');
        $token = $order->access_token_encrypted;

        foreach ([
            FestivalTicketOrderStatus::Pending,
            FestivalTicketOrderStatus::Failed,
            FestivalTicketOrderStatus::Cancelled,
            FestivalTicketOrderStatus::Expired,
            FestivalTicketOrderStatus::PaidRequiresRefund,
            FestivalTicketOrderStatus::Refunded,
        ] as $status) {
            $order->forceFill(['status' => $status])->save();

            $this->get(route('public.festival-orders.show', [$account->slug, $token]))
                ->assertOk()
                ->assertDontSee('data-ticket-pdf-share', false)
                ->assertDontSee('data-ticket-pdf-download', false)
                ->assertDontSee($ticket->code);
            $this->get(route('public.festival-orders.status', [$account->slug, $token]))
                ->assertOk()
                ->assertJson([
                    'status' => $status->value,
                    'terminal' => $status !== FestivalTicketOrderStatus::Pending,
                    'paid' => false,
                    'tickets_ready' => false,
                ]);
            $this->get(route('public.festival-orders.pdf', [$account->slug, $token]))->assertNotFound();
        }

        $order->update(['status' => FestivalTicketOrderStatus::Paid]);
        $edition->update(['cancelled_at' => now()]);
        $this->get(route('public.festival-orders.show', [$account->slug, $token]))
            ->assertOk()
            ->assertDontSee('data-ticket-pdf-share', false);
        $this->get(route('public.festival-orders.pdf', [$account->slug, $token]))->assertNotFound();
        $this->get(route('public.festival-tickets.qr', [$account->slug, $token, $ticket->code]))->assertNotFound();
    }

    /** @return array{Account, FestivalEdition, FestivalAdmissionType, FestivalPortalUser} */
    private function paidFestivalCheckoutContext(): array
    {
        [$account, $edition] = $this->festival();
        $type = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'delivery_mode' => FestivalAdmissionDeliveryMode::Venue,
            'price_cents' => 100,
            'inventory' => 10,
            'max_per_order' => 10,
        ]);
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        $this->accountPaymentIntegration($account, IntegrationProvider::Monopay, ['api_token' => 'festival-mono-token']);

        return [$account, $edition, $type, $guest];
    }

    /** @return array{Account, FestivalEdition} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'venue_name' => 'Main hall',
            'venue_address' => '1 Festival Street',
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addHours(6),
        ]);

        return [$account, $edition];
    }

    /** @return array<string, mixed> */
    private function festivalCheckoutPayload(FestivalPortalUser $guest, FestivalAdmissionType $type): array
    {
        return [
            'buyer_name' => $guest->displayName(),
            'buyer_email' => $guest->email,
            'buyer_phone' => $guest->phone,
            'provider' => IntegrationProvider::Monopay->value,
            'items' => [['admission_type_id' => $type->id, 'quantity' => 1]],
            'terms' => '1',
        ];
    }

    private function orderItem(FestivalTicketOrder $order, FestivalAdmissionType $type, int $quantity): FestivalTicketOrderItem
    {
        return $order->items()->create([
            'account_id' => $order->account_id,
            'festival_admission_type_id' => $type->id,
            'admission_name' => $type->name,
            'unit_price_cents' => $type->price_cents,
            'quantity' => $quantity,
            'total_cents' => $type->price_cents * $quantity,
        ]);
    }

    private function ticket(
        FestivalTicketOrder $order,
        FestivalTicketOrderItem $item,
        FestivalAdmissionType $type,
        string $code,
        FestivalTicketStatus $status = FestivalTicketStatus::Valid,
    ): FestivalTicket {
        $token = Str::random(64);

        return FestivalTicket::query()->create([
            'account_id' => $order->account_id,
            'festival_edition_id' => $order->festival_edition_id,
            'festival_ticket_order_id' => $order->id,
            'festival_ticket_order_item_id' => $item->id,
            'festival_admission_type_id' => $type->id,
            'code' => $code,
            'token_encrypted' => $token,
            'token_hash' => hash('sha256', $token),
            'status' => $status,
        ]);
    }

    /** @param array<string, mixed> $credentials */
    private function accountPaymentIntegration(Account $account, IntegrationProvider $provider, array $credentials): IntegrationSetting
    {
        return IntegrationSetting::query()->create([
            'scope_type' => IntegrationScope::Account->value,
            'scope_id' => $account->id,
            'account_id' => $account->id,
            'provider' => $provider->value,
            'category' => IntegrationCategory::Payment->value,
            'is_enabled' => true,
            'credentials' => $credentials,
        ]);
    }
}
