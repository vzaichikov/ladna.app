<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\AccountStatus;
use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\TelegramAlert;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Support\Telegram\Alerts\TelegramAlertSender;
use App\Support\Telegram\Announcements\QueueStudioOwnerAnnouncement;
use App\Support\Telegram\Announcements\StudioOwnerAnnouncementAudienceResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

class StudioOwnerAnnouncementTest extends TestCase
{
    use DatabaseTransactions;

    private const SourceRef = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const UkrainianMessage = 'У Ladna вже доступне оновлення.';

    private const EnglishMessage = 'A Ladna update is now available.';

    protected function setUp(): void
    {
        parent::setUp();

        putenv('LADNA_OWNER_ANNOUNCEMENT_ORIGIN=codex_skill');
        putenv('LADNA_OWNER_ANNOUNCEMENT_PLATFORM_USER_ID');
    }

    protected function tearDown(): void
    {
        putenv('LADNA_OWNER_ANNOUNCEMENT_ORIGIN');
        putenv('LADNA_OWNER_ANNOUNCEMENT_PLATFORM_USER_ID');

        parent::tearDown();
    }

    public function test_preview_includes_only_active_live_subscribed_studio_owners(): void
    {
        Http::preventStrayRequests();
        $installation = TelegramBotInstallation::factory()->platformOwner()->create();

        $ukAccount = Account::factory()->create(['name' => 'Kyiv Movement', 'default_language' => 'pl']);
        $ukOwner = $this->addOwner($ukAccount, '+380671110001', 'Anna Owner');
        $this->authorize($installation, $ukAccount, $ukOwner, 'uk-owner-chat');

        $enAccount = Account::factory()->create(['name' => 'Lviv Flow', 'default_language' => 'en']);
        $enOwner = $this->addOwner($enAccount, '+380671110002', 'Emma Owner');
        $this->authorize($installation, $enAccount, null, 'en-owner-chat', $enOwner->phone);

        $disabledAccount = Account::factory()->create(['enable_telegram_alerts' => false]);
        $disabledOwner = $this->addOwner($disabledAccount, '+380671110003');
        $this->authorize($installation, $disabledAccount, $disabledOwner, 'disabled-owner-chat');

        $staff = User::factory()->create(['phone' => '+380671110004']);
        $ukAccount->users()->attach($staff, ['role' => AccountRole::Manager->value]);
        $this->authorize($installation, $ukAccount, $staff, 'staff-chat');

        $suspendedAccount = Account::factory()->create(['status' => AccountStatus::Suspended->value]);
        $suspendedOwner = $this->addOwner($suspendedAccount, '+380671110005');
        $this->authorize($installation, $suspendedAccount, $suspendedOwner, 'suspended-owner-chat');

        $demoAccount = Account::factory()->demoReadonly()->create();
        $demoOwner = $this->addOwner($demoAccount, '+380671110006');
        $this->authorize($installation, $demoAccount, $demoOwner, 'demo-owner-chat');

        $this->assertSame(0, Artisan::call('telegram:announce-studio-owners', $this->commandOptions()));
        $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(2, $result['eligible_chats']);
        $this->assertSame(2, $result['eligible_owners']);
        $this->assertArrayNotHasKey('owners', $result);
        $this->assertArrayNotHasKey('owners_omitted', $result);
        $this->assertSame(['uk' => 1, 'en' => 1], $result['locales']);
        $this->assertSame(1, $result['excluded']['owners_with_alerts_disabled']);

        $this->assertDatabaseCount((new TelegramAlert)->getTable(), 0);
        Http::assertNothingSent();
    }

