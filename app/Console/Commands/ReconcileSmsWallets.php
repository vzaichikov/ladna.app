<?php

namespace App\Console\Commands;

use App\Models\AccountSmsWallet;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sms-wallets:reconcile')]
#[Description('Validate SMS wallet snapshots and nonnegative balances')]
class ReconcileSmsWallets extends Command
{
    public function handle(): int
    {
        $mismatches = 0;

        AccountSmsWallet::query()
            ->with('ledgerEntries')
            ->orderBy('id')
            ->chunkById(100, function ($wallets) use (&$mismatches): void {
                foreach ($wallets as $wallet) {
                    $latestEntry = $wallet->ledgerEntries->sortByDesc('id')->first();
                    $invalidSnapshot = $latestEntry
                        && (
                            $latestEntry->balance_after_cents !== $wallet->balance_cents
                            || $latestEntry->outstanding_after_cents !== $wallet->outstanding_cents
                        );
                    $invalidBalance = $wallet->balance_cents < 0
                        || $wallet->reserved_cents < 0
                        || $wallet->reserved_cents > $wallet->balance_cents
                        || $wallet->outstanding_cents < 0;

                    if (! $invalidSnapshot && ! $invalidBalance) {
                        continue;
                    }

                    $mismatches++;
                    $this->error("SMS wallet {$wallet->id} for account {$wallet->account_id} is inconsistent.");
                }
            });

        if ($mismatches > 0) {
            report(new \RuntimeException("SMS wallet reconciliation found {$mismatches} mismatches."));

            return self::FAILURE;
        }

        $this->info('All SMS wallet snapshots are consistent.');

        return self::SUCCESS;
    }
}
