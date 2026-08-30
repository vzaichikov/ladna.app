<?php

namespace App\Console\Commands;

use App\Actions\Festivals\AttachFestivalEntryRequirements;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('festivals:backfill-entry-requirements
    {--account= : Required exact Festival account ID}
    {--edition= : Required exact Festival edition ID}
    {--workflow-step= : Required exact Music workflow step ID}
    {--field=* : Exactly five exact registration field IDs}
    {--expected-missing= : Required with --execute and must match the live missing-row count}
    {--execute : Attach the displayed missing requirement rows}
    {--force : Required with --execute in production}')]
#[Description('Safely inspect or attach five entry-scoped fields to existing applications on one exact Festival workflow step.')]
class BackfillFestivalEntryRequirements extends Command
{
    public function handle(AttachFestivalEntryRequirements $attach): int
    {
        $accountId = $this->positiveIntegerOption('account');
        $editionId = $this->positiveIntegerOption('edition');
        $workflowStepId = $this->positiveIntegerOption('workflow-step');
        $fieldIds = collect($this->option('field'))
            ->map(fn (mixed $value): int|false => filter_var($value, FILTER_VALIDATE_INT))
            ->all();

        if (! $accountId || ! $editionId || ! $workflowStepId || in_array(false, $fieldIds, true)) {
            $this->error('Positive integer --account, --edition, --workflow-step, and --field values are required.');

            return self::FAILURE;
        }

        try {
            $plan = $attach->preview($accountId, $editionId, $workflowStepId, $fieldIds);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderPlan($plan);

        if (! $this->option('execute')) {
            $this->warn("Dry run only. No database changes were made. Re-run with --execute --expected-missing={$plan['missing_rows']} after verifying this report.");

            return self::SUCCESS;
        }

        $expectedMissing = filter_var($this->option('expected-missing'), FILTER_VALIDATE_INT);
        if ($expectedMissing === false || $expectedMissing < 0) {
            $this->error('A non-negative --expected-missing value from the latest dry run is required with --execute.');

            return self::FAILURE;
        }

        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Use --force together with --execute in production after the verified backup and dry run.');

            return self::FAILURE;
        }

        try {
            $result = $attach->execute($accountId, $editionId, $workflowStepId, $fieldIds, $expectedMissing);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Attached {$result['inserted_rows']} missing requirement row(s). Post-write verification reports {$result['missing_rows']} missing row(s).");

        return self::SUCCESS;
    }

    private function positiveIntegerOption(string $name): int|false
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT);

        return $value !== false && $value > 0 ? $value : false;
    }

    /** @param array<string, mixed> $plan */
    private function renderPlan(array $plan): void
    {
        $statuses = collect($plan['application_statuses'])
            ->map(fn (int $count, string $status): string => "{$status}: {$count}")
            ->implode(', ');
        $this->table(['Target', 'Value'], [
            ['account', "#{$plan['account_id']} ({$plan['account_name']})"],
            ['edition', "#{$plan['edition_id']} {$plan['edition_title']} ({$plan['edition_slug']})"],
            ['workflow step', "#{$plan['workflow_step_id']} {$plan['workflow_step_title']} ({$plan['workflow_step_code']})"],
            ['applications', "{$plan['application_count']} ({$statuses})"],
            ['existing rows', $plan['existing_rows']],
            ['missing rows', $plan['missing_rows']],
        ]);
        $this->table(
            ['ID', 'Code', 'Name', 'Input', 'Required', 'Extensions', 'MIME types', 'Max KB'],
            collect($plan['fields'])->map(fn (array $field): array => [
                $field['id'],
                $field['code'],
                $field['name'],
                $field['input_type'],
                $field['required'] ? 'yes' : 'no',
                implode(', ', $field['extensions']),
                implode(', ', $field['mime_types']),
                $field['max_size_kb'],
            ])->all(),
        );
    }
}
