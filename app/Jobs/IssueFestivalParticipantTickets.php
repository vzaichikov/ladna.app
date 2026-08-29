<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Compatibility shell for participant-ticket jobs queued before entrance passes replaced them.
 */
class IssueFestivalParticipantTickets implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $editionId,
        public readonly int $registrantId,
        public readonly int $admissionTypeId,
        public readonly int $actorId,
    ) {}

    public function uniqueId(): string
    {
        return implode(':', [$this->editionId, $this->registrantId, $this->admissionTypeId]);
    }

    public function handle(): void {}
}
