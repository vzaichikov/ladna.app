<?php

namespace App\Support\Telegram;

use App\Enums\TelegramBotProfile;
use App\Enums\TelegramUpdateStatus;
use App\Models\TelegramUpdate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TelegramUpdateDispatcher
{
    private const MaxAttempts = 3;

    public function __construct(
        private readonly Application $application,
        private readonly CustomerTelegramUpdateProcessor $customerProcessor,
    ) {}

    public function process(int $telegramUpdateId): void
    {
        $telegramUpdate = $this->claim($telegramUpdateId);

        if (! $telegramUpdate) {
            return;
        }

        try {
            if ($telegramUpdate->profile === TelegramBotProfile::Customer) {
                $processed = $this->customerProcessor->handle($telegramUpdate);
                $telegramUpdate->forceFill([
                    'status' => $processed ? TelegramUpdateStatus::Processed->value : TelegramUpdateStatus::Ignored->value,
                    'error_message' => null,
                    'available_at' => null,
                    'processing_started_at' => null,
                    'processed_at' => now(),
                ])->save();

                return;
            }

            $this->application->make(TelegramUpdateProcessor::class)->process($telegramUpdate->id);
            $telegramUpdate->refresh();

            if ($telegramUpdate->status === TelegramUpdateStatus::Failed) {
                $this->retryOrFail($telegramUpdate, (string) $telegramUpdate->error_message);

                return;
            }

            $telegramUpdate->forceFill([
                'status' => $telegramUpdate->status === TelegramUpdateStatus::Processed
                    ? TelegramUpdateStatus::Processed->value
                    : TelegramUpdateStatus::Ignored->value,
                'available_at' => null,
                'processing_started_at' => null,
                'error_message' => null,
                'processed_at' => $telegramUpdate->processed_at ?? now(),
            ])->save();
        } catch (Throwable $throwable) {
            report(new RuntimeException('Telegram update processing failed ('.$throwable::class.').'));
            $this->retryOrFail($telegramUpdate->fresh(), 'telegram_update_processing_failed');
        }
    }

    /**
     * @return array{processed: int, pending: int, failed: int}
     */
    public function processPending(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $ids = TelegramUpdate::query()
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('status', TelegramUpdateStatus::Pending->value)
                        ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()));
                })->orWhere(function ($query): void {
                    $query->where('status', TelegramUpdateStatus::Processing->value)
                        ->where(fn ($query) => $query
                            ->whereNull('processing_started_at')
                            ->orWhere('processing_started_at', '<=', now()->subMinutes(5)));
                });
            })
            ->where('attempts', '<', self::MaxAttempts)
            ->orderByRaw('COALESCE(available_at, received_at, created_at)')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $this->process((int) $id);
        }

        return [
            'processed' => $ids->count(),
            'pending' => TelegramUpdate::query()->where('status', TelegramUpdateStatus::Pending->value)->count(),
            'failed' => TelegramUpdate::query()->where('status', TelegramUpdateStatus::Failed->value)->count(),
        ];
    }

    private function claim(int $telegramUpdateId): ?TelegramUpdate
    {
        return DB::transaction(function () use ($telegramUpdateId): ?TelegramUpdate {
            $telegramUpdate = TelegramUpdate::query()->whereKey($telegramUpdateId)->lockForUpdate()->first();

            if (! $telegramUpdate || $telegramUpdate->attempts >= self::MaxAttempts) {
                return null;
            }

            $isDuePending = $telegramUpdate->status === TelegramUpdateStatus::Pending
                && (! $telegramUpdate->available_at || $telegramUpdate->available_at->lessThanOrEqualTo(now()));
            $isStaleProcessing = $telegramUpdate->status === TelegramUpdateStatus::Processing
                && (! $telegramUpdate->processing_started_at || $telegramUpdate->processing_started_at->lessThanOrEqualTo(now()->subMinutes(5)));

            if (! $isDuePending && ! $isStaleProcessing) {
                return null;
            }

            $telegramUpdate->forceFill([
                'status' => TelegramUpdateStatus::Processing->value,
                'attempts' => $telegramUpdate->attempts + 1,
                'processing_started_at' => now(),
                'available_at' => null,
                'processed_at' => null,
            ])->save();

            return $telegramUpdate->load(['account', 'installation.account']);
        });
    }

    private function retryOrFail(TelegramUpdate $telegramUpdate, string $error): void
    {
        $failed = $telegramUpdate->attempts >= self::MaxAttempts;

        $telegramUpdate->forceFill([
            'status' => $failed ? TelegramUpdateStatus::Failed->value : TelegramUpdateStatus::Pending->value,
            'available_at' => $failed ? null : now()->addMinutes($this->backoffMinutes($telegramUpdate->attempts)),
            'processing_started_at' => null,
            'processed_at' => $failed ? now() : null,
            'error_message' => Str::limit($error, 2000),
        ])->save();
    }

    private function backoffMinutes(int $attempt): int
    {
        return match ($attempt) {
            1 => 1,
            2 => 5,
            default => 15,
        };
    }
}
