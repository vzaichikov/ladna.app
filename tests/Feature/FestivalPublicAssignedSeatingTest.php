<?php

namespace Tests\Feature;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalSeat;
use App\Models\FestivalSeries;
use App\Models\FestivalTicketOrder;
use App\Models\IntegrationSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FestivalPublicAssignedSeatingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seating_routes_are_closed_for_an_edition_without_assigned_seating(): void
    {
        [$account, $edition] = $this->festival();
        $route = [$account->slug, $edition->slug];

        $this->get(route('public.festivals.show', $route))
            ->assertOk()->assertDontSee('data-festival-seat-picker', false);
        $this->getJson(route('public.festivals.admission.seats', $route))->assertNotFound();
        $this->postJson(route('public.festivals.admission.seat-hold', $route), ['seat_ids' => []])->assertNotFound();
    }

    public function test_mixed_checkout_uses_a_session_hold_and_issues_the_original_seat_once(): void
    {
        Queue::fake();
        [$account, $edition] = $this->festival();
        $seat = $this->assignedSeat($edition);
        $general = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id, 'inventory' => 10, 'price_cents' => 0,
        ]);
        $route = [$account->slug, $edition->slug];
        $map = route('public.festivals.admission.seats', $route);
        $hold = route('public.festivals.admission.seat-hold', $route);

        $landing = $this->get(route('public.festivals.show', $route))->assertOk()->assertSee('data-festival-seat-picker', false);
        $this->keepBrowserSession($landing);
        $this->getJson($map)->assertOk()->assertJsonPath('seats.0.available', true)->assertJsonMissingPath('seats.0.hold_key');
        $draft = $this->postJson($hold, ['seat_ids' => [$seat->id]])->assertOk()->json('hold');
        $this->withSession(['festival_seat_checkout' => []]);
        $this->getJson($map)->assertOk()->assertJsonPath('hold.checkout_id', $draft['checkout_id'])
            ->assertJsonPath('hold.seat_ids', [$seat->id]);

        $cart = ['items' => [$general->id => 1], 'seat_ids' => [$seat->id], 'seat_checkout_id' => $draft['checkout_id']];
        $quote = $this->postJson(route('public.festivals.admission.promo-code', $route), $cart)
            ->assertOk()->assertJsonPath('total_cents', 0)->json('seat_quote');
        $orderInput = [
            ...$cart,
            'seat_quote' => $quote, 'seat_quote_total' => 0, 'terms' => 1,
            'buyer_name' => 'Olena Viewer', 'buyer_email' => 'viewer@example.com',
            'buyer_email_confirmation' => 'viewer@example.com', 'buyer_phone' => '0501112233',
        ];

        $this->postJson(route('public.festivals.admission.store', $route), [...$orderInput, 'seat_checkout_id' => str_repeat('z', 48)])
            ->assertUnprocessable()->assertJsonValidationErrors('seat_ids');

        $endpoint = route('public.festivals.admission.store', $route);
        $this->post($endpoint, [...$orderInput, 'seat_quote_total' => 1])->assertSessionHasErrors('seat_quote');
        $this->post($endpoint, $orderInput)->assertSessionHasNoErrors()->assertRedirect();
        $order = FestivalTicketOrder::query()->whereBelongsTo($account)->sole();
        $orderUrl = route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]);

        $this->post($endpoint, $orderInput)->assertRedirect($orderUrl);
        $this->postJson($endpoint, [...$orderInput, 'buyer_email' => 'new@example.com', 'buyer_email_confirmation' => 'new@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('seat_ids');
        $this->assertSame(2, $order->tickets()->count());
        $ticket = $order->tickets()->whereHas('seat')->sole();
        $this->assertSame($seat->id, $ticket->seat->id);
        $this->get($orderUrl)->assertOk()->assertSee('data-festival-ticket-seat', false)->assertSee('Stalls');
        $this->get(route('public.festival-orders.pdf', [$account->slug, $order->access_token_encrypted]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('public.festival-tickets.qr', [$account->slug, $order->access_token_encrypted, $ticket->code]))
            ->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->getJson($map)->assertOk()->assertJsonPath('seats.0.available', false)->assertJsonPath('hold', null);
    }

    public function test_assigned_only_checkout_remains_available_in_both_templates_and_survives_google_prefill(): void
    {
        [$account, $edition] = $this->festival();
        $account->update(['allowed_festival_landing_templates' => ['velvet_night']]);
        $seat = $this->assignedSeat($edition);
        $route = [$account->slug, $edition->slug];

        foreach (['general', 'velvet_night'] as $template) {
            $edition->update(['landing_template' => $template, 'assigned_seating_enabled' => false]);
            $this->assertFalse($edition->admissionTypes()->availableForSale()->exists());
            $this->get(route('public.festivals.show', $route))
                ->assertOk()->assertDontSee('data-festival-seat-picker', false)->assertDontSee(__('app.buy_tickets'));

            $edition->update(['assigned_seating_enabled' => true]);
            $this->assertTrue($edition->admissionTypes()->availableForSale()->exists());
            $this->get(route('public.festivals.show', $route))
                ->assertOk()->assertSee('data-festival-seat-picker', false)->assertSee(__('app.buy_tickets'));
        }

        IntegrationSetting::query()->create([
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
            'provider' => 'google_oauth',
            'category' => IntegrationCategory::Authentication->value,
            'is_enabled' => true,
            'credentials' => ['client_id' => 'seating-prefill-client', 'client_secret' => 'seating-prefill-secret'],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'prefill-token']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'seating-prefill-subject',
                'name' => 'Seat Buyer',
                'email' => 'seating@example.com',
                'email_verified' => true,
            ]),
        ]);

        $this->keepBrowserSession($this->get(route('public.festivals.show', $route))->assertOk());
        $hold = $this->postJson(route('public.festivals.admission.seat-hold', $route), ['seat_ids' => [$seat->id]])
            ->assertOk()->json('hold');
        $google = $this->post(route('public.festivals.admission.google', $route))->assertRedirect();
        parse_str(parse_url($google->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);
        $this->get(route('public.festival-admission.google.callback', ['state' => $query['state'], 'code' => 'one-use-code']))
            ->assertRedirect(route('public.festivals.show', $route))->assertSessionHasInput('buyer_email', 'seating@example.com');
        $this->getJson(route('public.festivals.admission.seats', $route))
            ->assertOk()->assertJsonPath('hold.checkout_id', $hold['checkout_id'])->assertJsonPath('hold.seat_ids', [$seat->id]);
    }

    private function keepBrowserSession(TestResponse $response): void
    {
        $cookieName = config('session.cookie');
        $cookie = $response->getCookie($cookieName);
        $this->assertNotNull($cookie);
        $this->withCredentials()->withCookie($cookieName, $cookie->getValue());
    }

    private function assignedSeat(FestivalEdition $edition): FestivalSeat
    {
        $edition->update([
            'assigned_seating_enabled' => true,
            'seating_floors' => [['id' => 'main', 'label' => 'Main floor', 'width' => 1000, 'height' => 700, 'stage' => 'Main stage']],
        ]);
        $type = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $edition->account_id, 'inventory' => 1, 'price_cents' => 0, 'is_assigned_seating' => true,
        ]);

        return $edition->seats()->create([
            'account_id' => $edition->account_id, 'festival_admission_type_id' => $type->id,
            'floor_key' => 'main', 'section' => 'Stalls', 'group_kind' => 'row', 'group_label' => 'A',
            'seat_number' => '12', 'x' => 4200, 'y' => 3800,
        ]);
    }

    /** @return array{Account, FestivalEdition} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id, 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(6),
        ]);

        return [$account, $edition];
    }
}
