<?php

namespace Tests\Feature;

use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Models\TelegramAlert;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramBroadcastTarget;
use App\Models\TelegramMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FoundersAnnouncementTest extends TestCase
{
    use DatabaseTransactions;

    private const ChatId = '-5208558952';

    private const Message = 'Тестове повідомлення для Ladna Founders.';

    private const SourceRef = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        putenv('LADNA_FOUNDERS_ANNOUNCEMENT_ORIGIN=codex_skill');
        putenv('LADNA_FOUNDERS_ANNOUNCEMENT_PLATFORM_USER_ID');
    }

    protected function tearDown(): void
    {
        putenv('LADNA_FOUNDERS_ANNOUNCEMENT_ORIGIN');
        putenv('LADNA_FOUNDERS_ANNOUNCEMENT_PLATFORM_USER_ID');

        parent::tearDown();
    }

    public function test_configuration_requires_live_verification_and_matching_preview_hash(): void
    {
        $this->fakeTelegram();
        TelegramBotInstallation::factory()->platformOwner()->create();

        $this->assertSame(0, Artisan::call('telegram:configure-ladna-founders', [
            '--chat-id' => self::ChatId,
            '--expected-title' => 'Ladna Founders',
            '--json' => true,
        ]));
        $preview = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($preview['target']['configured']);
        $this->assertSame('Ladna Founders', $preview['target']['title']);
        $this->assertSame('group', $preview['target']['type']);
        $this->assertStringNotContainsString(self::ChatId, Artisan::output());
        $this->assertDatabaseCount((new TelegramBroadcastTarget)->getTable(), 0);

        $this->assertSame(0, Artisan::call('telegram:configure-ladna-founders', [
            '--chat-id' => self::ChatId,
            '--expected-title' => 'Ladna Founders',
            '--expected-target-hash' => $preview['target_hash'],
            '--execute' => true,
            '--json' => true,
        ]));

        $target = TelegramBroadcastTarget::query()->firstOrFail();

        $this->assertSame(self::ChatId, $target->telegram_chat_id);
        $this->assertSame('Ladna Founders', $target->title);
        $this->assertSame('group', $target->chat_type);
        $this->assertTrue($target->is_enabled);
        $this->assertNotNull($target->verified_at);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
    }

    public function test_configuration_rejects_a_title_mismatch(): void
    {
        $this->fakeTelegram(title: 'Different Group');
        TelegramBotInstallation::factory()->platformOwner()->create();

        $this->artisan('telegram:configure-ladna-founders', [
            '--chat-id' => self::ChatId,
            '--expected-title' => 'Ladna Founders',
            '--json' => true,
        ])->assertFailed();

        $this->assertDatabaseCount((new TelegramBroadcastTarget)->getTable(), 0);
    }

    public function test_preview_is_read_only_and_execute_sends_once_with_accountless_audit(): void
    {
        $this->fakeTelegram();
        $installation = TelegramBotInstallation::factory()->platformOwner()->create();
        $target = $this->target($installation);

        $this->assertSame(0, Artisan::call('telegram:announce-ladna-founders', $this->announcementOptions()));
        $preview = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('preview', $preview['mode']);
        $this->assertSame('Ladna Founders', $preview['target']['title']);
        $this->assertSame(self::Message, $preview['message']);
        $this->assertStringNotContainsString(self::ChatId, Artisan::output());
        $this->assertDatabaseCount((new TelegramAlert)->getTable(), 0);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));

        $options = $this->announcementOptions([
            '--execute' => true,
            '--expected-target-hash' => $preview['target_hash'],
        ]);

        $this->assertSame(0, Artisan::call('telegram:announce-ladna-founders', $options));
        $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('execute', $result['mode']);
        $this->assertSame(1, $result['statuses']['sent']);
        $this->assertSame(0, $result['statuses']['failed']);

        $alert = TelegramAlert::query()->firstOrFail();
        $this->assertNull($alert->account_id);
        $this->assertNull($alert->telegram_chat_authorization_id);
        $this->assertSame($target->id, $alert->telegram_broadcast_target_id);
        $this->assertSame(TelegramAlertType::FoundersAnnouncement, $alert->type);
        $this->assertSame(TelegramAlertRecipientKind::FoundersGroup, $alert->recipient_kind);
        $this->assertSame(TelegramAlertStatus::Sent, $alert->status);
        $this->assertSame('901', $alert->telegram_message_id);

        $this->assertDatabaseHas((new TelegramMessage)->getTable(), [
            'account_id' => null,
            'telegram_chat_authorization_id' => null,
            'telegram_chat_id' => self::ChatId,
            'telegram_message_id' => '901',
            'message_type' => 'founders_announcement',
            'text' => self::Message,
        ]);

        $this->artisan('telegram:announce-ladna-founders', $options)->assertSuccessful();

        $this->assertSame(1, TelegramAlert::query()->count());
        $this->assertSame(1, TelegramMessage::query()->where('message_type', 'founders_announcement')->count());
        $sendRequests = collect(Http::recorded())
            ->filter(fn (array $record): bool => str_ends_with($record[0]->url(), '/sendMessage'));
        $this->assertCount(1, $sendRequests);
        $this->assertSame(self::ChatId, (string) $sendRequests->first()[0]['chat_id']);
        $this->assertSame(self::Message, $sendRequests->first()[0]['text']);
    }

    public function test_execute_aborts_when_target_changed_after_preview(): void
    {
        $this->fakeTelegram();
        $installation = TelegramBotInstallation::factory()->platformOwner()->create();
        $target = $this->target($installation);

        Artisan::call('telegram:announce-ladna-founders', $this->announcementOptions());
        $preview = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $target->update(['title' => 'Renamed Founders']);
        $this->fakeTelegram(title: 'Renamed Founders');

        $this->artisan('telegram:announce-ladna-founders', $this->announcementOptions([
            '--execute' => true,
            '--expected-target-hash' => $preview['target_hash'],
        ]))->assertFailed();

        $this->assertDatabaseCount((new TelegramAlert)->getTable(), 0);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
    }

    public function test_disabled_target_blocks_preview_before_http_work(): void
    {
        Http::preventStrayRequests();
        $installation = TelegramBotInstallation::factory()->platformOwner()->create();
        $this->target($installation, ['is_enabled' => false]);

        $this->artisan('telegram:announce-ladna-founders', $this->announcementOptions())
            ->assertFailed();

        $this->assertDatabaseCount((new TelegramAlert)->getTable(), 0);
        Http::assertNothingSent();
    }

    public function test_group_webhook_updates_do_not_enter_private_authorization_flow(): void
    {
        Http::preventStrayRequests();
        $installation = TelegramBotInstallation::factory()->platformOwner()->create();
        $messageCount = TelegramMessage::query()->count();
        $authorizationCount = $installation->chatAuthorizations()->count();

        $this->postJson(
            route('api.v1.telegram.webhooks.handle', $installation->webhookKey()),
            [
                'update_id' => 7001,
                'message' => [
                    'message_id' => 80,
                    'chat' => [
                        'id' => (int) self::ChatId,
                        'type' => 'group',
                        'title' => 'Ladna Founders',
                    ],
                    'from' => ['id' => 123],
                    'new_chat_members' => [['id' => 456, 'is_bot' => true]],
                ],
            ],
            ['X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret()],
        )->assertNoContent();

        $this->assertDatabaseHas('telegram_updates', [
            'telegram_bot_installation_id' => $installation->id,
            'update_id' => 7001,
        ]);
        $this->assertSame($messageCount, TelegramMessage::query()->count());
        $this->assertSame($authorizationCount, $installation->chatAuthorizations()->count());
        Http::assertNothingSent();
    }

    public function test_http_route_cannot_invoke_founders_announcement(): void
    {
        Http::preventStrayRequests();

        Route::get('/_test/founders-announcement-guard', function (): array {
            return [
                'exit_code' => Artisan::call(
                    'telegram:announce-ladna-founders',
                    $this->announcementOptions(),
                ),
            ];
        });

        $this->getJson('/_test/founders-announcement-guard')
            ->assertOk()
            ->assertExactJson(['exit_code' => 1]);

        $this->assertDatabaseCount((new TelegramAlert)->getTable(), 0);
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function announcementOptions(array $overrides = []): array
    {
        return [
            '--message-base64' => base64_encode(self::Message),
            '--source-ref' => self::SourceRef,
            '--json' => true,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function target(
        TelegramBotInstallation $installation,
        array $overrides = [],
    ): TelegramBroadcastTarget {
        return TelegramBroadcastTarget::factory()
            ->for($installation, 'installation')
            ->create([
                'telegram_chat_id' => self::ChatId,
                'title' => 'Ladna Founders',
                'chat_type' => 'group',
                'is_enabled' => true,
                'verified_at' => now(),
                ...$overrides,
            ]);
    }

    private function fakeTelegram(string $title = 'Ladna Founders'): void
    {
        Http::fake([
            'api.telegram.org/*/getChatMember*' => Http::response([
                'ok' => true,
                'result' => ['status' => 'member'],
            ]),
            'api.telegram.org/*/getChat*' => Http::response([
                'ok' => true,
                'result' => [
                    'id' => (int) self::ChatId,
                    'type' => 'group',
                    'title' => $title,
                ],
            ]),
            'api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 777, 'is_bot' => true],
            ]),
            'api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 901],
            ]),
        ]);
    }
}
