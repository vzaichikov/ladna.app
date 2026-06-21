<?php

namespace App\Console\Commands;

use App\Actions\NormalizeCustomerClassPasses as NormalizeCustomerClassPassesAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('class-passes:normalize')]
#[Description('Normalize customer class pass counters and active state.')]
class NormalizeCustomerClassPasses extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NormalizeCustomerClassPassesAction $normalizeCustomerClassPasses): int
    {
        $count = $normalizeCustomerClassPasses->execute();

        $this->info(__('app.customer_class_passes_normalized', ['count' => $count]));

        return self::SUCCESS;
    }
}
