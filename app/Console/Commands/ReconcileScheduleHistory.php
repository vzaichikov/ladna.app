<?php

namespace App\Console\Commands;

use App\Actions\ReconcileDeletedScheduleSeriesHistory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;

#[Signature('schedule:reconcile-history {account : Expected studio account id} {manifest : Reviewed JSON source manifest} {--apply : Apply supported repairs; otherwise preview only}')]
#[Description('Preview or restore explicitly mapped historical classes and original pass usage from a reviewed backup manifest.')]
class ReconcileScheduleHistory extends Command
{
    public function handle(ReconcileDeletedScheduleSeriesHistory $reconcileHistory): int
    {
        $path = (string) $this->argument('manifest');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Readable source manifest not found.');

            return self::FAILURE;
        }

        try {
            $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || (int) ($manifest['account_id'] ?? 0) !== (int) $this->argument('account')) {
                $this->error('Manifest account does not match the explicitly selected studio.');

                return self::FAILURE;
            }
            $report = $reconcileHistory->execute($manifest, (bool) $this->option('apply'));
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (JsonException|ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
