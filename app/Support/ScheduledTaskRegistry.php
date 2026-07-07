<?php

namespace App\Support;

use App\Models\ScheduledTaskStatus;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

class ScheduledTaskRegistry
{
    /**
     * @return array<int, array{key: string, command: string, expression: string, frequency_key: string, description_key: string, overlap_minutes: int|null}>
     */
    public function definitions(): array
    {
        return [
            [
                'key' => 'schedule_generate',
                'command' => 'schedule:generate',
                'expression' => '0 0 * * *',
                'frequency_key' => 'scheduled_task_frequency_daily',
                'description_key' => 'scheduled_task_schedule_generate_description',
                'overlap_minutes' => null,
            ],
            [
                'key' => 'class_passes_normalize',
                'command' => 'class-passes:normalize',
                'expression' => '*/15 * * * *',
                'frequency_key' => 'scheduled_task_frequency_every_fifteen_minutes',
                'description_key' => 'scheduled_task_class_passes_normalize_description',
                'overlap_minutes' => 30,
            ],
            [
                'key' => 'billing_reconcile',
                'command' => 'billing:reconcile',
                'expression' => '0 * * * *',
                'frequency_key' => 'scheduled_task_frequency_hourly',
                'description_key' => 'scheduled_task_billing_reconcile_description',
                'overlap_minutes' => 30,
            ],
            [
                'key' => 'telegram_alerts_send',
                'command' => 'telegram-alerts:send --limit=50',
                'expression' => '* * * * *',
                'frequency_key' => 'scheduled_task_frequency_every_minute',
                'description_key' => 'scheduled_task_telegram_alerts_send_description',
                'overlap_minutes' => 5,
            ],
            [
                'key' => 'customer_notifications_send',
                'command' => 'customer-notifications:send --limit=50',
                'expression' => '* * * * *',
                'frequency_key' => 'scheduled_task_frequency_every_minute',
                'description_key' => 'scheduled_task_customer_notifications_send_description',
                'overlap_minutes' => 5,
            ],
            [
                'key' => 'customer_notifications_fill',
                'command' => 'customer-notifications:fill --lookahead-hours=192 --limit=1000',
                'expression' => '*/30 * * * *',
                'frequency_key' => 'scheduled_task_frequency_every_thirty_minutes',
                'description_key' => 'scheduled_task_customer_notifications_fill_description',
                'overlap_minutes' => 10,
            ],
            [
                'key' => 'account_activity_logs_prune',
                'command' => 'account-activity-logs:prune',
                'expression' => '0 0 * * *',
                'frequency_key' => 'scheduled_task_frequency_daily',
                'description_key' => 'scheduled_task_account_activity_logs_prune_description',
                'overlap_minutes' => 30,
            ],
            [
                'key' => 'people_counter_capture',
                'command' => 'people-counter:capture',
                'expression' => '*/7 * * * *',
                'frequency_key' => 'scheduled_task_frequency_every_seven_minutes',
                'description_key' => 'scheduled_task_people_counter_capture_description',
                'overlap_minutes' => 10,
            ],
            [
                'key' => 'people_counter_summarize',
                'command' => 'people-counter:summarize',
                'expression' => '*/30 * * * *',
                'frequency_key' => 'scheduled_task_frequency_every_thirty_minutes',
                'description_key' => 'scheduled_task_people_counter_summarize_description',
                'overlap_minutes' => 30,
            ],
            [
                'key' => 'people_counter_prune',
                'command' => 'people-counter:prune',
                'expression' => '10 0 * * *',
                'frequency_key' => 'scheduled_task_frequency_daily',
                'description_key' => 'scheduled_task_people_counter_prune_description',
                'overlap_minutes' => 30,
            ],
        ];
    }

    public function schedule(): void
    {
        foreach ($this->definitions() as $definition) {
            $event = Schedule::command($definition['command'])
                ->cron($definition['expression'])
                ->before(fn (): ScheduledTaskStatus => $this->markStarted($definition))
                ->onSuccess(fn (Stringable $output): ScheduledTaskStatus => $this->markFinished(
                    $definition,
                    ScheduledTaskStatus::StatusSucceeded,
                    0,
                    $output,
                ))
                ->onFailure(fn (Stringable $output): ScheduledTaskStatus => $this->markFinished(
                    $definition,
                    ScheduledTaskStatus::StatusFailed,
                    1,
                    $output,
                ));

            $this->applyOverlapProtection($event, $definition['overlap_minutes']);
        }
    }

    /**
     * @return Collection<int, array{key: string, command: string, expression: string, frequency_key: string, description_key: string, overlap_minutes: int|null, status: string, last_started_at: Carbon|null, last_finished_at: Carbon|null, last_exit_code: int|null, last_output: string|null, next_due_at: Carbon}>
     */
    public function tasks(): Collection
    {
        $definitions = collect($this->definitions());
        $statuses = ScheduledTaskStatus::query()
            ->whereIn('task_key', $definitions->pluck('key'))
            ->get()
            ->keyBy('task_key');

        return $definitions
            ->map(function (array $definition) use ($statuses): array {
                /** @var ScheduledTaskStatus|null $status */
                $status = $statuses->get($definition['key']);

                return [
                    ...$definition,
                    'status' => $status?->status ?? ScheduledTaskStatus::StatusNeverRun,
                    'last_started_at' => $status?->last_started_at,
                    'last_finished_at' => $status?->last_finished_at,
                    'last_exit_code' => $status?->last_exit_code,
                    'last_output' => $status?->last_output,
                    'next_due_at' => $this->nextDueAt($definition['expression']),
                ];
            })
            ->values();
    }

    /**
     * @param  array{key: string, command: string, expression: string}  $definition
     */
    public function markStarted(array $definition): ScheduledTaskStatus
    {
        return ScheduledTaskStatus::query()->updateOrCreate(
            ['task_key' => $definition['key']],
            [
                'command' => $definition['command'],
                'expression' => $definition['expression'],
                'status' => ScheduledTaskStatus::StatusRunning,
                'last_started_at' => now(),
                'last_finished_at' => null,
                'last_exit_code' => null,
                'last_output' => null,
            ],
        );
    }

    /**
     * @param  array{key: string, command: string, expression: string}  $definition
     */
    public function markFinished(array $definition, string $status, int $exitCode, Stringable|string|null $output = null): ScheduledTaskStatus
    {
        $record = ScheduledTaskStatus::query()->firstOrNew(['task_key' => $definition['key']]);

        $record->fill([
            'command' => $definition['command'],
            'expression' => $definition['expression'],
            'status' => $status,
            'last_started_at' => $record->last_started_at ?? now(),
            'last_finished_at' => now(),
            'last_exit_code' => $exitCode,
            'last_output' => $this->normalizeOutput($output),
        ]);

        $record->save();

        return $record;
    }

    private function applyOverlapProtection(Event $event, ?int $overlapMinutes): void
    {
        if ($overlapMinutes === null) {
            $event->withoutOverlapping();

            return;
        }

        $event->withoutOverlapping($overlapMinutes);
    }

    private function nextDueAt(string $expression): Carbon
    {
        return Carbon::instance(
            (new CronExpression($expression))->getNextRunDate(now(config('app.timezone'))->toDateTime()),
        )->setTimezone(config('app.timezone'));
    }

    private function normalizeOutput(Stringable|string|null $output): ?string
    {
        $value = trim((string) $output);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\e\[[\d;]*m/', '', $value) ?? $value;

        return Str::limit($value, 4000);
    }
}
