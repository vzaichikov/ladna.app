<?php

namespace App\Console\Commands;

use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramBotProfile;
use App\Models\TelegramAlert;
use App\Models\TelegramBotInstallation;
use App\Support\Telegram\Alerts\TelegramAlertSender;
use App\Support\Telegram\Announcements\QueueStudioOwnerAnnouncement;
use App\Support\Telegram\Announcements\StudioOwnerAnnouncementAudience;
use App\Support\Telegram\Announcements\StudioOwnerAnnouncementAudienceResolver;
use App\Support\Telegram\Announcements\StudioOwnerAnnouncementExecutionGuard;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

#[Signature('telegram:announce-studio-owners
    {--uk-base64= : Base64-encoded Ukrainian plain-text message}
    {--en-base64= : Base64-encoded English plain-text message}
    {--source-ref= : Deployed production Git SHA}
    {--expected-audience-hash= : Audience hash returned by the preview}
    {--execute : Queue and attempt delivery}
    {--force : Required with --execute in production}
    {--json : Emit one machine-readable JSON object}')]
#[Description('Preview or send an internal CLI-only Ladna Bot announcement to current studio owners.')]
class AnnounceStudioOwners extends Command
{
    private const TelegramMessageMaxLength = 4096;

    public function handle(
        StudioOwnerAnnouncementExecutionGuard $executionGuard,
        StudioOwnerAnnouncementAudienceResolver $audienceResolver,
        QueueStudioOwnerAnnouncement $queueAnnouncement,
        TelegramAlertSender $alertSender,
    ): int {
        try {
            $actor = $executionGuard->authorize();
            $messages = [
                'uk' => $this->message('uk-base64', 'Ukrainian'),
                'en' => $this->message('en-base64', 'English'),
            ];
            $sourceRef = $this->sourceRef();
            $installation = $this->ownerBotInstallation();
            $audience = $audienceResolver->resolve($installation);
        } catch (InvalidArgumentException $exception) {
            return $this->failCommand(['error' => $exception->getMessage()]);
        }

        $campaignHash = $queueAnnouncement->campaignHash($sourceRef, $messages);

        try {
            $audienceHash = $audience->hash();
        } catch (JsonException $exception) {
            return $this->failCommand(['error' => 'The audience snapshot could not be encoded.']);
        }

        $result = $this->result(
            audience: $audience,
            audienceHash: $audienceHash,
            campaignHash: $campaignHash,
            messages: $messages,
            sourceRef: $sourceRef,
            actor: $actor,
        );

        if ($audience->integrityErrors !== []) {
            return $this->failCommand([...$result, 'error' => 'Audience integrity validation failed.']);
        }

        if ($audience->recipients->isEmpty()) {
            return $this->failCommand([...$result, 'error' => 'No eligible studio-owner bot subscriptions were found.']);
        }

        if (! $this->option('execute')) {
            $this->writeResult($result);

            return self::SUCCESS;
        }

        if (app()->isProduction() && ! $this->option('force')) {
            return $this->failCommand([...$result, 'error' => 'Use --force with --execute in production.']);
        }

        $expectedAudienceHash = trim((string) $this->option('expected-audience-hash'));

        if ($expectedAudienceHash === '' || ! hash_equals($audienceHash, $expectedAudienceHash)) {
            return $this->failCommand([...$result, 'error' => 'The expected audience hash is missing or no longer matches. Run a new preview.']);
        }

        $alerts = $queueAnnouncement->execute(
            installation: $installation,
            audience: $audience,
            messages: $messages,
            sourceRef: $sourceRef,
            campaignHash: $campaignHash,
            audienceHash: $audienceHash,
        );
        $delivery = $alertSender->sendAlertIds($alerts->modelKeys());

        $this->writeResult([
            ...$result,
            'mode' => 'execute',
            'delivery' => $delivery,
            'statuses' => $this->statusCounts($campaignHash),
        ]);

        return self::SUCCESS;
    }

    private function ownerBotInstallation(): TelegramBotInstallation
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
            throw new InvalidArgumentException('The enabled platform owner bot is not configured.');
        }

        if (! $installation->tokenValue()) {
            throw new InvalidArgumentException('The platform owner bot token is missing.');
        }

        return $installation;
    }

    private function message(string $option, string $label): string
    {
        $encoded = trim((string) $this->option($option));
        $decoded = $encoded === '' ? false : base64_decode($encoded, true);

        if (! is_string($decoded) || ! mb_check_encoding($decoded, 'UTF-8')) {
            throw new InvalidArgumentException("The {$label} message must be valid base64-encoded UTF-8 text.");
        }

        $message = trim($decoded);

        if ($message === '') {
            throw new InvalidArgumentException("The {$label} message cannot be empty.");
        }

        if (Str::length($message) > self::TelegramMessageMaxLength) {
            throw new InvalidArgumentException("The {$label} message cannot exceed ".self::TelegramMessageMaxLength.' characters.');
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
     * @param  array{uk: string, en: string}  $messages
     * @param  array{origin: 'codex_skill'|'platform_owner', platform_user_id: int|null}  $actor
     * @return array<string, mixed>
     */
    private function result(
        StudioOwnerAnnouncementAudience $audience,
        string $audienceHash,
        string $campaignHash,
        array $messages,
        string $sourceRef,
        array $actor,
    ): array {
        return [
            'ok' => true,
            'mode' => 'preview',
            'source_ref' => $sourceRef,
            'campaign_hash' => $campaignHash,
            'audience_hash' => $audienceHash,
            'execution_origin' => $actor['origin'],
            'platform_user_id' => $actor['platform_user_id'],
            'eligible_chats' => $audience->recipients->count(),
            'eligible_owners' => $audience->ownerCount(),
            'locales' => [
                'uk' => $audience->recipients->where('locale', 'uk')->count(),
                'en' => $audience->recipients->where('locale', 'en')->count(),
            ],
            'excluded' => $audience->excluded,
            'integrity_errors' => $audience->integrityErrors,
            'messages' => $messages,
            'statuses' => $this->statusCounts($campaignHash),
        ];
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
        }

        if (! isset($result['audience_hash'])) {
            return;
        }

        $this->table(['Field', 'Value'], [
            ['Mode', (string) $result['mode']],
            ['Source ref', (string) $result['source_ref']],
            ['Campaign hash', (string) $result['campaign_hash']],
            ['Audience hash', (string) $result['audience_hash']],
            ['Eligible chats', (string) $result['eligible_chats']],
            ['Eligible owners', (string) $result['eligible_owners']],
            ['Ukrainian chats', (string) data_get($result, 'locales.uk', 0)],
            ['English chats', (string) data_get($result, 'locales.en', 0)],
        ]);
        $this->newLine();
        $this->components->info('Ukrainian message');
        $this->line((string) data_get($result, 'messages.uk'));
        $this->newLine();
        $this->components->info('English message');
        $this->line((string) data_get($result, 'messages.en'));
    }
}
