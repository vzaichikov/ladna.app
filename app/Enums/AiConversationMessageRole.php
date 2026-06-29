<?php

namespace App\Enums;

enum AiConversationMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
    case RejectedIntent = 'rejected_intent';
}
