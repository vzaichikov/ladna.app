<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OutputsPeopleCounterDebug;
use App\Support\PeopleCounter\PeopleCounterPruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('people-counter:prune {--debug : Print retention and deleted-record diagnostics}')]
#[Description('Delete old people counter screenshots, samples, and summaries.')]
class PrunePeopleCounterData extends Command
{
    use OutputsPeopleCounterDebug;

    /**
     * Execute the console command.
     */
    public function handle(PeopleCounterPruner $pruner): int
    {
        $count = $pruner->prune(debug: $this->peopleCounterDebugCallback());

        $this->info(__('app.people_counter_prune_command_result', ['count' => $count]));

        return self::SUCCESS;
    }
}
