<?php

namespace App\Console\Commands;

use App\Actions\ProvisionDemoReadonlyStudio;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('demo-studio:provision {--execute : Apply the planned demo studio creation} {--refresh : Replace an existing validated read-only demo studio}')]
#[Description('Safely inspect or provision the synthetic read-only Ladna demo studio.')]
class ProvisionDemoStudio extends Command
{
    public function handle(ProvisionDemoReadonlyStudio $provisioner): int
    {
        $refresh = (bool) $this->option('refresh');

        try {
            $preview = $provisioner->preview($refresh);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], collect($preview)->map(
            fn (mixed $value, string $field): array => [$field, (string) $value],
        )->values()->all());

        if (! $this->option('execute')) {
            $this->warn('Dry run only. Re-run with --execute to apply this exact operation.');

            return self::SUCCESS;
        }

        try {
            $account = $provisioner->execute($refresh);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("{$account->name} was provisioned as account #{$account->id}.");

        return self::SUCCESS;
    }
}
