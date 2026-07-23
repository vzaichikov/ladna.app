<?php

namespace App\Console\Commands;

use App\Enums\TelegramAlertStatus;
use App\Models\TelegramAlert;
use App\Models\TelegramBroadcastTarget;
use App\Support\Telegram\Alerts\TelegramAlertSender;
use App\Support\Telegram\Announcements\PlatformAnnouncementExecutionGuard;
use App\Support\Telegram\Announcements\QueueFoundersAnnouncement;
use App\Support\Telegram\Announcements\TelegramBroadcastTargetVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Signature('telegram:announce-ladna-founders
    {--message-base64= : Base64-encoded Ukrainian plain-text message}
    {--source-ref= : Deployed production Git SHA}
    {--expected-target-hash= : Target hash returned by the preview}
    {--execute : Queue and attempt delivery}
    {--force : Required with --execute in production}
    {--json : Emit one machine-readable JSON object}')]
#[Description('Preview or send a guarded announcement to the verified Ladna Founders destination.')]
class AnnounceLadnaFounders extends Command
{
    private const TelegramMessageMaxLength = 4096;

    public function handle(
        PlatformAnnouncementExecutionGuard $executionGuard,
        TelegramBroadcastTargetVerifier $verifier,
        QueueFoundersAnnouncement $queueAnnouncement,
        TelegramAlertSender $alertSender,
    ): int {
        try {
            $actor = $executionGuard->authorize();
            $message = $this->message();
            $sourceRef = $this->sourceRef();
            $target = $this->target();
            $installation = $target->installation;
            $verified = $verifier->verify(
                $installation,
                $target->telegram_chat_id,
                $target->title,
            );

            if ($target->chat_type !== $verified->chatType) {
                throw new InvalidArgumentException('The Telegram destination type changed. Reconfigure Ladna Founders.');
            }
        } catch (InvalidArgumentException $exception) {
            return $this->failCommand(['error' => $exception->getMessage()]);
        }

        $targetHash = $verified->hash($installation->id, $target->purpose);
        $campaignHash = $queueAnnouncement->campaignHash($sourceRef, $message, $targetHash);
        $result = [
            'ok' => true,
            'mode' => 'preview',
            'source_ref' => $sourceRef,
            'campaign_hash' => $campaignHash,
            'target_hash' => $targetHash,
            'target' => [
                'title' => $verified->title,
                'type' => $verified->chatType,
            ],
            'message' => $message,
            'execution_origin' => $actor['origin'],
            'platform_user_id' => $actor['platform_user_id'],
            'statuses' => $this->statusCounts($campaignHash),
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

        $alert = $queueAnnouncement->execute(
            target: $target,
            message: $message,
            sourceRef: $sourceRef,
            campaignHash: $campaignHash,
            targetHash: $targetHash,
        );
        $delivery = $alertSender->sendAlertIds([$alert->id]);

        $this->writeResult([
            ...$result,
            'mode' => 'execute',
            'delivery' => $delivery,
            'statuses' => $this->statusCounts($campaignHash),
        ]);

        return self::SUCCESS;
    }

    private function target(): TelegramBroadcastTarget
    {
        $target = TelegramBroadcastTarget::query()
            ->with('installation')
            ->where('purpose', TelegramBroadcastTarget::PurposeLadnaFounders)
            ->where('is_enabled', true)
            ->whereNotNull('verified_at')
            ->first();

        if (! $target) {
            throw new InvalidArgumentException('The Ladna Founders destination is not configured, verified, and enabled.');
        }

        $installation = $target->installation;

        if (
            ! $installation
            || ! $installation->isPlatformScoped()
            || ! $installation->is_enabled
            || ! $installation->tokenValue()
        ) {
            throw new InvalidArgumentException('The enabled Ladna support bot is not configured.');
        }

        return $target;
    }

    private function message(): string
    {
        $encoded = trim((string) $this->option('message-base64'));
        $decoded = $encoded === '' ? false : base64_decode($encoded, true);

        if (! is_string($decoded) || ! mb_check_encoding($decoded, 'UTF-8')) {
            throw new InvalidArgumentException('The Ukrainian message must be valid base64-encoded UTF-8 text.');
        }

        $message = trim($decoded);

        if ($message === '') {
            throw new InvalidArgumentException('The Ukrainian message cannot be empty.');
        }

        if (Str::length($message) > self::TelegramMessageMaxLength) {
            throw new InvalidArgumentException('The Ukrainian message cannot exceed '.self::TelegramMessageMaxLength.' characters.');
        }

        return $message;
    }

    private function sourceRef(): string
    {
        $sourceRef = mb_strtolower(trim((string) $this->option('source-ref')));

        if (preg_match('/\A[0-9a-f]{7,64}\z/', $sourceRef) !== 1) {
            throw new InvalidArgumentException('The source ref must be a deployed Git SHA.');
        }

        return $sourceRef;
    }

    /**
     * @return array{pending: int, processing: int, sent: int, failed: int}
     */
    private function statusCounts(string $campaignHash): array
    {
        $counts = TelegramAlert::query()
            ->where('payload->campaign_hash', $campaignHash)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'pending' => (int) $counts->get(TelegramAlertStatus::Pending->value, 0),
            'processing' => (int) $counts->get(TelegramAlertStatus::Processing->value, 0),
            'sent' => (int) $counts->get(TelegramAlertStatus::Sent->value, 0),
            'failed' => (int) $counts->get(TelegramAlertStatus::Failed->value, 0),
        ];
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
            ['Source ref', (string) $result['source_ref']],
            ['Campaign hash', (string) $result['campaign_hash']],
            ['Target hash', (string) $result['target_hash']],
            ['Target', (string) data_get($result, 'target.title')],
            ['Type', (string) data_get($result, 'target.type')],
            ['Sent', (string) data_get($result, 'statuses.sent', 0)],
            ['Pending', (string) data_get($result, 'statuses.pending', 0)],
            ['Processing', (string) data_get($result, 'statuses.processing', 0)],
            ['Failed', (string) data_get($result, 'statuses.failed', 0)],
        ]);
        $this->newLine();
        $this->components->info('Ukrainian message');
        $this->line((string) $result['message']);
    }
}
