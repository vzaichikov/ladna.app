<?php

namespace App\Support\Festivals;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class FestivalMediaDuplicateAnalysisException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly string $reason,
        public readonly int $httpStatus,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
