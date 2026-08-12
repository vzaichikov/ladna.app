<?php

namespace App\Console\Commands;

use App\Actions\Festivals\SyncCharmExoticFestival2026 as SyncFestival;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('festivals:sync-charm-exotic-2026
    {--expected-account-id= : Required exact Charmpole account ID}
    {--expected-edition-id= : Required exact empty Festival edition ID}
    {--execute : Apply the displayed import plan}
    {--confirm-production : Explicitly authorize the guarded production target and preserve its existing identity}')]
#[Description('Preview or synchronize Charm Exotic Pole Dance Fest 2026 into the exact guarded Charmpole edition.')]
class SyncCharmExoticFestival2026 extends Command
{
    public function handle(SyncFestival $sync): int
    {
        $accountId = filter_var($this->option('expected-account-id'), FILTER_VALIDATE_INT);
        $editionId = filter_var($this->option('expected-edition-id'), FILTER_VALIDATE_INT);

        if (! $accountId || ! $editionId || $accountId < 1 || $editionId < 1) {
            $this->components->error('Positive --expected-account-id and --expected-edition-id values are required.');

            return self::FAILURE;
        }

        try {
            $preserveExistingIdentity = app()->environment('production');
            $plan = $sync->preview($accountId, $editionId, $preserveExistingIdentity);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Target', 'Value'], [
            ['account', "#{$plan['account_id']} ({$plan['account_slug']})"],
            ['edition', "#{$plan['edition_id']} ({$plan['current_title']})"],
            ['target title', $plan['target_title']],
            ['identity', $plan['identity_preserved'] ? 'preserve existing Series, title, slug, direction, and event times' : 'replace with source mapping'],
            ['categories', (string) $plan['category_count']],
            ['rubrics', (string) $plan['rubric_count']],
            ['registration', 'draft / closed'],
            ['online payment ready', $plan['online_payment_ready'] ? 'yes' : 'no'],
        ]);
        $this->table(
            ['Resource', 'Current', 'Target'],
            collect($plan['current_counts'])->map(
                fn (int $count, string $resource): array => [
                    $resource,
                    $count,
                    match ($resource) {
                        'directions' => 1,
                        'categories' => $plan['category_count'],
                        'workflows' => 2,
                        'requirements' => 13,
                        'fees' => 13,
                        'rubrics' => $plan['rubric_count'],
                        'stages' => 1,
                        'content_sections' => 4,
                        'admission_types' => 0,
                    },
                ],
            )->values()->all(),
        );

        if (! $this->option('execute')) {
            $this->components->warn('Dry run only. No database changes were made.');

            return self::SUCCESS;
        }

        if (app()->environment('production') && ! $this->option('confirm-production')) {
            $this->components->error('Production execution requires --confirm-production after reviewing this dry run and taking a verified backup.');

            return self::FAILURE;
        }

        try {
            $result = $sync->execute(
                $accountId,
                $editionId,
                (bool) $this->option('confirm-production'),
                $preserveExistingIdentity,
            );
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Charm Exotic Pole Dance Fest 2026 synchronized for Charmpole edition #{$result['edition']->id}.");
        $this->table(
            ['Resource', 'Before', 'After'],
            collect($result['after'])->map(
                fn (int $count, string $resource): array => [$resource, $result['before'][$resource], $count],
            )->values()->all(),
        );

        if (! $result['online_payment_ready']) {
            $this->components->warn('Registration remains closed: the Charmpole online payment integration is not configured.');
        }

        return self::SUCCESS;
    }
}
