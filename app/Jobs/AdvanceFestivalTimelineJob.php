<?php

namespace App\Jobs;

use App\Actions\Festivals\AdvanceFestivalTimeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdvanceFestivalTimelineJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

    public function __construct(
        public readonly int $timelineId,
        public readonly ?int $expectedActiveItemId,
        public readonly ?int $expectedLastFinishedItemId,
        public readonly int $expectedTransitionTimestamp,
    ) {}

    public function handle(AdvanceFestivalTimeline $advance): void
    {
        $advance->execute(
            $this->timelineId,
            $this->expectedActiveItemId,
            $this->expectedLastFinishedItemId,
            $this->expectedTransitionTimestamp,
        );
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('festival-timeline:'.$this->timelineId))
                ->releaseAfter(5)
                ->expireAfter(60),
        ];
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Festival timeline boundary job failed.', [
            'timeline_id' => $this->timelineId,
            'expected_transition_timestamp' => $this->expectedTransitionTimestamp,
            'exception' => $exception,
        ]);
    }
}
