<?php

namespace App\Console\Commands;

use App\Actions\SyncCharmpoleCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('ladna:sync-charmpole-catalog {--account=charmpole : Account slug to sync} {--execute : Apply catalog changes instead of running a dry run}')]
#[Description('Dry-run or sync Charmpole catalog data without running production seeders')]
class SyncCharmpoleCatalogCommand extends Command
{
    public function handle(SyncCharmpoleCatalog $syncCharmpoleCatalog): int
    {
        $accountSlug = (string) $this->option('account');
        $shouldExecute = (bool) $this->option('execute');

        $this->components->info(($shouldExecute ? 'Executing' : 'Dry-running').' Charmpole catalog sync for account ['.$accountSlug.'].');

        try {
            $summary = $syncCharmpoleCatalog->execute($accountSlug, $shouldExecute);
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Metric', 'Value'],
            collect($summary)
                ->reject(fn (mixed $value, string $key): bool => $key === 'account_id')
                ->map(fn (mixed $value, string $key): array => [
                    $key,
                    is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value,
                ])
                ->values()
                ->all(),
        );

        if (! $shouldExecute) {
            $this->components->warn('No data was changed. Re-run with --execute to apply the catalog sync.');
        }

        return self::SUCCESS;
    }
}
