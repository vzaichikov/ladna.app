<?php

namespace App\Models;

use Database\Factories\AiProviderRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'account_id',
    'user_id',
    'ai_conversation_id',
    'ai_conversation_message_id',
    'channel',
    'provider',
    'model',
    'request_type',
    'provider_round',
    'status',
    'provider_request_id',
    'input_tokens',
    'cached_input_tokens',
    'output_tokens',
    'reasoning_tokens',
    'total_tokens',
    'duration_ms',
    'error_code',
    'started_at',
    'finished_at',
])]
class AiProviderRequest extends Model
{
    /** @use HasFactory<AiProviderRequestFactory> */
    use HasFactory;

    public const StatusSucceeded = 'succeeded';

    public const StatusFailed = 'failed';

    public const TypeVisualAnalysis = 'visual_analysis';

    public const TypeInference = 'inference';

    public const TypeFinalSynthesis = 'final_synthesis';

    public const TypeEnvelopeRepair = 'envelope_repair';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_round' => 'integer',
            'input_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'total_tokens' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function conversationMessage(): BelongsTo
    {
        return $this->belongsTo(AiConversationMessage::class, 'ai_conversation_message_id');
    }
}
