<?php

namespace App\Console\Commands;

use App\Actions\SyncDemoStudioShowcase as SyncShowcase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('demo-studio:showcase {--expected-account-id= : Required exact demo account ID} {--execute : Apply the displayed additive showcase plan}')]
#[Description('Safely inspect or add the idempotent closed-class and event showcase to the existing read-only demo studio.')]
class SyncDemoStudioShowcase extends Command
{
    public function handle(SyncShowcase $showcase): int
    {
        $expectedAccountId = filter_var($this->option('expected-account-id'), FILTER_VALIDATE_INT);

        if (! $expectedAccountId || $expectedAccountId < 1) {
            $this->error('A positive --expected-account-id is required.');

            return self::FAILURE;
        }

        try {
            $plan = $showcase->preview($expectedAccountId);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Target', 'Value'], [
            ['account', "#{$plan['account_id']} ({$plan['account_slug']})"],
            ['owner', $plan['owner_email']],
            ['event slugs', implode(', ', $plan['event_slugs'])],
            ['order IDs', implode(', ', $plan['order_ids'])],
            ['ticket codes', implode(', ', $plan['ticket_codes'])],
        ]);
        $this->table(
            ['Resource', 'Creates', 'Updates', 'No-ops'],
            collect($plan['resources'])->map(
                fn (array $counts, string $resource): array => [
                    $resource,
                    $counts['create'],
                    $counts['update'],
                    $counts['noop'],
                ],
            )->values()->all(),
        );

        if (! $this->option('execute')) {
            $this->warn('Dry run only. No database changes were made.');

            return self::SUCCESS;
        }

        try {
            $result = $showcase->execute($expectedAccountId);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Showcase synchronized for account #{$result['account']->id} without replacing the account or existing studio data.");

        return self::SUCCESS;
    }
}
