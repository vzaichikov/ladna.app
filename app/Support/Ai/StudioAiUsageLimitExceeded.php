<?php

namespace App\Support\Ai;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class StudioAiUsageLimitExceeded extends Exception implements ShouldntReport
{
    public function __construct(public readonly StudioAiFirewallDecision $decision)
    {
        parent::__construct('Studio AI usage limit reached.');
    }
}
