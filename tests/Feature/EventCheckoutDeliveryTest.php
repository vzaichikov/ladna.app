<?php

namespace Tests\Feature;

use App\Actions\CreateEventOrder;
use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Enums\EventTicketStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventOrderItem;
use App\Models\EventTicketType;
use App\Models\FestivalPortalUser;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\Payments\MonopayCheckoutSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EventCheckoutDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_event_renders_the_full_checkout_contract_without_a_visible_quantity_input(): void
    {
        $this->enableGoogle();
        $account = Account::factory()->create([
            'studio_rules_html' => '<p>Studio rules</p>',
            'public_offer_html' => '<p>Studio offer</p>',
        ]);
        $event = Event::factory()->published()->for($account)->create([
            'capacity' => 3,
            'rules_html' => '<p>Event rules</p>',
        ]);
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 25000,
            'early_bird_price_cents' => 20000,
            'early_bird_ends_at' => now()->addDay(),
            'early_bird_quota' => 1,
            'inventory' => 5,
            'max_per_order' => 4,
        ]);
        $this->accountPaymentIntegration($account, IntegrationProvider::Monopay, [
            'api_token' => 'mono-token',
        ]);
        $this->accountPaymentIntegration($account, IntegrationProvider::Liqpay, [
            'public_key' => 'public-key',
            'private_key' => 'private-key',
        ]);

        $this->get(route('public.events.show', [$account->slug, $event->slug]))
            ->assertOk()
            ->assertSee('href="#buy-tickets"', false)
            ->assertSee('mt-5 w-full lg:hidden', false)
            ->assertSee('id="buy-tickets"', false)
            ->assertSee('data-event-ticket-checkout', false)
            ->assertSee('data-event-capacity="3"', false)
            ->assertSee('data-event-has-paid-ticket-options="true"', false)
            ->assertSee('lg:grid-cols-[minmax(0,1fr)_minmax(20rem,0.72fr)]', false)
            ->assertSee('data-event-ticket-decrement', false)
            ->assertSee('data-event-ticket-increment', false)
            ->assertSee('data-regular-price-cents="25000"', false)
            ->assertSee('data-early-bird-price-cents="20000"', false)
            ->assertSee('data-early-bird-max-quantity="1"', false)
            ->assertSee('type="hidden" name="items['.$ticketType->id.']"', false)
            ->assertDontSee('type="number"', false)
            ->assertSee('name="buyer_email_confirmation"', false)
            ->assertSee('name="buyer_phone" value="" required', false)
            ->assertSee(__('app.phone'))
            ->assertDontSee(__('app.phone_optional'))
            ->assertSee('data-phone-mask', false)
            ->assertSee('data-phone-mask-reject-national-zero', false)
            ->assertSee(__('app.event_ticket_email_delivery_title'))
            ->assertSee(route('public.events.checkout.google', [$account->slug, $event->slug]), false)
            ->assertSee(route('public.studio-offer', $account->slug), false)
            ->assertSee('data-lucide="credit-card"', false)
            ->assertSee(__('app.pay_by_card'))
            ->assertSee('alt="Google Pay"', false)
            ->assertSee('alt="Apple Pay"', false)
            ->assertSee('alt="Visa"', false)
            ->assertSee('alt="Mastercard"', false)
            ->assertSee('data-event-payment-select-help', false)
            ->assertSee('data-event-payment-required-help', false)
            ->assertSee(__('app.event_complete_required_fields_first'))
            ->assertSeeInOrder(['data-event-payment-required-help="paid"', 'data-event-paid-action'], false)
            ->assertSee('name="provider" value="monopay"', false)
            ->assertSee('name="provider" value="liqpay"', false)
            ->assertSee('LiqPay')
            ->assertDontSee('Monopay');

        $this->withSession(['locale' => 'uk'])
            ->get(route('public.events.show', [$account->slug, $event->slug]))
            ->assertOk()
            ->assertSee('Сплатити карткою');
    }

    public function test_free_event_blocking_help_is_rendered_above_its_disabled_action(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 0,
            'inventory' => 5,
        ]);

        $page = $this->get(route('public.events.show', [$account->slug, $event->slug]));

        $page->assertOk()
            ->assertSeeInOrder(['data-event-payment-required-help="free"', 'data-event-free-action'], false);
        $this->assertMatchesRegularExpression(
            '/<button(?=[^>]*data-event-free-action)(?=[^>]*disabled)[^>]*>/s',
            (string) $page->getContent(),
        );
    }

    public function test_public_event_shows_the_early_bird_quantity_and_local_cutoff_until_the_quota_is_exhausted(): void
    {
        $account = Account::factory()->create(['default_language' => 'en']);
        $event = Event::factory()->published()->for($account)->create([
            'capacity' => 40,
            'timezone' => 'Europe/Kyiv',
        ]);
        $earlyBirdEndsAt = now()->addDay()->startOfMinute();
        $limitedTicketType = EventTicketType::factory()->for($account)->for($event)->create([
            'name' => 'Limited early bird',
            'inventory' => 40,
            'price_cents' => 70000,
            'early_bird_price_cents' => 60000,
            'early_bird_ends_at' => $earlyBirdEndsAt,
            'early_bird_quota' => 20,
        ]);
        EventTicketType::factory()->for($account)->for($event)->create([
            'name' => 'Date-only early bird',
            'inventory' => 40,
            'price_cents' => 70000,
            'early_bird_price_cents' => 60000,
            'early_bird_ends_at' => $earlyBirdEndsAt,
            'early_bird_quota' => null,
        ]);
        $order = EventOrder::factory()->for($account)->for($event)->create([
            'status' => EventOrderStatus::Paid,
            'expires_at' => null,
        ]);
        $earlyBirdItem = EventOrderItem::factory()->create([
            'account_id' => $account->id,
            'event_id' => $event->id,
            'event_order_id' => $order->id,
            'event_ticket_type_id' => $limitedTicketType->id,
            'ticket_type_name' => $limitedTicketType->name,
            'price_tier' => 'early_bird',
            'unit_price_cents' => 60000,
            'quantity' => 3,
            'total_cents' => 180000,
        ]);
        $cutoff = $earlyBirdEndsAt->copy()->timezone($event->timezone)->format('d.m.Y H:i');

        $this->withSession(['locale' => 'en'])
            ->get(route('public.events.show', [$account->slug, $event->slug]))
            ->assertOk()
            ->assertSee(trans('app.event_early_bird_limited_availability', [
                'remaining' => 17,
                'quota' => 20,
                'date' => $cutoff,
            ], 'en'))
            ->assertSee(trans('app.event_early_bird_availability_until', ['date' => $cutoff], 'en'))
            ->assertSeeInOrder(['Limited early bird', '17 of 20 tickets left'], false);

        $this->withSession(['locale' => 'uk'])
            ->get(route('public.events.show', [$account->slug, $event->slug]))
            ->assertOk()
            ->assertSee(trans('app.event_early_bird_limited_availability', [
                'remaining' => 17,
                'quota' => 20,
                'date' => $cutoff,
            ], 'uk'));

        $earlyBirdItem->update([
            'quantity' => 20,
            'total_cents' => 1200000,
        ]);

        $page = $this->withSession(['locale' => 'en'])
            ->get(route('public.events.show', [$account->slug, $event->slug]))
            ->assertOk()
            ->assertDontSee(trans('app.event_early_bird_limited_availability', [
                'remaining' => 0,
                'quota' => 20,
                'date' => $cutoff,
            ], 'en'))
            ->assertSee(trans('app.event_early_bird_availability_until', ['date' => $cutoff], 'en'));

        $this->assertSame(1, substr_count((string) $page->getContent(), 'data-event-early-bird-availability'));
    }

    public function test_paid_event_checkout_renders_an_explicit_unavailable_provider_state(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 25000,
            'inventory' => 5,
        ]);

        $this->get(route('public.events.show', [$account->slug, $event->slug]))
            ->assertOk()
            ->assertSee('data-event-payment-unavailable', false)
            ->assertSee(__('app.no_payment_methods_available'));
    }

    public function test_events_monopay_checkout_keeps_the_existing_redirect_when_v2_is_disabled(): void
    {
        [$account, $event, $ticketType] = $this->paidEventCheckoutContext();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'event-mono-v1',
                'pageUrl' => 'https://pay.monobank.ua/invoice/event-mono-v1',
                'status' => 'created',
            ]),
        ]);

        $this->from(route('public.events.show', [$account->slug, $event->slug]))
            ->post(route('public.events.checkout', [$account->slug, $event->slug]), $this->paidEventPayload($ticketType))
            ->assertRedirect('https://pay.monobank.ua/invoice/event-mono-v1');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/invoice/create'
            && ! array_key_exists('displayType', $request->data()));
    }

    public function test_events_monopay_v2_creates_an_iframe_invoice_and_private_payment_page(): void
    {
        app(MonopayCheckoutSettings::class)->saveEventIframeV2Enabled(true);
        [$account, $event, $ticketType] = $this->paidEventCheckoutContext();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'event-mono-v2',
                'pageUrl' => 'https://pay.monobank.ua/invoice/event-mono-v2',
                'status' => 'created',
            ]),
        ]);

        $checkout = $this->from(route('public.events.show', [$account->slug, $event->slug]))
            ->post(route('public.events.checkout', [$account->slug, $event->slug]), $this->paidEventPayload($ticketType));
        $order = EventOrder::query()->whereBelongsTo($account)->sole();
        $paymentUrl = route('public.event-orders.payment', [$account->slug, $order->access_token_encrypted]);
        $returnUrl = route('public.event-orders.show', [$account->slug, $order->access_token_encrypted]);

        $checkout->assertRedirect($paymentUrl);
        $this->assertSame('iframe', data_get($order->gateway_checkout_payload, 'request.displayType'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/invoice/create'
            && $request->data()['displayType'] === 'iframe');

        $this->get($paymentUrl)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "frame-src https://pay.monobank.ua; frame-ancestors 'self'")
            ->assertHeader('Permissions-Policy', 'payment=(self "https://pay.monobank.ua")')
            ->assertSee('data-event-monopay-iframe', false)
            ->assertSee('src="https://pay.monobank.ua/invoice/event-mono-v2"', false)
            ->assertSee('allow="payment *"', false)
            ->assertSee('width="100%"', false)
            ->assertSee('height="700"', false)
            ->assertSee('class="h-[700px] w-full max-w-[600px] rounded-3xl border-0 bg-white md:h-[600px]', false)
            ->assertDontSee('data-event-monopay-mobile-fallback', false)
            ->assertSee('data-event-order-poll', false)
            ->assertDontSee('alt="Google Pay"', false)
            ->assertDontSee('alt="Apple Pay"', false)
            ->assertSee(__('app.event_monopay_open_direct'))
            ->assertSee('alt="Visa"', false)
            ->assertSee('alt="Mastercard"', false);

        $this->get($returnUrl)
            ->assertOk()
            ->assertSee($paymentUrl, false)
            ->assertSee(__('app.event_monopay_resume_payment'))
            ->assertSee('data-event-order-return-to-event', false)
            ->assertSee(route('public.events.show', [$account->slug, $event->slug]), false)
            ->assertSee(__('app.event_order_return_to_event'));

        $otherAccount = Account::factory()->create();
        $this->get(route('public.event-orders.payment', [$otherAccount->slug, $order->access_token_encrypted]))
            ->assertNotFound();

        $order->update(['status' => EventOrderStatus::Failed]);
        $this->get($paymentUrl)->assertRedirect($returnUrl);
    }

    public function test_events_monopay_v2_rejects_an_untrusted_iframe_checkout_url(): void
    {
        app(MonopayCheckoutSettings::class)->saveEventIframeV2Enabled(true);
        [$account, $event, $ticketType] = $this->paidEventCheckoutContext();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'event-mono-invalid-host',
                'pageUrl' => 'https://payments.example.test/event-mono-invalid-host',
                'status' => 'created',
            ]),
        ]);

        $this->from(route('public.events.show', [$account->slug, $event->slug]))
            ->post(route('public.events.checkout', [$account->slug, $event->slug]), $this->paidEventPayload($ticketType))
            ->assertRedirect(route('public.events.show', [$account->slug, $event->slug]))
            ->assertSessionHasErrors('provider');

        $this->assertSame(EventOrderStatus::Failed, EventOrder::query()->whereBelongsTo($account)->sole()->status);
    }

    public function test_checkout_normalizes_matching_emails_and_requires_a_valid_phone_without_losing_id_keyed_old_input(): void
    {
        Mail::fake();
        $account = Account::factory()->create(['country_code' => 'UA']);
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 0,
            'inventory' => 5,
        ]);

        $this->from(route('public.events.show', [$account->slug, $event->slug]))
            ->post(route('public.events.checkout', [$account->slug, $event->slug]), [
                'buyer_name' => '  Guest Buyer  ',
                'buyer_email' => ' Guest@Example.COM ',
                'buyer_email_confirmation' => 'guest@example.com',
                'buyer_phone' => '067 123 45 67',
                'items' => [$ticketType->id => 1],
                'accept_terms' => '1',
            ])
            ->assertRedirect();

        $order = EventOrder::query()->whereBelongsTo($account)->sole();
        $this->assertSame('Guest Buyer', $order->buyer_name);
        $this->assertSame('guest@example.com', $order->buyer_email);
        $this->assertSame('+380671234567', $order->buyer_phone);

        $this->from(route('public.events.show', [$account->slug, $event->slug]))
            ->post(route('public.events.checkout', [$account->slug, $event->slug]), [
                'buyer_name' => 'Missing Phone Buyer',
                'buyer_email' => 'missing-phone@example.com',
                'buyer_email_confirmation' => 'missing-phone@example.com',
                'items' => [$ticketType->id => 1],
                'accept_terms' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['buyer_phone'])
            ->assertSessionHasInput('items', [$ticketType->id => 1]);

        $this->from(route('public.events.show', [$account->slug, $event->slug]))
            ->post(route('public.events.checkout', [$account->slug, $event->slug]), [
                'buyer_name' => 'Another Buyer',
                'buyer_email' => 'one@example.com',
                'buyer_email_confirmation' => 'two@example.com',
                'buyer_phone' => 'invalid-phone',
                'items' => [$ticketType->id => 1],
                'accept_terms' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['buyer_email', 'buyer_phone'])
            ->assertSessionHasInput('items', [$ticketType->id => 1]);

        $this->from(route('public.events.show', [$account->slug, $event->slug]))
            ->post(route('public.events.checkout', [$account->slug, $event->slug]), [
                'buyer_name' => 'Leading Zero Buyer',
                'buyer_email' => 'leading-zero@example.com',
                'buyer_email_confirmation' => 'leading-zero@example.com',
                'buyer_phone' => '+380 (06) 712-34-56',
                'items' => [$ticketType->id => 1],
                'accept_terms' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['buyer_phone']);

        $this->assertSame(1, EventOrder::query()->whereBelongsTo($account)->count());
    }

    public function test_google_prefill_is_one_time_verified_and_does_not_create_an_identity_or_order(): void
    {
        $this->enableGoogle();
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create();
        $counts = [
            Customer::query()->count(),
            User::query()->count(),
            FestivalPortalUser::query()->count(),
            EventOrder::query()->count(),
        ];

        $start = $this->post(route('public.events.checkout.google', [$account->slug, $event->slug]), [
            'buyer_phone' => '+380671234567',
            'items' => [$ticketType->id => 2],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $location = (string) $start->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertSame(route('public.event-checkout.google.callback'), $query['redirect_uri']);
        $state = (string) $query['state'];

        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'event-google-token']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-subject',
                'name' => 'Verified Guest',
                'email' => 'Verified.Guest@Example.com',
                'email_verified' => true,
            ]),
        ]);
        $callback = route('public.event-checkout.google.callback', [
            'state' => $state,
            'code' => 'one-use-code',
        ]);

        $this->get($callback)
            ->assertRedirect(route('public.events.show', [$account->slug, $event->slug]))
            ->assertSessionHasInput('buyer_name', 'Verified Guest')
            ->assertSessionHasInput('buyer_email', 'verified.guest@example.com')
            ->assertSessionHasInput('buyer_email_confirmation', 'verified.guest@example.com')
            ->assertSessionHasInput('items', [$ticketType->id => 2]);

        $this->get($callback)
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors('google');
        $this->assertSame($counts, [
            Customer::query()->count(),
            User::query()->count(),
            FestivalPortalUser::query()->count(),
            EventOrder::query()->count(),
        ]);
    }

    public function test_google_prefill_rejects_expired_state_before_any_external_request(): void
    {
        $this->enableGoogle();
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();

        $state = $this->beginGooglePrefill($account, $event, ['buyer_name' => 'Expired Draft']);
        $this->travel(11)->minutes();
        Http::preventStrayRequests();

        $this->get(route('public.event-checkout.google.callback', [
            'state' => $state,
            'code' => 'unused-code',
        ]))
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors('google');

        Http::assertNothingSent();
    }

    public function test_google_prefill_rejects_an_unverified_email_and_restores_the_draft(): void
    {
        $this->enableGoogle();
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $state = $this->beginGooglePrefill($account, $event, [
            'buyer_name' => 'Preserved Draft',
            'buyer_phone' => '+380671234567',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'event-google-token']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-subject',
                'email' => 'unverified@example.com',
                'email_verified' => false,
            ]),
        ]);

        $this->get(route('public.event-checkout.google.callback', [
            'state' => $state,
            'code' => 'one-use-code',
        ]))
            ->assertRedirect(route('public.events.show', [$account->slug, $event->slug]))
            ->assertSessionHasErrors('google')
            ->assertSessionHasInput('buyer_name', 'Preserved Draft')
            ->assertSessionHasInput('buyer_phone', '+380671234567');

        $this->assertSame(0, EventOrder::query()->whereBelongsTo($account)->count());
    }

    public function test_private_return_and_status_map_every_non_success_order_state_without_ticket_controls(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 0,
            'inventory' => 10,
        ]);
        $order = app(CreateEventOrder::class)->execute($event, [
            'buyer_name' => 'Status Buyer',
            'buyer_email' => 'status@example.com',
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
            'accept_terms' => true,
        ], 'uk');
        $token = $order->access_token_encrypted;
        $nonSuccessStatuses = [
            EventOrderStatus::Pending,
            EventOrderStatus::Failed,
            EventOrderStatus::Cancelled,
            EventOrderStatus::Expired,
            EventOrderStatus::PaidRequiresRefund,
            EventOrderStatus::RefundRequired,
            EventOrderStatus::Refunded,
        ];

        foreach ($nonSuccessStatuses as $status) {
            $order->forceFill(['status' => $status])->save();

            $this->get(route('public.event-orders.show', [$account->slug, $token]))
                ->assertOk()
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
                ->assertDontSee('data-event-ticket-pdf-share', false)
                ->assertDontSee('data-event-ticket-pdf-download', false);

            $this->get(route('public.event-orders.status', [$account->slug, $token]))
                ->assertOk()
                ->assertJson([
                    'status' => $status->value,
                    'terminal' => $status !== EventOrderStatus::Pending,
                    'paid' => false,
                    'tickets_ready' => false,
                ]);
        }
    }

    public function test_private_status_pdf_and_qr_require_a_paid_order_for_a_published_event(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 0,
            'inventory' => 10,
        ]);
        $order = app(CreateEventOrder::class)->execute($event, [
            'buyer_name' => 'Private Buyer',
            'buyer_email' => 'private-buyer@example.com',
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            'accept_terms' => true,
        ], 'uk');
        $token = $order->access_token_encrypted;

        $this->get(route('public.event-orders.status', [$account->slug, $token]))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertJson([
                'status' => EventOrderStatus::Paid->value,
                'terminal' => true,
                'paid' => true,
                'tickets_ready' => true,
                'event_cancelled' => false,
            ]);

        $this->get(route('public.event-orders.show', [$account->slug, $token]))
            ->assertOk()
            ->assertSee('data-event-ticket-row', false)
            ->assertSee('sm:table-row', false)
            ->assertSee('data-event-ticket-pdf-share', false);

        $pdf = $this->get(route('public.event-orders.pdf', [$account->slug, $token]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertMatchesRegularExpression('/attachment; filename=event-tickets-'.preg_quote($order->order_id, '/').'\.pdf/', (string) $pdf->headers->get('Content-Disposition'));
        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page\b/', $pdf->getContent()));

        $ticket = $order->tickets()->firstOrFail();
        $this->get(route('public.event-tickets.qr', [$account->slug, $token, $ticket->code]))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $order->update(['status' => EventOrderStatus::RefundRequired]);
        $this->get(route('public.event-orders.pdf', [$account->slug, $token]))->assertNotFound();
        $this->get(route('public.event-tickets.qr', [$account->slug, $token, $ticket->code]))->assertNotFound();
        $this->get(route('public.event-orders.status', [$account->slug, $token]))
            ->assertJson([
                'status' => EventOrderStatus::RefundRequired->value,
                'terminal' => true,
                'paid' => false,
                'tickets_ready' => false,
            ]);

        $order->update(['status' => EventOrderStatus::Paid]);
        $event->update(['status' => EventStatus::Cancelled]);
        $this->get(route('public.event-orders.show', [$account->slug, $token]))
            ->assertOk()
            ->assertDontSee('data-event-ticket-pdf-share', false);
        $this->get(route('public.event-orders.pdf', [$account->slug, $token]))->assertNotFound();
        $this->get(route('public.event-tickets.qr', [$account->slug, $token, $ticket->code]))->assertNotFound();
        $this->get(route('public.event-orders.status', [$account->slug, $token]))
            ->assertJson([
                'status' => EventOrderStatus::Paid->value,
                'terminal' => true,
                'paid' => false,
                'tickets_ready' => false,
                'event_cancelled' => true,
            ]);

        $otherAccount = Account::factory()->create();
        $this->get(route('public.event-orders.show', [$otherAccount->slug, $token]))->assertNotFound();
        $this->get(route('public.event-orders.status', [$otherAccount->slug, $token]))->assertNotFound();
        $this->get(route('public.event-orders.pdf', [$otherAccount->slug, $token]))->assertNotFound();
        $this->get(route('public.event-orders.show', [$account->slug, str_repeat('x', 64)]))->assertNotFound();
    }

    public function test_pdf_contains_exactly_one_page_per_valid_ticket(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 0,
            'inventory' => 10,
        ]);
        $order = app(CreateEventOrder::class)->execute($event, [
            'buyer_name' => 'PDF Buyer',
            'buyer_email' => 'pdf-buyer@example.com',
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 3]],
            'accept_terms' => true,
        ], 'uk');
        $invalidTicket = $order->tickets()->latest('id')->firstOrFail();
        $invalidTicket->update(['status' => EventTicketStatus::Refunded]);

        $pdf = $this->get(route('public.event-orders.pdf', [
            $account->slug,
            $order->access_token_encrypted,
        ]))->assertOk();

        $this->assertSame(2, $order->tickets()->where('status', EventTicketStatus::Valid->value)->count());
        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page\b/', $pdf->getContent()));
        $this->get(route('public.event-tickets.qr', [
            $account->slug,
            $order->access_token_encrypted,
            $invalidTicket->code,
        ]))->assertNotFound();
    }

    private function enableGoogle(): void
    {
        IntegrationSetting::query()->create([
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
            'provider' => 'google_oauth',
            'category' => IntegrationCategory::Authentication->value,
            'is_enabled' => true,
            'credentials' => [
                'client_id' => 'event-google-client',
                'client_secret' => 'event-google-secret',
            ],
        ]);
    }

    /**
     * @return array{0: Account, 1: Event, 2: EventTicketType}
     */
    private function paidEventCheckoutContext(): array
    {
        $account = Account::factory()->create();
        $event = Event::factory()->published()->for($account)->create();
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 25000,
            'inventory' => 5,
        ]);
        $this->accountPaymentIntegration($account, IntegrationProvider::Monopay, [
            'api_token' => 'event-mono-token',
        ]);

        return [$account, $event, $ticketType];
    }

    /**
     * @return array<string, mixed>
     */
    private function paidEventPayload(EventTicketType $ticketType): array
    {
        return [
            'buyer_name' => 'Iframe Buyer',
            'buyer_email' => 'iframe-buyer@example.com',
            'buyer_email_confirmation' => 'iframe-buyer@example.com',
            'buyer_phone' => '+380671234567',
            'items' => [$ticketType->id => 1],
            'provider' => IntegrationProvider::Monopay->value,
            'accept_terms' => '1',
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function beginGooglePrefill(Account $account, Event $event, array $draft): string
    {
        $response = $this->post(route('public.events.checkout.google', [$account->slug, $event->slug]), $draft)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        parse_str(parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);

        return (string) $query['state'];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function accountPaymentIntegration(
        Account $account,
        IntegrationProvider $provider,
        array $credentials,
    ): IntegrationSetting {
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
