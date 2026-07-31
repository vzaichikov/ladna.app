<?php

namespace App\Support\CustomerAuth;

readonly class SmsSegmentEstimate
{
    public function __construct(
        public SmsEncoding $encoding,
        public int $units,
        public int $segments,
        public int $singleSegmentLimit,
        public int $concatenatedSegmentLimit,
    ) {}
}
