<?php

namespace App\Support\PeopleCounter;

class PeopleCounterCaptureResult
{
    public function __construct(
        public readonly string $path,
        public readonly int $width,
        public readonly int $height,
    ) {}
}
