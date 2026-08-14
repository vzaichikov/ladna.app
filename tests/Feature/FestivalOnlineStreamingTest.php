<?php

namespace Tests\Feature;

use App\Actions\Festivals\CreateFestivalTicketOrder;
use App\Actions\Festivals\FestivalTicketIssuer;
use App\Enums\AccountStatus;
use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalStreamOverride;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FestivalTicketStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalOnlineStream;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\FestivalStreamEntitlement;
use App\Models\FestivalTicketOrder;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\Festivals\FestivalMediaMtxGateway;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Festivals\FestivalStreamAccessService;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class FestivalOnlineStreamingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        config([
            'services.festival_stream.api_url' => 'http://127.0.0.1:9998',
            'services.festival_stream.public_url' => 'https://stream.ladna.test',
            'services.festival_stream.obs_server' => 'rtmp://100.64.0.10:1935',
            'services.festival_stream.hls_origin_url' => 'http://127.0.0.1:8888',
            'services.festival_stream.ip_hmac_key' => 'test-ip-hmac-key',
            'services.festival_stream.internal_secret' => 'test-internal-secret',
        ]);
    }

    public function test_stream_configuration_is_created_disabled_and_online_types_require_explicit_enablement(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $streamUrl = route('dashboard.accounts.festivals.online-stream.update', [$account, $edition]);

        $this->actingAs($owner)->put($streamUrl, $this->streamPayload(['is_enabled' => 1]))
            ->assertSessionHasNoErrors();
        $stream = $edition->onlineStream()->firstOrFail();
        $this->assertFalse($stream->is_enabled);

        $createTypeUrl = route('dashboard.accounts.festivals.admission-types.store', [$account, $edition]);
        $this->actingAs($owner)->from($createTypeUrl)->post($createTypeUrl, $this->admissionPayload())
            ->assertSessionHasErrors('delivery_mode');

        $this->actingAs($owner)->put($streamUrl, $this->streamPayload(['is_enabled' => 1]))
            ->assertSessionHasNoErrors();
        $this->assertTrue($stream->refresh()->is_enabled);
        $this->actingAs($owner)->post($createTypeUrl, $this->admissionPayload())
            ->assertSessionHasNoErrors();

        $type = $edition->admissionTypes()->where('delivery_mode', FestivalAdmissionDeliveryMode::OnlineStream->value)->sole();
        $this->assertSame($stream->id, $type->festival_online_stream_id);
        $this->assertSame(1, $type->max_per_order);
    }

    public function test_stream_cannot_be_enabled_until_the_dedicated_infrastructure_is_configured(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $streamUrl = route('dashboard.accounts.festivals.online-stream.update', [$account, $edition]);

        $this->actingAs($owner)->put($streamUrl, $this->streamPayload())
            ->assertSessionHasNoErrors();
        config(['services.festival_stream.api_url' => '']);
        $this->actingAs($owner)->from($streamUrl)->put($streamUrl, $this->streamPayload(['is_enabled' => 1]))
            ->assertSessionHasErrors('is_enabled');

        $this->assertFalse($edition->onlineStream()->firstOrFail()->is_enabled);
    }

    public function test_disabled_or_missing_stream_hides_online_products_and_rejects_direct_checkout(): void
    {
        [$account, $edition] = $this->festival();
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        $withoutStream = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'delivery_mode' => FestivalAdmissionDeliveryMode::OnlineStream,
            'festival_online_stream_id' => null,
        ]);
        $this->assertFalse($edition->admissionTypes()->availableForSale()->whereKey($withoutStream)->exists());

        $stream = FestivalOnlineStream::factory()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->online($stream)->create();
        $this->assertFalse($edition->admissionTypes()->availableForSale()->whereKey($type)->exists());

        $this->expectException(ValidationException::class);
        $this->createOrderAction($account)->execute($edition, $this->orderInput($type), $guest);
    }

    public function test_online_checkout_requires_one_guest_one_item_quantity_one_and_blocks_duplicates(): void
    {
        [$account, $edition] = $this->festival();
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->online($stream)->create();
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        $registrant = FestivalPortalUser::factory()->for($account)->create();
        $create = $this->createOrderAction($account);

        try {
            $create->execute($edition, $this->orderInput($type, 2), $guest);
            $this->fail('Online quantity two was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        try {
            $create->execute($edition, $this->orderInput($type), $registrant);
            $this->fail('A Registrant was accepted as the ticket cabinet owner.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $order = $create->execute($edition, $this->orderInput($type), $guest);
        $this->assertSame($guest->id, $order->festival_portal_user_id);
        $this->expectException(ValidationException::class);
        $create->execute($edition, $this->orderInput($type), $guest);
    }

    public function test_online_ticket_issuance_and_late_conflict_callback_are_atomic(): void
    {
        [$account, $edition] = $this->festival();
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->online($stream)->create(['inventory' => 10]);
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        [$paidOrder, $entitlement] = $this->issuedOnlineOrder($stream, $type, $guest);

        $this->assertSame($guest->id, $entitlement->festival_portal_user_id);
        $this->assertSame($paidOrder->tickets()->sole()->id, $entitlement->festival_ticket_id);

        $late = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_portal_user_id' => $guest->id,
            'provider' => 'monopay',
            'amount_cents' => $type->price_cents,
            'expires_at' => now()->subMinute(),
        ]);
        $this->addOrderItem($late, $type);
        app(FestivalPaymentService::class)->completeOrder($late, new PaymentCallbackResult(
            orderId: $late->order_id,
            status: PaymentCallbackStatus::Paid,
            amountCents: $late->amount_cents,
            currency: $late->currency,
        ));

        $this->assertSame(FestivalTicketOrderStatus::PaidRequiresRefund, $late->refresh()->status);
        $this->assertSame('online_access_conflict', $late->failure_reason);
        $this->assertSame(0, $late->tickets()->count());
    }

    public function test_refund_required_late_payment_does_not_invalidate_the_current_pending_purchase(): void
    {
        [$account, $edition] = $this->festival();
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->online($stream)->create(['inventory' => 10]);
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        $late = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_portal_user_id' => $guest->id,
            'provider' => 'monopay',
            'amount_cents' => $type->price_cents,
            'expires_at' => now()->subMinute(),
        ]);
        $this->addOrderItem($late, $type);
        $current = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_portal_user_id' => $guest->id,
            'provider' => 'monopay',
            'amount_cents' => $type->price_cents,
            'expires_at' => now()->addMinutes(20),
        ]);
        $this->addOrderItem($current, $type);
        $callback = fn (FestivalTicketOrder $order): PaymentCallbackResult => new PaymentCallbackResult(
            orderId: $order->order_id,
            status: PaymentCallbackStatus::Paid,
            amountCents: $order->amount_cents,
            currency: $order->currency,
        );

        app(FestivalPaymentService::class)->completeOrder($late, $callback($late));
        $this->assertSame(FestivalTicketOrderStatus::PaidRequiresRefund, $late->refresh()->status);
        app(FestivalPaymentService::class)->completeOrder($current, $callback($current));

        $this->assertSame(FestivalTicketOrderStatus::Paid, $current->refresh()->status);
        $this->assertNotNull($current->tickets()->sole()->streamEntitlement);
    }

    public function test_staff_cannot_disable_stream_with_active_online_orders_but_can_force_playback_closed(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->online($stream)->create();
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        $this->createOrderAction($account)->execute($edition, $this->orderInput($type), $guest);
        $url = route('dashboard.accounts.festivals.online-stream.update', [$account, $edition]);

        $this->actingAs($owner)->from($url)->put($url, $this->streamPayload(['is_enabled' => 0]))
            ->assertSessionHasErrors('is_enabled');
        $this->assertTrue($stream->refresh()->is_enabled);

        $this->actingAs($owner)->put($url, $this->streamPayload([
            'is_enabled' => 1,
            'playback_override' => FestivalStreamOverride::Closed->value,
        ]))->assertSessionHasNoErrors();
        $this->assertSame(FestivalStreamOverride::Closed, $stream->refresh()->playback_override);
    }

    public function test_staff_must_close_online_ticket_sales_before_disabling_streaming(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->online($stream)->create();
        $url = route('dashboard.accounts.festivals.online-stream.update', [$account, $edition]);

        $this->actingAs($owner)->from($url)->put($url, $this->streamPayload(['is_enabled' => 0]))
            ->assertSessionHasErrors('is_enabled');
        $type->update(['is_active' => false]);
        $this->actingAs($owner)->put($url, $this->streamPayload(['is_enabled' => 0]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($stream->refresh()->is_enabled);
    }

    public function test_refund_revokes_entitlement_and_allows_a_legitimate_repurchase(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->online($stream)->create();
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        [$order, $entitlement] = $this->issuedOnlineOrder($stream, $type, $guest);
        $ticket = $entitlement->ticket;

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.ticket-orders.refund', [$account, $edition, $order]), [
            'reason' => 'External gateway refund completed.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(FestivalTicketOrderStatus::Refunded, $order->refresh()->status);
        $this->assertSame(FestivalTicketStatus::Refunded, $ticket->refresh()->status);
        $this->assertDatabaseMissing('festival_stream_entitlements', ['id' => $entitlement->id]);
        $replacement = $this->createOrderAction($account)->execute($edition, $this->orderInput($type), $guest);
        $this->assertSame(FestivalTicketOrderStatus::Pending, $replacement->status);
    }

    public function test_voided_online_ticket_revokes_entitlement_and_allows_a_legitimate_repurchase(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->online($stream)->create();
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        [, $entitlement] = $this->issuedOnlineOrder($stream, $type, $guest);
        $ticket = $entitlement->ticket;

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.tickets.void', [$account, $edition, $ticket]), [
            'reason' => 'Access invalidated by the organizer.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(FestivalTicketStatus::Voided, $ticket->refresh()->status);
        $this->assertDatabaseMissing('festival_stream_entitlements', ['id' => $entitlement->id]);
        $replacement = $this->createOrderAction($account)->execute($edition, $this->orderInput($type), $guest);
        $this->assertSame(FestivalTicketOrderStatus::Pending, $replacement->status);
    }

    public function test_three_ip_leases_are_normalized_idempotent_expiring_and_never_store_raw_ips(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->online($stream)->create();
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        [, $entitlement] = $this->issuedOnlineOrder($stream, $type, $guest);
        $access = app(FestivalStreamAccessService::class);

        $access->acquireLease($entitlement, $guest, '203.0.113.10');
        $access->acquireLease($entitlement, $guest, '::ffff:203.0.113.10');
        $access->acquireLease($entitlement, $guest, '2001:db8::1');
        $access->acquireLease($entitlement, $guest, '198.51.100.20');
        $this->assertSame(3, $entitlement->leases()->count());
        $this->assertFalse(DB::table('festival_stream_ip_leases')->pluck('ip_hash')->contains('203.0.113.10'));

        try {
            $access->acquireLease($entitlement, $guest, '192.0.2.30');
            $this->fail('A fourth active public IP was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stream', $exception->errors());
        }

        $entitlement->leases()->oldest('id')->firstOrFail()->update(['expires_at' => now()->subSecond()]);
        $access->acquireLease($entitlement, $guest, '192.0.2.30');
        $this->assertSame(3, $entitlement->leases()->count());

        $this->actingAs($owner)->delete(route('dashboard.accounts.festivals.online-stream.reset-leases', [$account, $edition]))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $entitlement->leases()->count());
    }

    public function test_viewer_tokens_reject_forgery_wrong_ip_path_closed_window_and_disabled_stream(): void
    {
        [$account, $edition] = $this->festival();
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->online($stream)->create();
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        [, $entitlement] = $this->issuedOnlineOrder($stream, $type, $guest);
        $access = app(FestivalStreamAccessService::class);
        $access->acquireLease($entitlement, $guest, '203.0.113.40');
        $cookie = $access->viewerCookie($entitlement, '203.0.113.40');
        $cookieName = $access->viewerCookieName($stream->path);
        $this->assertStringStartsWith('ladna_festival_stream_', $cookieName);
        $this->assertTrue($access->authorizeViewerCookie($cookie, $stream->path, '203.0.113.40')->is($entitlement));
        $gatewayHeaders = [
            'X-Festival-Stream-Secret' => 'test-internal-secret',
            'X-Festival-Stream-Path' => $stream->path,
            'X-Original-Client-IP' => '203.0.113.40',
        ];
        $this->withCookie($cookieName, $cookie)
            ->withHeaders($gatewayHeaders)
            ->get(route('internal.festival-stream.authorize'))
            ->assertNoContent();
        $this->withCookie($cookieName, $cookie.'forged')
            ->withHeaders($gatewayHeaders)
            ->get(route('internal.festival-stream.authorize'))
            ->assertForbidden();
        $this->get(route('festival.stream.bootstrap', ['token' => 'forged']))->assertForbidden();

        foreach ([
            fn () => $access->authorizeViewerCookie($cookie.'forged', $stream->path, '203.0.113.40'),
            fn () => $access->authorizeViewerCookie($cookie, $stream->path, '203.0.113.41'),
            fn () => $access->authorizeViewerCookie($cookie, 'wrong-path', '203.0.113.40'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Invalid viewer credentials were accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        config(['services.festival_stream.session_seconds' => 1]);
        $expiredCookie = $access->viewerCookie($entitlement, '203.0.113.40');
        $this->travel(2)->seconds();
        try {
            $access->authorizeViewerCookie($expiredCookie, $stream->path, '203.0.113.40');
            $this->fail('An expired viewer cookie was accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->travelBack();
        }

        $stream->update(['playback_override' => FestivalStreamOverride::Closed]);
        $this->expectException(ValidationException::class);
        $access->authorizeViewerCookie($cookie, $stream->path, '203.0.113.40');
    }

    public function test_valid_ip_bound_cookie_renders_the_stream_player_with_its_account_context(): void
    {
        [$account, $edition] = $this->festival();
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->online($stream)->create();
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        [, $entitlement] = $this->issuedOnlineOrder($stream, $type, $guest);
        $access = app(FestivalStreamAccessService::class);
        $access->acquireLease($entitlement, $guest, '203.0.113.40');
        $cookie = $access->viewerCookie($entitlement, '203.0.113.40');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
            ->withCookie($access->viewerCookieName($stream->path), $cookie)
            ->get(route('festival.stream.player', $stream->path))
            ->assertOk()
            ->assertViewIs('festivals.portal.stream-player')
            ->assertViewHas('account', fn (Account $viewAccount): bool => $viewAccount->is($account))
            ->assertViewHas('stream', fn (FestivalOnlineStream $viewStream): bool => $viewStream->is($stream))
            ->assertSee($edition->title)
            ->assertSee($account->name)
            ->assertSee('https://stream.ladna.test/hls/'.$stream->path.'/index.m3u8', false)
            ->assertSee('https://stream.ladna.test/festival-stream/heartbeat/'.$stream->path, false)
            ->assertDontSee('rel="manifest"', false)
            ->assertDontSee('data-app-update', false);
    }

    public function test_minted_viewer_access_is_revoked_with_account_capability_or_subscription(): void
    {
        [$account, $edition] = $this->festival();
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->online($stream)->create();
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        [, $entitlement] = $this->issuedOnlineOrder($stream, $type, $guest);
        $access = app(FestivalStreamAccessService::class);
        $access->acquireLease($entitlement, $guest, '203.0.113.40');
        $cookie = $access->viewerCookie($entitlement, '203.0.113.40');

        $account->update(['enable_festivals' => false]);
        $this->assertViewerAccessDenied($access, $cookie, $stream->path);

        $account->update(['enable_festivals' => true, 'status' => AccountStatus::Suspended]);
        $this->assertViewerAccessDenied($access, $cookie, $stream->path);

        $account->update(['status' => AccountStatus::Active]);
        AccountSubscription::factory()->for($account)->create(['status' => SubscriptionStatus::Expired]);
        $this->assertViewerAccessDenied($access, $cookie, $stream->path);
    }

    public function test_guest_watch_and_cabinet_are_exact_owner_and_account_scoped(): void
    {
        [$account, $edition] = $this->festival();
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->online($stream)->create(['name' => 'Private online access']);
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create();
        $otherGuest = FestivalPortalUser::factory()->guest()->for($account)->create();
        [$order, $entitlement] = $this->issuedOnlineOrder($stream, $type, $guest);
        $this->assertSame(1, $guest->ticketOrders()->count());
        $this->assertSame(1, $guest->ticketOrders()->whereBelongsTo($account)->count());

        $this->actingAs($guest, 'festival')->get(route('festival.portal.guest.dashboard', $account->slug))
            ->assertOk()
            ->assertSee($order->order_id)
            ->assertSee('Private online access');
        $this->actingAs($guest, 'festival')->get(route('festival.portal.guest.stream.watch', [$account->slug, $entitlement]))
            ->assertRedirectContains('https://stream.ladna.test/festival-stream/bootstrap?token=');
        $this->assertSame(1, $entitlement->leases()->count());
        $this->actingAs($guest, 'festival')->delete(route('festival.portal.guest.stream.release', [$account->slug, $entitlement]))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $entitlement->leases()->count());

        $this->actingAs($otherGuest, 'festival')->get(route('festival.portal.guest.dashboard', $account->slug))
            ->assertOk()
            ->assertDontSee($order->order_id)
            ->assertDontSee(route('festival.portal.guest.stream.watch', [$account->slug, $entitlement]));
        $this->actingAs($otherGuest, 'festival')->get(route('festival.portal.guest.stream.watch', [$account->slug, $entitlement]))
            ->assertNotFound();
    }

    public function test_publisher_auth_and_mediamtx_status_use_the_dedicated_service_contract(): void
    {
        [$account, $edition] = $this->festival();
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        config(['services.festival_stream.api_url' => 'http://127.0.0.1:9998']);
        Http::fake([
            'http://127.0.0.1:9998/v3/paths/get/*' => Http::response(['ready' => true, 'readers' => [['id' => 'one'], ['id' => 'two']]]),
        ]);

        $this->assertSame(['publisher_online' => true, 'readers' => 2], app(FestivalMediaMtxGateway::class)->status($stream));
        $payload = [
            'action' => 'publish',
            'protocol' => 'rtmp',
            'path' => $stream->path,
            'query' => 'token='.rawurlencode($stream->publisher_token_encrypted),
        ];
        $this->withHeader('X-Festival-Stream-Secret', 'test-internal-secret')
            ->postJson(route('internal.festival-stream.publisher-authorize'), $payload)
            ->assertNoContent();
        $this->withoutHeader('X-Festival-Stream-Secret')
            ->postJson(route('internal.festival-stream.publisher-authorize', ['key' => 'test-internal-secret']), $payload)
            ->assertUnauthorized();
        $this->withHeader('X-Festival-Stream-Secret', 'wrong-secret')
            ->postJson(route('internal.festival-stream.publisher-authorize'), $payload)
            ->assertUnauthorized();
        Http::assertSentCount(1);
    }

    private function assertViewerAccessDenied(FestivalStreamAccessService $access, string $cookie, string $path): void
    {
        try {
            $access->authorizeViewerCookie($cookie, $path, '203.0.113.40');
            $this->fail('Viewer access remained available after the account capability was revoked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stream', $exception->errors());
        }
    }

    /** @return array{Account, FestivalEdition, User} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $edition = FestivalEdition::factory()->published()->for(FestivalSeries::factory()->for($account))->create([
            'account_id' => $account->id,
            'timezone' => 'Europe/Kyiv',
        ]);

        return [$account, $edition, $owner];
    }

    private function createOrderAction(Account $account): CreateFestivalTicketOrder
    {
        $setting = new IntegrationSetting(['provider' => 'monopay', 'is_enabled' => true]);
        $setting->account_id = $account->id;
        $gateways = Mockery::mock(PaymentGatewayRegistry::class);
        $gateways->shouldReceive('availableSettingsFor')->andReturn(collect([$setting]));

        return new CreateFestivalTicketOrder($gateways);
    }

    /** @return array{FestivalTicketOrder, FestivalStreamEntitlement} */
    private function issuedOnlineOrder(FestivalOnlineStream $stream, FestivalAdmissionType $type, FestivalPortalUser $guest): array
    {
        $order = FestivalTicketOrder::factory()->create([
            'account_id' => $stream->account_id,
            'festival_edition_id' => $stream->festival_edition_id,
            'festival_portal_user_id' => $guest->id,
            'status' => FestivalTicketOrderStatus::Paid,
            'paid_at' => now(),
            'expires_at' => null,
            'amount_cents' => $type->price_cents,
        ]);
        $this->addOrderItem($order, $type);
        app(FestivalTicketIssuer::class)->execute($order);

        return [$order->refresh(), $order->tickets()->sole()->streamEntitlement()->firstOrFail()];
    }

    private function addOrderItem(FestivalTicketOrder $order, FestivalAdmissionType $type): void
    {
        $order->items()->create([
            'account_id' => $order->account_id,
            'festival_admission_type_id' => $type->id,
            'admission_name' => $type->name,
            'unit_price_cents' => $type->price_cents,
            'quantity' => 1,
            'total_cents' => $type->price_cents,
        ]);
    }

    /** @return array<string, mixed> */
    private function orderInput(FestivalAdmissionType $type, int $quantity = 1): array
    {
        return [
            'buyer_name' => 'Online Guest',
            'buyer_email' => 'online@example.test',
            'provider' => 'monopay',
            'items' => [['admission_type_id' => $type->id, 'quantity' => $quantity]],
            'terms' => true,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function streamPayload(array $overrides = []): array
    {
        return [
            'is_enabled' => 0,
            'opens_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'closes_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'playback_override' => FestivalStreamOverride::Automatic->value,
            'rotate_publisher_token' => 0,
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function admissionPayload(): array
    {
        return [
            'delivery_mode' => FestivalAdmissionDeliveryMode::OnlineStream->value,
            'name' => 'Online access',
            'description' => 'Live stream access',
            'inventory' => 100,
            'price' => '300.00',
            'max_per_order' => 10,
            'is_active' => 1,
        ];
    }
}
