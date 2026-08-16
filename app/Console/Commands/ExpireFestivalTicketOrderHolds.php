<?php

namespace App\Console\Commands;

use App\Enums\FestivalTicketOrderStatus;
use App\Models\FestivalTicketOrder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('festival-ticket-orders:expire')]
#[Description('Expire abandoned Festival admission ticket inventory holds')]
class ExpireFestivalTicketOrderHolds extends Command
{
    public function handle(): int
    {
        $expired = FestivalTicketOrder::query()
            ->where('status', FestivalTicketOrderStatus::Pending->value)
            ->where('expires_at', '<=', now())
            ->update([
                'status' => FestivalTicketOrderStatus::Expired->value,
                'failure_reason' => 'Inventory hold expired.',
                'failed_at' => now(),
            ]);

        $this->info("Expired {$expired} Festival ticket order hold(s).");

        return self::SUCCESS;
    }
}
