<?php

namespace App\Console\Commands;

use App\Enums\EventOrderStatus;
use App\Models\EventOrder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('event-orders:expire')]
#[Description('Expire abandoned event ticket inventory holds')]
class ExpireEventOrderHolds extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $expired = EventOrder::query()
            ->where('status', EventOrderStatus::Pending->value)
            ->where('expires_at', '<=', now())
            ->update([
                'status' => EventOrderStatus::Expired->value,
                'failure_reason' => 'Inventory hold expired.',
                'failed_at' => now(),
            ]);

        $this->info("Expired {$expired} event order hold(s).");

        return self::SUCCESS;
    }
}
