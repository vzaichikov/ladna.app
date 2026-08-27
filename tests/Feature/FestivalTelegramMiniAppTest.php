<?php

namespace Tests\Feature;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalMedia;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\FestivalTicketOrder;
use App\Models\TelegramBotInstallation;
use App\Support\FestivalAuth\TelegramFestivalLoginTokenService;
use App\Support\Festivals\FestivalTelegramCheckoutHandoff;
use App\Support\Festivals\FestivalTelegramIdentityLinker;
use App\Support\Telegram\TelegramMiniAppInitDataValidator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FestivalTelegramMiniAppTest extends TestCase
{
    use DatabaseTransactions;

    public function test_init_data_requires_exact_bot_hmac_and_a_fresh_private_session(): void
    {
        [, , , $installation] = $this->festival();
        $valid = $this->initData($installation, '7001001');
        $result = app(TelegramMiniAppInitDataValidator::class)->validate($valid, $installation);

        $this->assertSame('7001001', $result['user']['id']);

        try {
            app(TelegramMiniAppInitDataValidator::class)->validate(str_replace('Telegram', 'Tampered', $valid), $installation);
            $this->fail('Expected tampered Telegram init data to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('init_data', $exception->errors());
        }

        $stale = $this->initData($installation, '7001001', now()->subMinutes(6)->timestamp);
        $this->expectException(ValidationException::class);
        app(TelegramMiniAppInitDataValidator::class)->validate($stale, $installation);
    }

    public function test_series_mini_app_shell_renders_public_data_without_exposing_the_bot_token(): void
    {
        [$account, $series, $edition, $installation] = $this->festival();
        FestivalMedia::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'kind' => 'image',
            'external_url' => 'https://cdn.example.test/festival-desktop.jpg',
            'alt_text' => 'Nearest Festival',
            'is_cover' => true,
        ]);
        FestivalMedia::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'kind' => 'image',
            'external_url' => 'https://cdn.example.test/festival-mobile.jpg',
            'alt_text' => 'Nearest Festival Mobile',
            'is_mobile_cover' => true,
        ]);
        $fartherEdition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'starts_at' => now()->addMonths(2),
            'ends_at' => now()->addMonths(2)->addHours(6),
        ]);
        FestivalMedia::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $fartherEdition->id,
            'kind' => 'image',
            'external_url' => 'https://cdn.example.test/farther-festival.jpg',
            'is_cover' => true,
        ]);
        $otherSeries = FestivalSeries::factory()->for($account)->create();
        $otherEdition = FestivalEdition::factory()->published()->for($otherSeries)->create([
            'account_id' => $account->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(6),
        ]);
        FestivalMedia::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $otherEdition->id,
            'kind' => 'image',
            'external_url' => 'https://cdn.example.test/other-series.jpg',
            'is_cover' => true,
        ]);

        $this->get(route('public.festival-telegram.show', [$account->slug, $series->slug]))
            ->assertOk()
            ->assertSee('data-festival-telegram-mini-app', false)
            ->assertSee('data-festival-telegram-hero-edition="'.$edition->id.'"', false)
            ->assertSee('https://cdn.example.test/festival-desktop.jpg', false)
            ->assertSee('https://cdn.example.test/festival-mobile.jpg', false)
            ->assertDontSee('https://cdn.example.test/farther-festival.jpg', false)
            ->assertDontSee('https://cdn.example.test/other-series.jpg', false)
            ->assertDontSee($series->summary)
            ->assertSee($edition->title)
            ->assertDontSee((string) $installation->tokenValue(), false);
    }

    public function test_bootstrap_is_series_scoped_and_releases_private_data_only_after_authorization(): void
    {
        [$account, $series, $edition, $installation] = $this->festival();
        $otherSeries = FestivalSeries::factory()->for($account)->create();
        $otherEdition = FestivalEdition::factory()->published()->for($otherSeries)->create([
            'account_id' => $account->id,
            'title' => 'Other Series Festival',
        ]);
        $initData = $this->initData($installation, '7002001');
        $route = route('public.festival-telegram.bootstrap', [$account->slug, $series->slug]);

        $this->postJson($route, ['init_data' => $initData])
            ->assertOk()
            ->assertJsonPath('authorized', false)
            ->assertJsonMissing(['registrant'])
            ->assertJsonFragment(['title' => $edition->title])
            ->assertJsonMissing(['title' => $otherEdition->title]);

        $linked = app(FestivalTelegramIdentityLinker::class)->authorizeRegistrant(
            $series,
            $installation,
            '7002001',
            '7002001',
            '+380501234501',
            ['first_name' => 'Telegram', 'last_name' => 'Registrant', 'language_code' => 'en'],
        );
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        FestivalScheduleSlot::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $stage->id,
            'festival_category_id' => $category->id,
            'type' => 'category_header',
            'published_at' => now(),
        ]);
        FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $linked['registrant']->id,
            'entry_name' => 'Series-only entry',
        ]);

        $this->postJson($route, ['init_data' => $initData])
            ->assertOk()
            ->assertJsonPath('authorized', true)
            ->assertJsonPath('identity.registrant_linked', true)
            ->assertJsonFragment(['name' => 'Series-only entry'])
            ->assertJsonFragment(['name' => $category->name])
            ->assertJsonMissing(['title' => $otherEdition->title]);
    }

    public function test_registrant_login_is_single_use_and_fails_after_revocation(): void
    {
        [$account, $series, , $installation] = $this->festival();
        $linked = app(FestivalTelegramIdentityLinker::class)->authorizeRegistrant(
            $series,
            $installation,
            '7003001',
            '7003001',
            '+380501234502',
            ['first_name' => 'One', 'last_name' => 'Use'],
        );
        $tokens = app(TelegramFestivalLoginTokenService::class);
        $url = $tokens->issueRegistrantUrl($series, $linked['authorization'], $linked['registrant'], 'dashboard');

        $this->get($url)->assertRedirect(route('festival.portal.dashboard', $account->slug));
        $this->assertAuthenticatedAs($linked['registrant'], 'festival');
        Auth::guard('festival')->logout();
        $this->get($url)->assertForbidden();

        $revokedUrl = $tokens->issueRegistrantUrl($series, $linked['authorization'], $linked['registrant'], 'dashboard');
        $linked['authorization']->forceFill([
            'status' => TelegramChatAuthorizationStatus::Revoked,
            'revoked_at' => now(),
        ])->save();

        $this->get($revokedUrl)->assertForbidden();
        $this->assertGuest('festival');
    }

    public function test_authenticated_checkout_links_the_exact_created_guest_while_anonymous_checkout_does_not(): void
    {
        [$account, $series, $edition, $installation] = $this->festival();
        $type = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'delivery_mode' => FestivalAdmissionDeliveryMode::Venue,
            'price_cents' => 0,
            'inventory' => 10,
        ]);
        $linked = app(FestivalTelegramIdentityLinker::class)->authorizeRegistrant(
            $series,
            $installation,
            '7004001',
            '7004001',
            '+380501234503',
            ['first_name' => 'Ticket', 'last_name' => 'Buyer'],
        );
        $handoffUrl = app(FestivalTelegramCheckoutHandoff::class)->issueUrl($series, $edition, $linked['authorization']);
        $this->get($handoffUrl)->assertRedirect(route('public.festivals.show', [$account->slug, $edition->slug]).'#festival-admission');

        $response = $this->post(route('public.festivals.admission.store', [$account->slug, $edition->slug]), $this->checkoutPayload($type, 'telegram-buyer@example.test', '+380501234599'));
        $response->assertSessionHasNoErrors();
        $order = FestivalTicketOrder::query()->whereBelongsTo($account)->sole();
        $guest = $order->portalUser;

        $response->assertRedirect(route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]));
        $this->assertSame('7004001', $guest->telegram_user_id);
        $this->assertDatabaseHas('telegram_festival_portal_links', [
            'telegram_chat_authorization_id' => $linked['authorization']->id,
            'festival_portal_user_id' => $guest->id,
        ]);

        [$otherAccount, , $otherEdition] = $this->festival();
        $anonymousType = FestivalAdmissionType::factory()->for($otherEdition)->create([
            'account_id' => $otherAccount->id,
            'delivery_mode' => FestivalAdmissionDeliveryMode::Venue,
            'price_cents' => 0,
            'inventory' => 10,
        ]);
        $this->post(
            route('public.festivals.admission.store', [$otherAccount->slug, $otherEdition->slug]),
            $this->checkoutPayload($anonymousType, 'anonymous@example.test', '+380501234598'),
        )->assertRedirect();
        $anonymousOrder = FestivalTicketOrder::query()->whereBelongsTo($otherAccount)->sole();

        $this->assertNull($anonymousOrder->portalUser->telegram_user_id);
        $this->assertFalse($anonymousOrder->portalUser->telegramFestivalLinks()->exists());
    }

    /** @return array{Account, FestivalSeries, FestivalEdition, TelegramBotInstallation} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addHours(6),
        ]);
        $installation = TelegramBotInstallation::factory()->for($account)->create([
            'scope_type' => 'festival_series',
            'scope_id' => $series->id,
            'profile' => TelegramBotProfile::Festival,
            'encrypted_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'is_enabled' => true,
        ]);

        return [$account, $series, $edition, $installation];
    }

    private function initData(TelegramBotInstallation $installation, string $telegramUserId, ?int $authDate = null): string
    {
        $parameters = [
            'auth_date' => (string) ($authDate ?? now()->timestamp),
            'query_id' => 'AAH-synthetic-query',
            'user' => json_encode([
                'id' => (int) $telegramUserId,
                'first_name' => 'Telegram',
                'last_name' => 'Tester',
                'username' => 'festival_tester',
                'language_code' => 'en',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
        ksort($parameters);
        $checkString = collect($parameters)->map(fn (string $value, string $key): string => $key.'='.$value)->implode("\n");
        $secretKey = hash_hmac('sha256', (string) $installation->tokenValue(), 'WebAppData', true);
        $parameters['hash'] = hash_hmac('sha256', $checkString, $secretKey);

        return http_build_query($parameters);
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(FestivalAdmissionType $type, string $email, string $phone): array
    {
        return [
            'buyer_name' => 'Telegram Guest',
            'buyer_email' => $email,
            'buyer_email_confirmation' => $email,
            'buyer_phone' => $phone,
            'items' => [$type->id => 1],
            'terms' => '1',
        ];
    }
}
