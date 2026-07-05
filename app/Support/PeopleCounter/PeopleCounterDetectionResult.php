<?php

namespace App\Support\PeopleCounter;

class PeopleCounterDetectionResult
{
    /**
     * @param  array<int, array<string, mixed>>  $detections
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $count,
        public readonly array $detections,
        public readonly array $payload,
        public readonly ?float $averageConfidence,
    ) {}
}
