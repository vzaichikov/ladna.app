<?php

namespace App\Enums;

enum McpToolInvocationStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Denied = 'denied';
}
