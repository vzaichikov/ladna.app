<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OutputsPeopleCounterDebug;
use App\Support\PeopleCounter\PeopleCounterCaptureService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('people-counter:capture {--limit=100 : Maximum active classes and unknown-presence rooms to sample in one run} {--debug : Print selection and per-sample diagnostics}')]
#[Description('Capture camera frames for active classes and unknown presence monitoring.')]
class CapturePeopleCounterSamples extends Command
{
    use OutputsPeopleCounterDebug;

    /**
     * Execute the console command.
     */
    public function handle(PeopleCounterCaptureService $captureService): int
    {
        $count = $captureService->captureDueClasses(
            limit: max(1, (int) $this->option('limit')),
            debug: $this->peopleCounterDebugCallback(),
        );

        $this->info(__('app.people_counter_capture_command_result', ['count' => $count]));

        return self::SUCCESS;
    }
}
