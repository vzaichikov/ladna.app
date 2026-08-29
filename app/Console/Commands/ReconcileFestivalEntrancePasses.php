<?php

namespace App\Console\Commands;

use App\Actions\Festivals\ReconcileFestivalEntrancePasses as ReconcileFestivalEntrancePassesAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('festival-entrance-passes:reconcile')]
#[Description('Reconcile entrance passes for eligible Festival participants and helpers')]
class ReconcileFestivalEntrancePasses extends Command
{
    public function handle(ReconcileFestivalEntrancePassesAction $reconcile): int
    {
        $result = $reconcile->execute();
        $this->info(sprintf(
            'Festival entrance passes reconciled: %d editions, %d created, %d reactivated, %d disabled.',
            $result['editions'],
            $result['created'],
            $result['reactivated'],
            $result['disabled'],
        ));

        return self::SUCCESS;
    }
}
