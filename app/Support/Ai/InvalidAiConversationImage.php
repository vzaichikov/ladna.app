<?php

namespace App\Support\Ai;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class InvalidAiConversationImage extends RuntimeException implements ShouldntReport
{
    public function __construct(private readonly string $imageReason)
    {
        parent::__construct('The supplied AI conversation image is invalid.');
    }

    public function reason(): string
    {
        return $this->imageReason;
    }
}
