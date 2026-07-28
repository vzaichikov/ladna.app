<?php

namespace App\Models;

use Database\Factories\AiConversationMessageAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'ai_conversation_message_id', 'source', 'disk', 'path', 'original_name', 'mime_type', 'byte_size', 'width', 'height'])]
class AiConversationMessageAttachment extends Model
{
    /** @use HasFactory<AiConversationMessageAttachmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiConversationMessage::class, 'ai_conversation_message_id');
    }
}
