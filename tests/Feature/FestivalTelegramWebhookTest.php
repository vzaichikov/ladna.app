<?php

namespace Tests\Feature;

use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\TelegramBotInstallation;
use App\Support\Festivals\FestivalTelegramIdentityLinker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FestivalTelegramWebhookTest extends TestCase
{
    use DatabaseTransactions;

    public function test_private_own_contact_creates_role_neutral_series_authorization_and_registrant(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => true, 'result' => ['message_id' => 55101]])
            ->push(['ok' => true, 'result' => ['message_id' => 55102]])]);
        [$account, $series, $installation] = $this->festival();
        $payload = $this->messageUpdate(5101, 7201001, [
            'contact' => [
                'user_id' => 7201001,
                'phone_number' => '+380501111001',
            ],
        ]);

        $this->postUpdate($installation, $payload)->assertNoContent();
        $this->postUpdate($installation, $payload)->assertNoContent();

        $registrant = FestivalPortalUser::query()->whereBelongsTo($account)->sole();
        $authorization = $installation->chatAuthorizations()->sole();
        $this->assertSame('registrant', $registrant->role->value);
        $this->assertSame('7201001', $registrant->telegram_user_id);
        $this->assertSame('7201001', $authorization->telegram_chat_id);
        $this->assertSame(TelegramChatAuthorizationStatus::Authorized, $authorization->status);
        $this->assertDatabaseHas('telegram_festival_portal_links', [
            'account_id' => $account->id,
            'telegram_chat_authorization_id' => $authorization->id,
            'festival_portal_user_id' => $registrant->id,
        ]);
        $this->assertSame(1, $installation->updates()->count());
        $this->assertSame(3, $installation->messages()->count());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '7201001'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '7201001'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.web_app.url') === route(
                'public.festival-telegram.show',
                [$account->slug, $series->slug],
            ));
    }

    public function test_forwarded_contact_and_non_private_chat_cannot_authorize(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 55102],
        ])]);
        [$account, , $installation] = $this->festival();

        $this->postUpdate($installation, $this->messageUpdate(5102, 7202001, [
            'contact' => [
                'user_id' => 9999999,
                'phone_number' => '+380501112001',
            ],
        ]))->assertNoContent();
        $this->postUpdate($installation, $this->messageUpdate(5103, 7202002, [
            'chat' => ['id' => -10012345, 'type' => 'group'],
            'contact' => [
                'user_id' => 7202002,
                'phone_number' => '+380501112002',
            ],
        ]))->assertNoContent();

        $this->assertSame(0, FestivalPortalUser::query()->whereBelongsTo($account)->count());
        $this->assertSame(0, $installation->chatAuthorizations()->count());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains(
            (string) $request['text'],
            __('app.festival_telegram_contact_must_be_own', locale: 'en'),
        ) && data_get($request->data(), 'reply_markup.keyboard.0.0.request_contact') === true);
    }

    public function test_authorized_command_removes_the_contact_keyboard_and_keeps_the_mini_app_action(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => true, 'result' => ['message_id' => 55103]])
            ->push(['ok' => true, 'result' => ['message_id' => 55104]])]);
        [$account, $series, $installation] = $this->festival();
        $linked = app(FestivalTelegramIdentityLinker::class)->authorizeRegistrant(
            $series,
            $installation,
            '7202501',
            '7202501',
            '+380501112501',
            ['first_name' => 'Authorized'],
        );

        $this->postUpdate($installation, $this->messageUpdate(5105, 7202501, [
            'text' => '/start',
        ]))->assertNoContent();

        $this->assertSame(1, $installation->chatAuthorizations()->count());
        $this->assertSame(3, $installation->messages()->count());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '7202501'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '7202501'
            && $request['text'] === __('app.festival_telegram_open_app', locale: $linked['registrant']->locale)
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.web_app.url') === route(
                'public.festival-telegram.show',
                [$account->slug, $series->slug],
            ));
    }

    public function test_bot_block_membership_update_revokes_the_exact_series_authorization(): void
    {
        [$account, $series, $installation] = $this->festival();
        $linked = app(FestivalTelegramIdentityLinker::class)->authorizeRegistrant(
            $series,
            $installation,
            '7203001',
            '7203001',
            '+380501113001',
            ['first_name' => 'Blocked'],
        );

        $this->postUpdate($installation, [
            'update_id' => 5104,
            'my_chat_member' => [
                'chat' => ['id' => 7203001, 'type' => 'private'],
                'from' => ['id' => 7203001],
                'new_chat_member' => ['status' => 'kicked'],
            ],
        ])->assertNoContent();

        $this->assertSame(TelegramChatAuthorizationStatus::Revoked, $linked['authorization']->refresh()->status);
        $this->assertNotNull($linked['authorization']->revoked_at);
        $this->assertSame(0, FestivalPortalUser::query()->whereBelongsTo($account)->where('is_active', false)->count());
        Http::assertNothingSent();
    }

    /** @return array{Account, FestivalSeries, TelegramBotInstallation} */
    private function festival(): array
    {
        $account = Account::factory()->create([
            'enable_festivals' => true,
            'default_language' => 'en',
            'country_code' => 'UA',
        ]);
        $series = FestivalSeries::factory()->for($account)->create(['is_active' => true]);
        $installation = TelegramBotInstallation::factory()->for($account)->create([
            'scope_type' => 'festival_series',
            'scope_id' => $series->id,
            'profile' => TelegramBotProfile::Festival,
            'is_enabled' => true,
        ]);

        return [$account, $series, $installation];
    }

    /** @param array<string, mixed> $payload */
    private function postUpdate(TelegramBotInstallation $installation, array $payload): TestResponse
    {
        return $this->postJson(
            route('api.v1.telegram.webhooks.handle', $installation->webhookKey()),
            $payload,
            ['X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret()],
        );
    }

    /**
     * @param  array<string, mixed>  $messageOverrides
     * @return array<string, mixed>
     */
    private function messageUpdate(int $updateId, int $telegramUserId, array $messageOverrides): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'from' => [
                    'id' => $telegramUserId,
                    'first_name' => 'Telegram',
                    'last_name' => 'User',
                    'username' => 'festival_user',
                    'language_code' => 'en',
                ],
                ...$messageOverrides,
            ],
        ];
    }
}
