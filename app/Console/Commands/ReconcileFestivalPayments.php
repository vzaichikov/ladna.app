<?php

namespace App\Console\Commands;

use App\Actions\Festivals\ReconcileFestivalPaymentAttempt;
use App\Enums\AccountMode;
use App\Enums\FestivalPaymentStatus;
use App\Enums\IntegrationProvider;
use App\Models\FestivalPaymentAttempt;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('festival-payments:reconcile {--limit=50 : Maximum stale payments to check}')]
#[Description('Recover stale Festival participation payments from their provider status')]
class ReconcileFestivalPayments extends Command
{
    public function handle(ReconcileFestivalPaymentAttempt $reconcile): int
    {
        $attempts = FestivalPaymentAttempt::query()
            ->where('provider', IntegrationProvider::Monopay->value)
            ->where('status', FestivalPaymentStatus::Pending->value)
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->whereHas('account', fn ($query) => $query->where('enable_festivals', true)->where('mode', '!=', AccountMode::DemoReadonly->value))
            ->orderBy('updated_at')->orderBy('id')
            ->limit(max(1, min(100, (int) $this->option('limit'))))
            ->get();
        $checked = 0;
        $unavailable = 0;
        foreach ($attempts as $attempt) {
            try {
                $reconcile->execute($attempt);
                $checked++;
            } catch (Throwable $exception) {
                report($exception);
                $unavailable++;
            } finally {
                FestivalPaymentAttempt::query()
                    ->whereKey($attempt->id)
                    ->where('account_id', $attempt->account_id)
                    ->where('status', FestivalPaymentStatus::Pending->value)
                    ->update(['updated_at' => now()]);
            }
        }
        $this->info("Checked {$checked} Festival payment(s); {$unavailable} unavailable.");

        return $unavailable > 0 ? self::FAILURE : self::SUCCESS;
    }
}
