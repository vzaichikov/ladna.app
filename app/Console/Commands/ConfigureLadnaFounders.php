<?php

namespace App\Console\Commands;

use App\Enums\TelegramBotProfile;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramBroadcastTarget;
use App\Support\Telegram\Announcements\PlatformAnnouncementExecutionGuard;
use App\Support\Telegram\Announcements\TelegramBroadcastTargetVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('telegram:configure-ladna-founders
    {--chat-id= : Numeric Telegram group, supergroup, or channel ID}
    {--expected-title=Ladna Founders : Exact Telegram destination title}
    {--expected-target-hash= : Target hash returned by the preview}
    {--execute : Save and enable the verified target}
    {--force : Required with --execute in production}
    {--json : Emit one machine-readable JSON object}')]
#[Description('Preview or configure the verified Ladna Founders Telegram destination.')]
class ConfigureLadnaFounders extends Command
{
    public function handle(
        PlatformAnnouncementExecutionGuard $executionGuard,
        TelegramBroadcastTargetVerifier $verifier,
    ): int {
        try {
            $actor = $executionGuard->authorize();
            $installation = $this->supportBotInstallation();
            $verified = $verifier->verify(
                $installation,
                trim((string) $this->option('chat-id')),
                trim((string) $this->option('expected-title')),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->failCommand(['error' => $exception->getMessage()]);
        }

        $targetHash = $verified->hash(
            $installation->id,
            TelegramBroadcastTarget::PurposeLadnaFounders,
        );
        $existingTarget = TelegramBroadcastTarget::query()
            ->where('telegram_bot_installation_id', $installation->id)
            ->where('purpose', TelegramBroadcastTarget::PurposeLadnaFounders)
            ->first();
        $result = [
            'ok' => true,
            'mode' => 'preview',
            'target_hash' => $targetHash,
            'target' => [
                'title' => $verified->title,
                'type' => $verified->chatType,
                'bot_status' => $verified->botStatus,
                'configured' => $this->matches($existingTarget, $verified->chatId, $verified->title, $verified->chatType),
            ],
            'execution_origin' => $actor['origin'],
            'platform_user_id' => $actor['platform_user_id'],
        ];

        if (! $this->option('execute')) {
            $this->writeResult($result);

            return self::SUCCESS;
        }

        if (app()->isProduction() && ! $this->option('force')) {
            return $this->failCommand([...$result, 'error' => 'Use --force with --execute in production.']);
        }

        $expectedTargetHash = trim((string) $this->option('expected-target-hash'));

        if ($expectedTargetHash === '' || ! hash_equals($targetHash, $expectedTargetHash)) {
            return $this->failCommand([...$result, 'error' => 'The expected target hash is missing or no longer matches. Run a new preview.']);
        }

        TelegramBroadcastTarget::query()->updateOrCreate(
            [
                'telegram_bot_installation_id' => $installation->id,
                'purpose' => TelegramBroadcastTarget::PurposeLadnaFounders,
            ],
            [
                'telegram_chat_id' => $verified->chatId,
                'title' => $verified->title,
                'chat_type' => $verified->chatType,
                'is_enabled' => true,
                'verified_at' => now(),
            ],
        );

        $this->writeResult([
            ...$result,
            'mode' => 'execute',
            'target' => [
                ...$result['target'],
                'configured' => true,
            ],
        ]);

        return self::SUCCESS;
    }

    private function supportBotInstallation(): TelegramBotInstallation
    {
        $installation = TelegramBotInstallation::query()
            ->where('scope_type', 'platform')
            ->where('scope_id', 0)
            ->where('profile', TelegramBotProfile::Owner->value)
            ->where('is_enabled', true)
            ->latest('updated_at')
            ->latest('id')
            ->first();

        if (! $installation) {
            throw new InvalidArgumentException('The enabled Ladna support bot is not configured.');
        }

        if (! $installation->tokenValue()) {
            throw new InvalidArgumentException('The Ladna support bot token is missing.');
        }

        return $installation;
    }

    private function matches(
        ?TelegramBroadcastTarget $target,
        string $chatId,
        string $title,
        string $chatType,
    ): bool {
        return $target?->is_enabled === true
            && $target->verified_at !== null
            && $target->telegram_chat_id === $chatId
            && $target->title === $title
            && $target->chat_type === $chatType;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function failCommand(array $result): int
    {
        $this->writeResult([...$result, 'ok' => false]);

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function writeResult(array $result): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }

        if (isset($result['error'])) {
            $this->components->error((string) $result['error']);

            return;
        }

        $this->table(['Field', 'Value'], [
            ['Mode', (string) $result['mode']],
            ['Target', (string) data_get($result, 'target.title')],
            ['Type', (string) data_get($result, 'target.type')],
            ['Bot status', (string) data_get($result, 'target.bot_status')],
            ['Configured', data_get($result, 'target.configured') ? 'yes' : 'no'],
            ['Target hash', (string) $result['target_hash']],
        ]);
    }
}
