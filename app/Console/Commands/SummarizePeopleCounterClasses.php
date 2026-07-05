<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OutputsPeopleCounterDebug;
use App\Support\PeopleCounter\PeopleCounterSummarizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('people-counter:summarize {--limit=200 : Maximum ended classes to summarize in one run} {--debug : Print selection and per-class diagnostics}')]
#[Description('Summarize people counter samples into one row per ended class.')]
class SummarizePeopleCounterClasses extends Command
{
    use OutputsPeopleCounterDebug;

    /**
     * Execute the console command.
     */
    public function handle(PeopleCounterSummarizer $summarizer): int
    {
        $count = $summarizer->summarizeEndedClasses(
            limit: max(1, (int) $this->option('limit')),
            debug: $this->peopleCounterDebugCallback(),
        );

        $this->info(__('app.people_counter_summarize_command_result', ['count' => $count]));

        return self::SUCCESS;
    }
}
