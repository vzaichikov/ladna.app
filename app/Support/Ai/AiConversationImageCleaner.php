<?php

namespace App\Support\Ai;

use App\Models\Account;
use App\Models\AiConversationMessage;
use App\Models\AiConversationMessageAttachment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AiConversationImageCleaner
{
    /**
     * @param  iterable<int, int|string>  $conversationIds
     */
    public function deleteForConversationIds(iterable $conversationIds): void
    {
        $ids = collect($conversationIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->filter()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $this->deleteQuery(
            AiConversationMessageAttachment::query()
                ->whereHas('message', fn (Builder $query): Builder => $query->whereIn('ai_conversation_id', $ids)),
        );
    }

    /**
     * @param  iterable<int, int|string>  $messageIds
     */
    public function deleteForMessageIds(iterable $messageIds): void
    {
        $ids = collect($messageIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->filter()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $this->deleteQuery(
            AiConversationMessageAttachment::query()->whereIn('ai_conversation_message_id', $ids),
        );
    }

    public function deleteForAccount(Account $account): void
    {
        $this->deleteQuery(
            AiConversationMessageAttachment::query()->whereBelongsTo($account),
        );
    }

    private function deleteQuery(Builder $query): void
    {
        $query
            ->orderBy('id')
            ->chunkById(100, function ($attachments): void {
                $deletedAttachmentIds = [];
                $messageIds = $attachments
                    ->pluck('ai_conversation_message_id')
                    ->map(fn (int|string $id): int => (int) $id)
                    ->unique()
                    ->values();

                foreach ($attachments as $attachment) {
                    $disk = Storage::disk($attachment->disk);

                    if ($disk->exists($attachment->path) && ! $disk->delete($attachment->path)) {
                        if ($deletedAttachmentIds !== []) {
                            AiConversationMessageAttachment::query()
                                ->whereKey($deletedAttachmentIds)
                                ->delete();
                        }

                        throw new RuntimeException('Unable to delete a private assistant image.');
                    }

                    $deletedAttachmentIds[] = $attachment->id;
                }

                if ($deletedAttachmentIds !== []) {
                    AiConversationMessageAttachment::query()
                        ->whereKey($deletedAttachmentIds)
                        ->delete();

                    AiConversationMessage::query()
                        ->whereKey($messageIds)
                        ->get()
                        ->each(function (AiConversationMessage $message): void {
                            $metadata = is_array($message->metadata) ? $message->metadata : [];
                            unset($metadata['visual_context']);
                            $message->forceFill([
                                'metadata' => $metadata !== [] ? $metadata : null,
                            ])->save();
                        });
                }
            });
    }
}
