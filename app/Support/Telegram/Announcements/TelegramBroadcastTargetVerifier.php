<?php

namespace App\Support\Telegram\Announcements;

use App\Models\TelegramBotInstallation;
use App\Support\Telegram\TelegramClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class TelegramBroadcastTargetVerifier
{
    public function __construct(private TelegramClient $telegramClient) {}

    public function verify(
        TelegramBotInstallation $installation,
        string $chatId,
        string $expectedTitle,
    ): VerifiedTelegramBroadcastTarget {
        $chatId = trim($chatId);
        $expectedTitle = trim($expectedTitle);

        if (preg_match('/\A-?\d+\z/', $chatId) !== 1) {
            throw new InvalidArgumentException('The Telegram destination must use a numeric chat ID.');
        }

        if ($expectedTitle === '') {
            throw new InvalidArgumentException('The expected Telegram destination title is required.');
        }

        if (! $installation->tokenValue()) {
            throw new InvalidArgumentException('The Ladna support bot token is missing.');
        }

        $chat = $this->result(
            $this->telegramClient->getChat($installation, $chatId),
            'Telegram could not verify the configured destination.',
        );
        $verifiedChatId = (string) data_get($chat, 'id', '');
        $chatType = (string) data_get($chat, 'type', '');
        $title = trim((string) data_get($chat, 'title', ''));

        if ($verifiedChatId === '' || ! hash_equals($chatId, $verifiedChatId)) {
            throw new InvalidArgumentException('Telegram returned a different destination chat ID.');
        }

        if (! in_array($chatType, ['group', 'supergroup', 'channel'], true)) {
            throw new InvalidArgumentException('The Telegram destination must be a group, supergroup, or channel.');
        }

        if ($title === '' || ! hash_equals($expectedTitle, $title)) {
            throw new InvalidArgumentException('The Telegram destination title does not match the configured title.');
        }

        $bot = $this->result(
            $this->telegramClient->getMe($installation),
            'Telegram could not verify the Ladna support bot identity.',
        );
        $botId = (string) data_get($bot, 'id', '');

        if ($botId === '') {
            throw new InvalidArgumentException('Telegram did not return the Ladna support bot ID.');
        }

        $membership = $this->result(
            $this->telegramClient->getChatMember($installation, $chatId, $botId),
            'Telegram could not verify the Ladna support bot membership.',
        );
        $botStatus = (string) data_get($membership, 'status', '');

        if (! in_array($botStatus, ['creator', 'administrator', 'member'], true)) {
            throw new InvalidArgumentException('The Ladna support bot is not an active member of the destination.');
        }

        if (
            $chatType === 'channel'
            && $botStatus !== 'creator'
            && (
                $botStatus !== 'administrator'
                || data_get($membership, 'can_post_messages') !== true
            )
        ) {
            throw new InvalidArgumentException('The Ladna support bot cannot post to the configured channel.');
        }

        return new VerifiedTelegramBroadcastTarget(
            chatId: $verifiedChatId,
            title: $title,
            chatType: $chatType,
            botStatus: $botStatus,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function result(?Response $response, string $fallback): array
    {
        if ($response?->successful() === true && $response->json('ok') === true) {
            $result = $response->json('result');

            if (is_array($result)) {
                return $result;
            }
        }

        $description = trim((string) $response?->json('description', ''));

        throw new InvalidArgumentException(
            $description !== '' ? Str::limit($description, 500) : $fallback,
        );
    }
}