    public function test_execute_limits_the_owner_report_to_ten_distinct_memberships(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 100],
        ])]);
        $installation = TelegramBotInstallation::factory()->platformOwner()->create();

        foreach (range(1, 12) as $number) {
            $account = Account::factory()->create(['name' => sprintf('Studio %02d', $number)]);
            $owner = $this->addOwner(
                $account,
                sprintf('+38068%07d', $number),
                sprintf('Owner %02d', $number),
            );
            $this->authorize($installation, $account, $owner, 'owner-chat-'.$number);

            if ($number === 1) {
                $this->authorize($installation, $account, $owner, 'owner-second-chat-'.$number);
            }
        }

        $audienceHash = app(StudioOwnerAnnouncementAudienceResolver::class)
            ->resolve($installation)
            ->hash();

        $this->assertSame(0, Artisan::call('telegram:announce-studio-owners', $this->commandOptions([
            '--execute' => true,
            '--expected-audience-hash' => $audienceHash,
        ])));
        $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(10, $result['owners']);
        $this->assertSame('Owner 01', $result['owners'][0]['owner_name']);
        $this->assertSame('Studio 10', $result['owners'][9]['studio_name']);
        $this->assertSame(2, $result['owners_omitted']);
        $this->assertSame(['owner_name', 'studio_name', 'locale'], array_keys($result['owners'][0]));

        $this->assertSame(13, $result['statuses']['sent']);
        $this->assertSame(13, TelegramAlert::query()->where('status', TelegramAlertStatus::Sent->value)->count());
    }

    public function test_execute_sends_localized_owner_messages_and_is_idempotent(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => true, 'result' => ['message_id' => 101]])
            ->push(['ok' => true, 'result' => ['message_id' => 102]])]);

        $installation = TelegramBotInstallation::factory()->platformOwner()->create();
        $ukAccount = Account::factory()->create(['name' => 'Kyiv Motion', 'default_language' => 'uk']);
        $ukOwner = $this->addOwner($ukAccount, '+380672220001', 'Olena Owner');
        $ukAuthorization = $this->authorize($installation, $ukAccount, $ukOwner, 'uk-chat');
        $enAccount = Account::factory()->create(['name' => 'Odesa Balance', 'default_language' => 'en']);
        $enOwner = $this->addOwner($enAccount, '+380672220002', 'Emily Owner');
        $enAuthorization = $this->authorize($installation, $enAccount, null, 'en-chat', $enOwner->phone);

        $audienceHash = app(StudioOwnerAnnouncementAudienceResolver::class)
            ->resolve($installation)
            ->hash();
        $options = $this->commandOptions([
            '--execute' => true,
            '--expected-audience-hash' => $audienceHash,
        ]);

        $this->assertSame(0, Artisan::call('telegram:announce-studio-owners', $options));
        $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('execute', $result['mode']);
        $this->assertSame('codex_skill', $result['execution_origin']);
        $this->assertNull($result['platform_user_id']);
        $this->assertSame(2, $result['delivery']['sent']);
        $this->assertSame([
            ['owner_name' => 'Olena Owner', 'studio_name' => 'Kyiv Motion', 'locale' => 'uk'],
            ['owner_name' => 'Emily Owner', 'studio_name' => 'Odesa Balance', 'locale' => 'en'],
        ], $result['owners']);
        $this->assertSame(0, $result['owners_omitted']);
        $this->assertSame([
            'uk' => self::UkrainianMessage,
            'en' => self::EnglishMessage,
        ], $result['messages']);

        $this->assertSame(2, TelegramAlert::query()
            ->where('type', TelegramAlertType::OwnerAnnouncement->value)
            ->where('recipient_kind', TelegramAlertRecipientKind::StudioOwner->value)
            ->where('status', TelegramAlertStatus::Sent->value)
            ->count());
        $this->assertDatabaseHas((new TelegramAlert)->getTable(), [
            'telegram_chat_authorization_id' => $ukAuthorization->id,
            'telegram_chat_id' => 'uk-chat',
            'text' => self::UkrainianMessage,
        ]);
        $this->assertDatabaseHas((new TelegramAlert)->getTable(), [
            'telegram_chat_authorization_id' => $enAuthorization->id,
            'telegram_chat_id' => 'en-chat',
            'text' => self::EnglishMessage,
        ]);
        $this->assertSame(2, TelegramMessage::query()->where('message_type', 'owner_announcement')->count());
        $this->assertSame('codex_skill', TelegramAlert::query()->first()->payload['execution_origin']);
        $this->assertNull(TelegramAlert::query()->first()->payload['platform_user_id']);

        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === 'uk-chat'
            && $request['text'] === self::UkrainianMessage);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === 'en-chat'
            && $request['text'] === self::EnglishMessage);

        $this->artisan('telegram:announce-studio-owners', $options)->assertSuccessful();

        $this->assertSame(2, TelegramAlert::where('type', TelegramAlertType::OwnerAnnouncement->value)->count());
        Http::assertSentCount(2);

        $platformAdmin = User::factory()->platformAdmin()->create();
        $this->actingAs($platformAdmin)
            ->get(route('platform.telegram-support.index', ['tab' => 'alerts']))
            ->assertOk()
            ->assertSee($ukOwner->name)
            ->assertSee(self::UkrainianMessage);
    }

    public function test_execute_aborts_when_audience_changed_after_preview(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $installation = TelegramBotInstallation::factory()->platformOwner()->create();
        $firstAccount = Account::factory()->create();
        $firstOwner = $this->addOwner($firstAccount, '+380673330001');
        $this->authorize($installation, $firstAccount, $firstOwner, 'first-chat');
        $previewHash = app(StudioOwnerAnnouncementAudienceResolver::class)
            ->resolve($installation)
            ->hash();

        $secondAccount = Account::factory()->create();
        $secondOwner = $this->addOwner($secondAccount, '+380673330002');
        $this->authorize($installation, $secondAccount, $secondOwner, 'second-chat');

        $this->artisan('telegram:announce-studio-owners', $this->commandOptions([
            '--execute' => true,
            '--expected-audience-hash' => $previewHash,
        ]))->assertFailed();

        $this->assertDatabaseCount((new TelegramAlert)->getTable(), 0);
        Http::assertNothingSent();
    }

    public function test_linked_staff_authorization_with_owner_phone_aborts_the_broadcast(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $installation = TelegramBotInstallation::factory()->platformOwner()->create();
        $account = Account::factory()->create();
        $owner = $this->addOwner($account, '+380674440001');
        $staff = User::factory()->create(['phone' => $owner->phone]);
        $account->users()->attach($staff, ['role' => AccountRole::Manager->value]);
        $this->authorize($installation, $account, $staff, 'conflicting-chat', $owner->phone);

        $this->artisan('telegram:announce-studio-owners', $this->commandOptions())
            ->expectsOutputToContain('Audience integrity validation failed')
            ->assertFailed();

        $this->assertDatabaseCount((new TelegramAlert)->getTable(), 0);
        Http::assertNothingSent();
    }

    public function test_sender_revalidates_owner_membership_before_delivery(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $installation = TelegramBotInstallation::factory()->platformOwner()->create();
        $account = Account::factory()->create();
        $owner = $this->addOwner($account, '+380675550001');
        $this->authorize($installation, $account, $owner, 'stale-owner-chat');
        $audience = app(StudioOwnerAnnouncementAudienceResolver::class)->resolve($installation);
        $queue = app(QueueStudioOwnerAnnouncement::class);
        $messages = ['uk' => self::UkrainianMessage, 'en' => self::EnglishMessage];
        $campaignHash = $queue->campaignHash(self::SourceRef, $messages);
        $alerts = $queue->execute(
            installation: $installation,
            audience: $audience,
            messages: $messages,
            sourceRef: self::SourceRef,
            campaignHash: $campaignHash,
            audienceHash: $audience->hash(),
        );

        $account->users()->updateExistingPivot($owner->id, ['role' => AccountRole::Manager->value]);
        $result = app(TelegramAlertSender::class)->sendAlertIds($alerts->modelKeys());

        $this->assertSame(['processed' => 1, 'sent' => 0, 'retried' => 0, 'failed' => 1], $result);
        $this->assertSame(TelegramAlertStatus::Failed, $alerts->first()->fresh()->status);
        $this->assertSame('studio_owner_telegram_authorization_missing', $alerts->first()->fresh()->last_error);
        Http::assertNothingSent();
    }

    public function test_invalid_message_input_fails_before_database_or_http_work(): void
    {
        Http::preventStrayRequests();

        $this->artisan('telegram:announce-studio-owners', [
            '--uk-base64' => 'not-base64',
            '--en-base64' => base64_encode(self::EnglishMessage),
            '--source-ref' => self::SourceRef,
            '--json' => true,
        ])->assertFailed();

        $this->assertDatabaseCount((new TelegramAlert)->getTable(), 0);
        Http::assertNothingSent();
    }

    public function test_command_requires_the_skill_or_a_verified_platform_owner_process(): void
    {
        Http::preventStrayRequests();
        $installation = TelegramBotInstallation::factory()->platformOwner()->create();
        $account = Account::factory()->create();
        $owner = $this->addOwner($account, '+380676660001');
        $this->authorize($installation, $account, $owner, 'guarded-owner-chat');

        putenv('LADNA_OWNER_ANNOUNCEMENT_ORIGIN');

        $this->assertSame(1, Artisan::call('telegram:announce-studio-owners', $this->commandOptions()));
        $this->assertStringContainsString(
            'require the Codex skill or a verified platform owner',
            Artisan::output(),
        );

        $ordinaryUser = User::factory()->create();
        putenv('LADNA_OWNER_ANNOUNCEMENT_ORIGIN=platform_owner');

        $this->assertSame(1, Artisan::call('telegram:announce-studio-owners', $this->commandOptions()));
        $this->assertStringContainsString('A valid platform owner user ID is required', Artisan::output());

        putenv('LADNA_OWNER_ANNOUNCEMENT_PLATFORM_USER_ID='.$ordinaryUser->id);

        $this->assertSame(1, Artisan::call('telegram:announce-studio-owners', $this->commandOptions()));
        $this->assertStringContainsString('is not a platform administrator', Artisan::output());

        $platformOwner = User::factory()->platformAdmin()->create();
        putenv('LADNA_OWNER_ANNOUNCEMENT_PLATFORM_USER_ID='.$platformOwner->id);

        $this->assertSame(0, Artisan::call('telegram:announce-studio-owners', $this->commandOptions()));
        $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('platform_owner', $result['execution_origin']);
        $this->assertSame($platformOwner->id, $result['platform_user_id']);
        $this->assertDatabaseCount((new TelegramAlert)->getTable(), 0);
        Http::assertNothingSent();
    }

    public function test_http_route_cannot_invoke_the_command_or_announcement_services(): void
    {
        Http::preventStrayRequests();
        $installation = TelegramBotInstallation::factory()->platformOwner()->create();
        $account = Account::factory()->create();
        $owner = $this->addOwner($account, '+380677770001');
        $this->authorize($installation, $account, $owner, 'http-owner-chat');

        $audience = app(StudioOwnerAnnouncementAudienceResolver::class)->resolve($installation);
        $messages = ['uk' => self::UkrainianMessage, 'en' => self::EnglishMessage];
        $queue = app(QueueStudioOwnerAnnouncement::class);
        $campaignHash = $queue->campaignHash(self::SourceRef, $messages);

        Route::get('/_test/studio-owner-announcement-guard', function () use ($installation, $audience, $messages, $campaignHash): array {
            $commandExitCode = Artisan::call('telegram:announce-studio-owners', $this->commandOptions());

            try {
                app(StudioOwnerAnnouncementAudienceResolver::class)->resolve($installation);
                $resolverGuarded = false;
            } catch (InvalidArgumentException) {
                $resolverGuarded = true;
            }

            try {
                app(QueueStudioOwnerAnnouncement::class)->execute(
                    installation: $installation,
                    audience: $audience,
                    messages: $messages,
                    sourceRef: self::SourceRef,
                    campaignHash: $campaignHash,
                    audienceHash: $audience->hash(),
                );
                $queueGuarded = false;
            } catch (InvalidArgumentException) {
                $queueGuarded = true;
            }

            return [
                'command_exit_code' => $commandExitCode,
                'resolver_guarded' => $resolverGuarded,
                'queue_guarded' => $queueGuarded,
            ];
        });

        $this->getJson('/_test/studio-owner-announcement-guard')
            ->assertOk()
            ->assertExactJson([
                'command_exit_code' => 1,
                'resolver_guarded' => true,
                'queue_guarded' => true,
            ]);

        $this->assertDatabaseCount((new TelegramAlert)->getTable(), 0);
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function commandOptions(array $overrides = []): array
    {
        return [
            '--uk-base64' => base64_encode(self::UkrainianMessage),
            '--en-base64' => base64_encode(self::EnglishMessage),
            '--source-ref' => self::SourceRef,
            '--json' => true,
            ...$overrides,
        ];
    }

    private function addOwner(Account $account, string $phone, ?string $name = null): User
    {
        $owner = User::factory()->create([
            'phone' => $phone,
            ...($name !== null ? ['name' => $name] : []),
        ]);
        $account->addOwner($owner);

        return $owner;
    }

    private function authorize(
        TelegramBotInstallation $installation,
        Account $account,
        ?User $user,
        string $chatId,
        ?string $phone = null,
    ): TelegramChatAuthorization {
        return TelegramChatAuthorization::factory()
            ->for($account)
            ->create([
                'telegram_bot_installation_id' => $installation->id,
                'user_id' => $user?->id,
                'profile' => TelegramBotProfile::Owner->value,
                'telegram_chat_id' => $chatId,
                'phone' => $phone ?? $user?->phone,
                'status' => TelegramChatAuthorizationStatus::Authorized->value,
            ]);
    }
}
